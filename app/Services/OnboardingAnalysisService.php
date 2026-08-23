<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignAnalysis;
use App\Models\CampaignWalletInsight;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Measures whether a campaign actually onboarded anyone, using the public Koios query
 * layer.
 *
 * Three questions, in order of how much they matter:
 *
 *   NEW         Did the wallet exist before the claim? A wallet whose first-ever
 *               transaction is the campaign claim was put on chain BY the campaign.
 *   ACTIVATED   Did it later initiate a transaction of its own? The wallet has to appear
 *               as an INPUT. A wallet that merely receives a second payout has done
 *               nothing, and counting that would let an operator inflate the number by
 *               sending more tokens.
 *   DELEGATED   Is it registered and delegated to a stake pool? Reported for every
 *               campaign, including those that never asked for it, because the gap
 *               between a campaign that guides people into staking and one that does not
 *               is the entire argument that campaigns drive behaviour.
 *
 * The equivalent standalone script has to reconstruct who claimed by pulling every claim
 * transaction and treating non-sender outputs as recipients. Here the platform already
 * recorded the claimant address, stake key and transaction hash at claim time, so the
 * analysis starts from known claimants and that whole pass disappears.
 */
class OnboardingAnalysisService
{
    /** Koios accepts batches of hashes; keep requests well inside its limits. */
    private const TX_BATCH = 20;

    private const ACCOUNT_BATCH = 50;

    /** Pacing between Koios calls, matching what the public tier tolerates. */
    private const PACE_MICROSECONDS = 250_000;

    public function __construct(private bool $paced = true) {}

    /**
     * Analyze every confirmed claimant wallet in the campaign and persist the results.
     */
    public function analyze(Campaign $campaign): CampaignAnalysis
    {
        $analysis = CampaignAnalysis::firstOrNew(['campaign_id' => $campaign->id]);
        $analysis->fill([
            'status' => CampaignAnalysis::STATUS_RUNNING,
            'started_at' => now(),
            'completed_at' => null,
            'error' => null,
        ])->save();

        try {
            $claimants = $this->claimants($campaign);

            $analysis->update(['wallets_total' => $claimants->count(), 'wallets_analyzed' => 0]);

            if ($claimants->isEmpty()) {
                return $this->complete($campaign, $analysis, collect());
            }

            $insights = $this->classify($campaign, $claimants);

            return $this->complete($campaign, $analysis, $insights);
        } catch (\Throwable $e) {
            Log::error('Onboarding analysis failed.', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            $analysis->update([
                'status' => CampaignAnalysis::STATUS_FAILED,
                'completed_at' => now(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Distinct claimant wallets, keyed by stake key, earliest claim winning.
     *
     * Only claims that reached the chain are eligible: an unconfirmed claim has no
     * transaction to classify against. Claimants presenting an enterprise address (no
     * stake key) are excluded, because Koios keys wallet history on the stake key and
     * there is nothing to look up.
     */
    private function claimants(Campaign $campaign): Collection
    {
        return $campaign->claims()
            ->whereNotNull('transaction_hash')
            ->where('stake_key', '!=', '')
            ->whereNotNull('stake_key')
            ->orderBy('claims.created_at')
            ->get(['claims.id', 'claims.address', 'claims.stake_key', 'claims.transaction_hash', 'claims.created_at'])
            ->filter(fn ($claim) => str_starts_with((string) $claim->stake_key, 'stake'))
            ->unique('stake_key')
            ->values();
    }

    /**
     * @param  Collection  $claimants  one claim row per distinct wallet
     */
    private function classify(Campaign $campaign, Collection $claimants): Collection
    {
        $network = $campaign->network ?? 'mainnet';

        // Claim block height is the before/after boundary for everything below. Height is
        // used rather than timestamp because it is strictly monotonic.
        $claimTxs = $this->txInfo($claimants->pluck('transaction_hash')->all(), $network);

        $wallets = [];
        foreach ($claimants as $claim) {
            $tx = $claimTxs[strtolower((string) $claim->transaction_hash)] ?? null;
            $wallets[$claim->stake_key] = [
                'address' => $claim->address,
                'tx_hash' => $claim->transaction_hash,
                'height' => (int) ($tx['block_height'] ?? 0),
                'claimed_at' => isset($tx['tx_timestamp'])
                    ? Carbon::createFromTimestamp((int) $tx['tx_timestamp'])
                    : $claim->created_at,
            ];
        }

        // Per-wallet history: how much came before the claim, and what came after.
        $history = [];
        foreach ($wallets as $stake => $info) {
            $txs = $this->accountTxs($stake, $network);

            $before = 0;
            $after = [];
            foreach ($txs as $tx) {
                $height = (int) ($tx['block_height'] ?? 0);
                if ($info['height'] > 0 && $height < $info['height']) {
                    $before++;
                } elseif ($info['height'] > 0 && $height > $info['height']) {
                    $after[] = $tx['tx_hash'];
                }
            }

            $history[$stake] = ['before' => $before, 'after' => $after];
        }

        // Who INITIATED each post-claim transaction, and which of them paid a script
        // address. Receiving is not activation; spending is.
        $postHashes = collect($history)->flatMap(fn ($h) => $h['after'])->unique()->values()->all();
        $postTxs = $this->txInfo($postHashes, $network);

        $initiators = [];
        $scriptPayments = [];
        foreach ($postTxs as $hash => $tx) {
            $inputs = [];
            foreach ($tx['inputs'] ?? [] as $in) {
                if ($stake = $in['stake_addr'] ?? null) {
                    $inputs[$stake] = true;
                }
            }
            $initiators[$hash] = $inputs;

            $scripts = [];
            foreach ($tx['outputs'] ?? [] as $out) {
                $addr = $out['payment_addr']['bech32'] ?? '';
                // Script addresses on mainnet and the test networks. A payment to one is
                // an interaction with a contract rather than a plain transfer.
                if (preg_match('/^addr(_test)?1[wx]/', $addr)) {
                    $scripts[$addr] = true;
                }
            }
            $scriptPayments[$hash] = $scripts;
        }

        $delegation = $this->accountInfo(array_keys($wallets), $network);

        $insights = collect();
        foreach ($wallets as $stake => $info) {
            $selfInitiated = 0;
            $scriptHits = 0;
            foreach ($history[$stake]['after'] ?? [] as $hash) {
                if (isset($initiators[$hash][$stake])) {
                    $selfInitiated++;
                    $scriptHits += count($scriptPayments[$hash] ?? []) > 0 ? 1 : 0;
                }
            }

            // is_operator is deliberately not touched here. It is set by the operator to
            // exclude their own test claims, and a re-analysis must not clear it.
            $insight = CampaignWalletInsight::updateOrCreate(
                ['campaign_id' => $campaign->id, 'stake_key' => $stake],
                [
                    'address' => $info['address'],
                    'claim_tx_hash' => $info['tx_hash'],
                    'claim_block_height' => $info['height'] ?: null,
                    'claimed_at' => $info['claimed_at'],
                    'prior_tx_count' => $history[$stake]['before'] ?? 0,
                    'is_new' => ($history[$stake]['before'] ?? 0) === 0,
                    'activated' => $selfInitiated > 0,
                    'self_initiated_count' => $selfInitiated,
                    'post_claim_tx_count' => count($history[$stake]['after'] ?? []),
                    'delegated' => $delegation[$stake]['delegated'] ?? false,
                    'pool_id' => $delegation[$stake]['pool'] ?? null,
                    'script_interactions' => $scriptHits,
                    'analyzed_at' => now(),
                ]
            );

            $insights->push($insight);
        }

        return $insights;
    }

    private function complete(Campaign $campaign, CampaignAnalysis $analysis, Collection $insights): CampaignAnalysis
    {
        $analysis->update([
            'status' => CampaignAnalysis::STATUS_COMPLETE,
            'completed_at' => now(),
            'wallets_analyzed' => $insights->count(),
            'summary' => $this->summarize($campaign, $insights),
        ]);

        Log::info('Onboarding analysis complete.', [
            'campaign_id' => $campaign->id,
            'wallets' => $insights->count(),
        ]);

        return $analysis->refresh();
    }

    /**
     * Headline metrics. Percentages are always of the genuine (non-operator) population,
     * and the observation window travels with them — an activation rate without the time
     * it was measured over says nothing.
     */
    public function summarize(Campaign $campaign, Collection $insights): array
    {
        $genuine = $insights->where('is_operator', false);
        $new = $genuine->where('is_new', true);
        $established = $genuine->where('is_new', false);

        $pct = static fn (int $count, int $of): float => $of > 0 ? round(100 * $count / $of, 1) : 0.0;

        $claimedAt = $genuine->pluck('claimed_at')->filter();
        $days = $claimedAt->map(fn ($at) => $at->diffInDays(now()));

        return [
            'network' => $campaign->network ?? 'mainnet',
            'claimants_total' => $insights->count(),
            'operator_wallets' => $insights->where('is_operator', true)->count(),
            'genuine_wallets' => $genuine->count(),

            'new_wallets' => $new->count(),
            'new_pct' => $pct($new->count(), $genuine->count()),
            'established_wallets' => $established->count(),
            'established_pct' => $pct($established->count(), $genuine->count()),

            'new_activated' => $new->where('activated', true)->count(),
            'new_activated_pct' => $pct($new->where('activated', true)->count(), $new->count()),
            'established_activated' => $established->where('activated', true)->count(),
            'established_activated_pct' => $pct($established->where('activated', true)->count(), $established->count()),

            'delegated' => $genuine->where('delegated', true)->count(),
            'delegated_pct' => $pct($genuine->where('delegated', true)->count(), $genuine->count()),
            'new_delegated' => $new->where('delegated', true)->count(),
            'new_delegated_pct' => $pct($new->where('delegated', true)->count(), $new->count()),

            'script_interactors' => $genuine->where('script_interactions', '>', 0)->count(),
            'script_interactors_pct' => $pct($genuine->where('script_interactions', '>', 0)->count(), $genuine->count()),

            'observation_days_avg' => $days->isNotEmpty() ? round($days->avg(), 1) : null,
            'observation_days_max' => $days->isNotEmpty() ? $days->max() : null,
            'first_claim_at' => $claimedAt->min()?->toIso8601String(),
            'last_claim_at' => $claimedAt->max()?->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, string>  $hashes
     * @return array<string, array> keyed by lower-case tx hash
     */
    private function txInfo(array $hashes, string $network): array
    {
        $hashes = array_values(array_unique(array_filter(array_map(
            static fn ($h) => strtolower(trim((string) $h)),
            $hashes
        ))));

        $out = [];
        foreach (array_chunk($hashes, self::TX_BATCH) as $batch) {
            $rows = $this->koios($network, 'tx_info', ['_tx_hashes' => $batch, '_inputs' => true]);
            foreach ($rows as $tx) {
                if ($hash = $tx['tx_hash'] ?? null) {
                    $out[strtolower($hash)] = $tx;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<int, array>
     */
    private function accountTxs(string $stakeKey, string $network): array
    {
        $response = Http::koios($network)
            ->retry(3, 750, throw: false)
            ->get('account_txs', ['_stake_address' => $stakeKey]);

        $this->pace();

        if ($response->failed()) {
            Log::warning('Koios account_txs failed.', [
                'stake_key' => $stakeKey,
                'status' => $response->status(),
            ]);

            return [];
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<int, string>  $stakeKeys
     * @return array<string, array{delegated: bool, pool: ?string}>
     */
    private function accountInfo(array $stakeKeys, string $network): array
    {
        $out = [];
        foreach (array_chunk(array_values($stakeKeys), self::ACCOUNT_BATCH) as $batch) {
            $rows = $this->koios($network, 'account_info', ['_stake_addresses' => $batch]);
            foreach ($rows as $account) {
                if ($stake = $account['stake_address'] ?? null) {
                    $out[$stake] = [
                        'delegated' => ! empty($account['delegated_pool']),
                        'pool' => $account['delegated_pool'] ?? null,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @return array<int, array>
     */
    private function koios(string $network, string $endpoint, array $body): array
    {
        $response = Http::koios($network)
            ->retry(3, 750, throw: false)
            ->post($endpoint, $body);

        $this->pace();

        if ($response->failed()) {
            Log::warning('Koios request failed.', [
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            return [];
        }

        return $response->json() ?? [];
    }

    private function pace(): void
    {
        if ($this->paced) {
            usleep(self::PACE_MICROSECONDS);
        }
    }
}

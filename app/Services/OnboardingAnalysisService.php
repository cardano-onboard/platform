<?php

namespace App\Services;

use App\Contracts\ReportsTaskProgress;
use App\Models\Campaign;
use App\Models\CampaignAnalysis;
use App\Models\CampaignWalletActivity;
use App\Models\CampaignWalletInsight;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Measures whether a campaign actually onboarded anyone, using the public Koios query
 * layer.
 *
 * Four questions, in order of how much they matter:
 *
 *   NEW         Did the wallet exist before the claim? A wallet whose first-ever
 *               transaction is the campaign claim was put on chain BY the campaign.
 *   ACTIVE      Did it later do something of its own? The wallet has to appear as an
 *               INPUT, because a wallet that merely receives a second payout has done
 *               nothing and counting that would let an operator inflate the number by
 *               sending more tokens. Submitting its own stake certificate does not count
 *               either; see DELEGATED.
 *   DELEGATED   Did it register a stake key and delegate, and when? Reported separately
 *               from activity and never folded into it. A delegation transaction carries
 *               the wallet as an input, because the wallet pays the deposit and the fee,
 *               so a wallet whose only act was delegating looks exactly like a wallet that
 *               spent if inputs are all you look at. Telling them apart means reading the
 *               certificates, which is why the post-claim query asks for them.
 *   WHEN        How long after the claim each of those happened. A bare total cannot be
 *               cut into a thirty-day figure afterwards, so every post-claim transaction
 *               is recorded with the time the chain reported for it.
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

    /** The follow-up windows the panel reports, in days. */
    public const WINDOW_DAYS = [30, 60, 90];

    /**
     * Certificate types that are a wallet administering its own stake, not transacting.
     *
     * Koios returns these under `certificates` when tx_info is asked with `_certs`, each
     * as {index, type, info}, and only these four carry a top-level `stake_address` in
     * `info`, which is what says whose certificate it is. `pool_delegation` is the name the
     * live service returns; the published description of the endpoint calls the same thing
     * `delegation`, so both are accepted rather than trusting one of the two.
     *
     * The MIR certificates also carry a stake_address but are the ledger paying an account
     * rather than the account acting, and a wallet is never the initiator of one.
     */
    private const STAKE_CERTIFICATES = [
        'stake_registration',
        'stake_deregistration',
        'pool_delegation',
        'delegation',
        'vote_delegation',
    ];

    /** The certificate types that mean the wallet delegated to a stake pool. */
    private const DELEGATION_CERTIFICATES = ['pool_delegation', 'delegation'];

    /**
     * What this run could not read, by stake key.
     *
     * A provider that answers with a 500 or with nothing is not a measurement. The wallets
     * a failed read touched are left unknown rather than written as zeros, and the run says
     * so instead of calling itself a complete reading of the chain. Reset at the start of
     * every run, because it describes that run and nothing else.
     *
     * @var array{history: array<string, true>, windows: array<string, true>, delegation: array<string, true>}
     */
    private array $unread = ['history' => [], 'windows' => [], 'delegation' => []];

    public function __construct(private bool $paced = true) {}

    /**
     * Analyze every confirmed claimant wallet in the campaign and persist the results.
     *
     * The run takes minutes and spends nearly all of them inside one loop, so it names the
     * phase it is in as it goes. $progress is the campaign task row when a queued job is
     * running this, and null when the console command is, because there the command prints
     * its own account of what is happening.
     */
    public function analyze(Campaign $campaign, ?ReportsTaskProgress $progress = null): CampaignAnalysis
    {
        $this->unread = ['history' => [], 'windows' => [], 'delegation' => []];

        $analysis = CampaignAnalysis::firstOrNew(['campaign_id' => $campaign->id]);
        $analysis->fill([
            'status' => CampaignAnalysis::STATUS_RUNNING,
            // A run that is already in flight started when its confirmation pass did, not
            // when the chain reading got its turn.
            'started_at' => $analysis->isRunning() ? ($analysis->started_at ?? now()) : now(),
            'completed_at' => null,
            'error' => null,
        ])->save();

        try {
            $progress?->stage('Finding claimant wallets');

            $claimants = $this->claimants($campaign);

            $analysis->update(['wallets_total' => $claimants->count(), 'wallets_analyzed' => 0]);

            if ($claimants->isEmpty()) {
                return $this->complete($campaign, $analysis, collect());
            }

            $insights = $this->classify($campaign, $claimants, $progress);

            $progress?->stage('Recording results');

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
    private function classify(Campaign $campaign, Collection $claimants, ?ReportsTaskProgress $progress = null): Collection
    {
        $network = $campaign->network ?? 'mainnet';

        $progress?->stage('Reading claim transactions');

        // Claim block height is the before/after boundary for everything below. Height is
        // used rather than timestamp because it is strictly monotonic.
        $claimRead = $this->txInfo($claimants->pluck('transaction_hash')->all(), $network);
        $claimTxs = $claimRead['rows'];

        $wallets = [];
        foreach ($claimants as $claim) {
            $tx = $claimTxs[strtolower((string) $claim->transaction_hash)] ?? null;
            $height = (int) ($tx['block_height'] ?? 0);

            // Without the height of the claim itself there is no before and no after, so
            // nothing about this wallet can be placed on either side of it. A read that
            // failed or came back without the transaction is that, and it is not the same
            // fact as a wallet that was read and had never transacted.
            if ($height <= 0) {
                $this->unread['history'][$claim->stake_key] = true;
            }

            $wallets[$claim->stake_key] = [
                'address' => $claim->address,
                'tx_hash' => $claim->transaction_hash,
                'height' => $height,
                'claimed_at' => isset($tx['tx_timestamp'])
                    ? Carbon::createFromTimestamp((int) $tx['tx_timestamp'])
                    : $claim->created_at,
            ];
        }

        // Per-wallet history: how much came before the claim, and what came after.
        //
        // This is where the minutes go: one paced Koios call per wallet, and the only part
        // of the run with a number anybody can count. Everything else is a handful of
        // batched calls, so this is the one phase that reports a denominator rather than
        // leaving the panel to draw an indeterminate bar.
        //
        // The block time of each transaction is kept, not only its hash. account_txs
        // returns it beside the height, so the times the windows are measured against cost
        // no extra requests to collect.
        $progress?->stage('Reading wallet history');
        $progress?->progress(0, count($wallets));

        $read = 0;
        $history = [];
        foreach ($wallets as $stake => $info) {
            $txs = $this->accountTxs($stake, $network);
            $progress?->progress(++$read, count($wallets));

            // A wallet whose history could not be read has no history of nothing. It is
            // recorded as unread and keeps whatever an earlier run knew about it.
            if ($txs === null) {
                $this->unread['history'][$stake] = true;
                $history[$stake] = ['before' => 0, 'after' => []];

                continue;
            }

            $before = 0;
            $after = [];
            foreach ($txs as $tx) {
                $height = (int) ($tx['block_height'] ?? 0);
                if ($info['height'] > 0 && $height < $info['height']) {
                    $before++;
                } elseif ($info['height'] > 0 && $height > $info['height']) {
                    $after[strtolower((string) ($tx['tx_hash'] ?? ''))] = [
                        'height' => $height,
                        // Null rather than a substitute when the provider returned no
                        // time. A transaction whose time is unknown belongs to no window,
                        // and must not be reported as one that happened on the claim day.
                        'time' => isset($tx['block_time']) ? (int) $tx['block_time'] : null,
                    ];
                }
            }

            unset($after['']);

            $history[$stake] = ['before' => $before, 'after' => $after];
        }

        // Who INITIATED each post-claim transaction, which of them paid a script address,
        // and which of them carried a certificate belonging to the wallet in question.
        // Receiving is not activity, spending is, and delegating is neither.
        $progress?->stage('Reading follow-up transactions');

        $postHashes = collect($history)
            ->flatMap(fn ($h) => array_keys($h['after']))
            ->unique()
            ->values()
            ->all();

        $postRead = $this->txInfo($postHashes, $network, certificates: true);
        $postTxs = $postRead['rows'];

        // A wallet one of whose follow-up transactions could not be read cannot be told to
        // have transacted or to have only delegated: that answer is in the detail of the
        // transaction that is missing. Its windows stay unknown.
        foreach ($history as $stake => $seen) {
            if (array_intersect_key($seen['after'], $postRead['unread']) !== []) {
                $this->unread['windows'][$stake] = true;
            }
        }

        $initiators = [];
        $scriptPayments = [];
        $outputStakes = [];
        $certificates = [];
        // Every row here carries its inputs, outputs and certificates as lists: txInfo()
        // keeps back any row that answered the certificate-bearing read without them, and
        // puts its hash in `unread` instead.
        foreach ($postTxs as $hash => $tx) {
            $inputs = [];
            foreach ($tx['inputs'] as $in) {
                if ($stake = $in['stake_addr'] ?? null) {
                    $inputs[$stake] = true;
                }
            }
            $initiators[$hash] = $inputs;

            $scripts = [];
            $paidStakes = [];
            foreach ($tx['outputs'] as $out) {
                $addr = $out['payment_addr']['bech32'] ?? '';
                // Script addresses on mainnet and the test networks, using the bech32
                // prefixes CIP-5 registers for addresses (`addr` and `addr_test`). A
                // payment to one is an interaction with a contract, not a plain transfer.
                if (preg_match('/^addr(_test)?1[wx]/', $addr)) {
                    $scripts[$addr] = true;
                }

                if ($stake = $out['stake_addr'] ?? null) {
                    $paidStakes[$stake] = true;
                }
            }
            $scriptPayments[$hash] = $scripts;
            $outputStakes[$hash] = $paidStakes;

            // Certificates, grouped by whose they are. A transaction can carry a
            // certificate for a stake key that put no input into it, so the subject is
            // read off the certificate rather than assumed to be the sender.
            $certs = [];
            foreach ($tx['certificates'] as $cert) {
                $subject = $cert['info']['stake_address'] ?? null;
                $type = $cert['type'] ?? null;

                if ($subject && $type) {
                    $certs[$subject][] = (string) $type;
                }
            }
            $certificates[$hash] = $certs;
        }

        $progress?->stage('Reading stake delegations');

        $accountRead = $this->accountInfo(array_keys($wallets), $network);
        $delegation = $accountRead['rows'];

        // An account the provider did not answer for at all. Absence from a successful
        // answer is not the same thing: Koios returns no row for a stake key that was
        // never registered, and that wallet is genuinely not delegating.
        foreach ($accountRead['unread'] as $stake => $_) {
            $this->unread['delegation'][$stake] = true;
        }

        $progress?->stage('Recording follow-up activity');

        // One moment for the whole run. Every offset below is measured against it and it
        // is stored beside them, so these rows answer the same windows next month as they
        // do today. Measuring against the reader's clock instead would let a result change
        // without anything having been observed again.
        $observedAt = now();

        $rows = [];
        $rollups = [];
        foreach ($wallets as $stake => $info) {
            $claimedAt = $info['claimed_at'] instanceof CarbonInterface ? $info['claimed_at'] : null;

            $rollup = [
                'self_initiated' => 0,
                'activity' => 0,
                'delegations' => 0,
                'scripts' => 0,
                'first_activity' => null,
                'first_delegation' => null,
            ];

            foreach ($history[$stake]['after'] ?? [] as $hash => $seen) {
                $selfInitiated = isset($initiators[$hash][$stake]);
                $ownCerts = $certificates[$hash][$stake] ?? [];
                $stakeCerts = array_values(array_intersect($ownCerts, self::STAKE_CERTIFICATES));

                // Did anything leave this wallet. A script payment counts, and so does an
                // output whose stake key is somebody else's. An output carrying no stake
                // key at all is not counted: that is unknown rather than a payment, and
                // reading it as one would turn a delegation whose change the provider
                // could not resolve into activity the wallet never performed.
                $paidOut = count($scriptPayments[$hash] ?? []) > 0
                    || count(array_diff(array_keys($outputStakes[$hash] ?? []), [$stake])) > 0;

                // A transaction that carried this wallet's own stake certificate and moved
                // nothing out of it is the wallet administering its stake, not using it.
                $isActivity = $selfInitiated && ($stakeCerts === [] || $paidOut);
                $isDelegation = $selfInitiated
                    && array_intersect($ownCerts, self::DELEGATION_CERTIFICATES) !== [];
                $scriptHit = $selfInitiated && count($scriptPayments[$hash] ?? []) > 0;

                $occurredAt = $seen['time'] !== null ? Carbon::createFromTimestamp($seen['time']) : null;
                // Signed and unclamped. The claim time falls back to the platform's own
                // record when the chain gave none, and a transaction that is later by
                // block height can then read as earlier by clock.
                $offset = ($occurredAt && $claimedAt)
                    ? $occurredAt->getTimestamp() - $claimedAt->getTimestamp()
                    : null;

                if ($selfInitiated) {
                    $rollup['self_initiated']++;
                }

                if ($isActivity) {
                    $rollup['activity']++;
                    $rollup['first_activity'] = $this->earliest($rollup['first_activity'], $offset);
                }

                if ($isDelegation) {
                    $rollup['delegations']++;
                    $rollup['first_delegation'] = $this->earliest($rollup['first_delegation'], $offset);
                }

                if ($scriptHit) {
                    $rollup['scripts']++;
                }

                $rows[] = [
                    'campaign_id' => $campaign->id,
                    'stake_key' => $stake,
                    'tx_hash' => $hash,
                    'block_height' => $seen['height'] ?: null,
                    'occurred_at' => $occurredAt,
                    'seconds_after_claim' => $offset,
                    'self_initiated' => $selfInitiated,
                    // Recorded as the provider named them, deduplicated but otherwise
                    // untranslated, so a type this code does not yet know about is still
                    // on the record when somebody comes looking for it.
                    'certificate_types' => array_values(array_unique($ownCerts)),
                    'is_delegation' => $isDelegation,
                    'is_activity' => $isActivity,
                    'script_interaction' => $scriptHit,
                    'observed_at' => $observedAt,
                    'created_at' => $observedAt,
                    'updated_at' => $observedAt,
                ];
            }

            $rollups[$stake] = $rollup + [
                'observed_seconds' => $claimedAt
                    ? $observedAt->getTimestamp() - $claimedAt->getTimestamp()
                    : null,
            ];
        }

        // Only the wallets this run read through to the end. A wallet whose follow-up
        // transactions could not be read keeps the rows an earlier run recorded for it,
        // because deleting them would turn a provider outage into a wallet that stopped
        // doing anything.
        $this->replaceActivity($campaign, $rows, array_values(array_filter(
            array_keys($wallets),
            fn (string $stake) => ! $this->windowsUnread($stake)
        )));

        $progress?->stage('Recording results');

        $insights = collect();
        foreach ($wallets as $stake => $info) {
            $rollup = $rollups[$stake];

            $measured = [];

            // Each group of columns is written only by a run that read what they are
            // measured from. A failed read leaves them as they were, so a wallet measured
            // in July still reads as July measured it rather than being overwritten with
            // an outage, and a wallet never measured at all stays null, which every
            // surface shows as unknown.
            if (! isset($this->unread['history'][$stake])) {
                $measured += [
                    'claim_block_height' => $info['height'] ?: null,
                    'prior_tx_count' => $history[$stake]['before'] ?? 0,
                    'is_new' => ($history[$stake]['before'] ?? 0) === 0,
                ];
            }

            if (! $this->windowsUnread($stake)) {
                $measured += [
                    'self_initiated_count' => $rollup['self_initiated'],
                    'activity_count' => $rollup['activity'],
                    'delegation_events' => $rollup['delegations'],
                    'first_activity_seconds' => $rollup['first_activity'],
                    'first_delegation_seconds' => $rollup['first_delegation'],
                    'observed_seconds' => $rollup['observed_seconds'],
                    'post_claim_tx_count' => count($history[$stake]['after'] ?? []),
                    'script_interactions' => $rollup['scripts'],
                    'windows_observed_at' => $observedAt,
                ];
            }

            if (! isset($this->unread['delegation'][$stake])) {
                $measured += [
                    // Current state, read off the account rather than out of the claim
                    // window: a wallet that delegated years before this campaign existed
                    // is delegated, and did not delegate because of it. The two are told
                    // apart by first_delegation_seconds, which is null for that wallet.
                    'delegated' => $delegation[$stake]['delegated'] ?? false,
                    'pool_id' => $delegation[$stake]['pool'] ?? null,
                ];
            }

            // is_operator is deliberately not touched here. It is set by the operator to
            // exclude their own test claims, and a re-analysis must not clear it.
            $insight = CampaignWalletInsight::updateOrCreate(
                ['campaign_id' => $campaign->id, 'stake_key' => $stake],
                $measured + [
                    'address' => $info['address'],
                    'claim_tx_hash' => $info['tx_hash'],
                    'claimed_at' => $info['claimed_at'],
                    'analyzed_at' => $observedAt,
                ]
            );

            $insights->push($insight);
        }

        return $insights;
    }

    /**
     * Could this run answer the window questions for this wallet.
     *
     * The claim's own height is part of the answer: without it nothing can be placed
     * before or after the claim, so a wallet whose history went unread has unknown windows
     * as well as an unknown classification.
     */
    private function windowsUnread(string $stake): bool
    {
        return isset($this->unread['history'][$stake]) || isset($this->unread['windows'][$stake]);
    }

    /** The earlier of two offsets, either of which may be unknown. */
    private function earliest(?int $current, ?int $candidate): ?int
    {
        if ($candidate === null) {
            return $current;
        }

        return $current === null ? $candidate : min($current, $candidate);
    }

    /**
     * Replace this campaign's recorded follow-up transactions with what this run saw.
     *
     * A re-analysis rewrites rather than accumulates: the same transaction read twice is
     * the same fact, and a second row for it would double every count taken from them. The
     * delete and the inserts go in one transaction, so a campaign is never left having lost
     * its old rows without gaining the new ones.
     *
     * Only the wallets named are rewritten. A wallet this run could not read is left with
     * what the last run that could read it recorded, because an outage is not a wallet that
     * stopped transacting.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, string>  $stakeKeys  the wallets this run read through to the end
     */
    private function replaceActivity(Campaign $campaign, array $rows, array $stakeKeys): void
    {
        $keep = array_flip($stakeKeys);
        $rows = array_values(array_filter($rows, static fn (array $row) => isset($keep[$row['stake_key']])));

        DB::transaction(function () use ($campaign, $rows, $stakeKeys) {
            foreach (array_chunk($stakeKeys, 500) as $batch) {
                CampaignWalletActivity::where('campaign_id', $campaign->id)
                    ->whereIn('stake_key', $batch)
                    ->delete();
            }

            foreach (array_chunk($rows, 250) as $chunk) {
                CampaignWalletActivity::insert(array_map(static function (array $row) {
                    // insert() is the query builder, which does not run model casts, so
                    // the JSON column is encoded here rather than handed an array.
                    $row['certificate_types'] = json_encode($row['certificate_types']);

                    return $row;
                }, $chunk));
            }
        });
    }

    private function complete(Campaign $campaign, CampaignAnalysis $analysis, Collection $insights): CampaignAnalysis
    {
        // What this run could not read, counted over the wallets it was asked to read. A
        // run that measured everything says complete; one that a provider failure left
        // holes in says so, because a hole is not a zero and the panel has to be able to
        // tell the operator which of the two they are looking at.
        $unread = count(array_unique(array_merge(
            array_keys($this->unread['history']),
            array_keys($this->unread['windows']),
            array_keys($this->unread['delegation']),
        )));

        $analysis->update([
            'status' => $unread > 0 ? CampaignAnalysis::STATUS_PARTIAL : CampaignAnalysis::STATUS_COMPLETE,
            'unread_wallets' => $unread,
            'completed_at' => now(),
            // Stamped by every run that could tell delegation from activity, which is what
            // lets the panel recognise a result stored before it could and say its windows
            // are unknown instead of printing a zero for them.
            'windowed_at' => now(),
            'wallets_analyzed' => $insights->count(),
            // The stored summary always means the whole campaign. A date range is a
            // question asked of the same per-wallet rows afterwards and is never written
            // here, so what this row reports never depends on who last looked at it.
            'summary' => $this->summarize($campaign, $insights),
        ]);

        Log::info('Onboarding analysis finished.', [
            'campaign_id' => $campaign->id,
            'wallets' => $insights->count(),
            'unread_wallets' => $unread,
        ]);

        return $analysis->refresh();
    }

    /**
     * The same metrics over a date range, recomputed from the rows already stored.
     *
     * A campaign that exists to serve one event collects claims the event did not
     * produce: spare cards handed out afterwards, a social post weeks later. Both are
     * real claims and both answer a different question, so the range restricts which
     * stored rows feed the summary rather than reclassifying anything.
     *
     * Nothing here touches the chain and nothing is written. A campaign already analysed
     * can be re-scoped as often as anyone likes without spending its rate limit again,
     * and the stored summary goes on meaning the whole campaign.
     *
     * Rows with no claim timestamp cannot be placed in any range and are therefore absent
     * from every scoped result. The panel counts them separately rather than letting them
     * disappear.
     */
    public function summarizeWindow(Campaign $campaign, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $insights = $campaign->walletInsights()
            ->whereNotNull('claimed_at')
            ->when($from, fn ($query) => $query->where('claimed_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('claimed_at', '<=', $to))
            ->get();

        return $this->summarize($campaign, $insights, $from, $to);
    }

    /**
     * Headline metrics. Percentages are always of the genuine (non-operator) population,
     * and the observation window travels with them: an activation rate without the time it
     * was measured over says nothing.
     *
     * Coverage travels with them for the same reason. The analysis can only see claims
     * that carry a transaction hash, and a percentage of those reads exactly like a
     * percentage of the campaign. Saying how many claims the result covered out of how
     * many exist is the only way a reader coming to the numbers later can tell the
     * difference.
     *
     * Activity, delegation and the follow-up windows are reported only over the rows a run
     * has read since the two were told apart. A row from an earlier run holds no answer to
     * those questions, and `windows_unknown` says how many such rows there are rather than
     * letting them sit in the denominator as wallets that did nothing.
     *
     * @param  ?CarbonInterface  $from  inclusive start of the range these rows were drawn
     *                                  from, so coverage is counted over the same range
     * @param  ?CarbonInterface  $to  inclusive end of that range
     */
    public function summarize(Campaign $campaign, Collection $insights, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $genuine = $insights->where('is_operator', false);

        // Strictly, because a wallet whose chain read failed has is_new null and a loose
        // comparison would file it under established. A wallet nobody could read is
        // neither, and is counted as unclassified below.
        $classified = $genuine->filter(fn ($w) => $w->is_new !== null);
        $new = $genuine->filter(fn ($w) => $w->is_new === true);
        $established = $genuine->filter(fn ($w) => $w->is_new === false);

        $pct = static fn (int $count, int $of): float => $of > 0 ? round(100 * $count / $of, 1) : 0.0;

        // Only the rows a windowed run has read can answer whether a wallet did anything
        // of its own. The rest are unknown, and are kept out of both halves of the
        // fraction rather than counted as zeros.
        $windowed = $genuine->filter(fn ($w) => $w->windows_observed_at !== null);
        $windowedNew = $windowed->filter(fn ($w) => $w->is_new === true);
        $windowedEstablished = $windowed->filter(fn ($w) => $w->is_new === false);

        // Current delegation state is a separate read from the history, so it fails
        // separately. Its percentages are of the wallets that read answered for.
        $delegationKnown = $genuine->filter(fn ($w) => $w->delegated !== null);
        $newDelegationKnown = $new->filter(fn ($w) => $w->delegated !== null);

        $active = static fn (Collection $rows) => $rows->filter(fn ($w) => (int) $w->activity_count > 0);
        $delegatedAfter = static fn (Collection $rows) => $rows->filter(fn ($w) => $w->first_delegation_seconds !== null);

        $claimedAt = $genuine->pluck('claimed_at')->filter();
        $days = $this->observedDays($genuine);

        $coverage = $this->coverage($campaign, $from, $to);

        $observedAt = $windowed->pluck('windows_observed_at')->filter()->max();

        return [
            'network' => $campaign->network ?? 'mainnet',
            'window_from' => $from?->toIso8601String(),
            'window_to' => $to?->toIso8601String(),
            'claimants_total' => $insights->count(),
            'operator_wallets' => $insights->where('is_operator', true)->count(),
            'genuine_wallets' => $genuine->count(),

            // Wallets a run read the history of, and wallets it could not. A percentage
            // over a population that includes wallets nobody could read describes neither
            // of them, so the denominator is the read population and the rest is stated.
            'classified_wallets' => $classified->count(),
            'unclassified_wallets' => $genuine->count() - $classified->count(),

            'new_wallets' => $new->count(),
            'new_pct' => $pct($new->count(), $classified->count()),
            'established_wallets' => $established->count(),
            'established_pct' => $pct($established->count(), $classified->count()),

            // Whether the figures below mean anything at all. Absent from a summary stored
            // before delegation could be excluded, so an old result reads as unknown
            // rather than as a campaign where nobody did anything.
            // True where there is nothing left unmeasured, which includes a population
            // with nobody in it. A range in which nobody claimed is a result, and saying
            // it predates the measurement would send the operator to re-run an analysis
            // that would change nothing.
            'windows_available' => $windowed->isNotEmpty() || $genuine->isEmpty(),
            'windows_observed' => $windowed->count(),
            'windows_observed_new' => $windowedNew->count(),
            'windows_observed_established' => $windowedEstablished->count(),
            'windows_unknown' => $genuine->count() - $windowed->count(),
            'windows_observed_at' => $observedAt?->toIso8601String(),

            // Activity excludes a transaction whose only content for this wallet was its
            // own stake certificate. Delegating is not transacting for yourself.
            'new_active' => $active($windowedNew)->count(),
            'new_active_pct' => $pct($active($windowedNew)->count(), $windowedNew->count()),
            'established_active' => $active($windowedEstablished)->count(),
            'established_active_pct' => $pct($active($windowedEstablished)->count(), $windowedEstablished->count()),

            // The wallets the retired figure counted as activated: they delegated after
            // claiming and never did anything else.
            'delegation_only' => $windowed
                ->filter(fn ($w) => (int) $w->delegation_events > 0 && (int) $w->activity_count === 0)
                ->count(),

            // Current delegation state, which includes wallets that were already
            // delegating before this campaign ran.
            'delegated' => $delegationKnown->where('delegated', true)->count(),
            'delegated_pct' => $pct($delegationKnown->where('delegated', true)->count(), $delegationKnown->count()),
            'delegation_known' => $delegationKnown->count(),
            'new_delegated' => $newDelegationKnown->where('delegated', true)->count(),
            'new_delegated_pct' => $pct($newDelegationKnown->where('delegated', true)->count(), $newDelegationKnown->count()),
            'new_delegation_known' => $newDelegationKnown->count(),

            // Delegation that happened after the claim, which is the only part of the
            // figure above a campaign can take any credit for.
            //
            // Taken over the wallets whose delegation could be placed in time, not over every
            // windowed new wallet. The numerator asks for a delegation timestamp, so a wallet
            // that delegated at a time the chain query returned no time for fails it while
            // sitting in the denominator, and is counted as a wallet that did not delegate.
            // It is the same wallet the follow-up table holds out of the delegation window
            // for the same reason, so leaving it in here put two figures for one fact on one
            // screen.
            'new_delegated_after_claim' => $delegatedAfter($windowedNew)->count(),
            'new_delegated_after_claim_pct' => $pct(
                $delegatedAfter($windowedNew)->count(),
                $windowedNew->reject(fn ($w) => $w->delegationTimingUnknown())->count(),
            ),
            'new_delegated_after_claim_known' => $windowedNew->reject(fn ($w) => $w->delegationTimingUnknown())->count(),

            // Over the wallets a windowed run read, like every other follow-up figure. A
            // wallet whose post-claim transactions were never read has no answer here
            // either, and counting it as one that touched no contract would be the same
            // mistake in a quieter place.
            'script_interactors' => $windowed->where('script_interactions', '>', 0)->count(),
            'script_interactors_pct' => $pct($windowed->where('script_interactions', '>', 0)->count(), $windowed->count()),

            'windows' => $this->windows($windowed, $windowedNew, $pct),

            // Null, not zero, where no wallet was watched for a length anybody recorded.
            // The count travels with the average for the same reason a denominator travels
            // with a percentage: an average over nobody is not an average of nothing.
            'observation_days_avg' => $days->isNotEmpty() ? round($days->avg(), 1) : null,
            'observation_days_max' => $days->isNotEmpty() ? $days->max() : null,
            'observation_wallets' => $days->count(),
            'first_claim_at' => $claimedAt->min()?->toIso8601String(),
            'last_claim_at' => $claimedAt->max()?->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
        ] + $coverage;
    }

    /**
     * How many days each wallet was actually watched for.
     *
     * Read from what the run recorded, and from nothing else. A campaign analysed in July
     * watched its wallets for as long as it watched them, and a figure that grows every
     * day nobody re-runs the analysis is describing the reader rather than the campaign.
     *
     * A wallet with no recorded length was not watched: either no run has read it since
     * the length was recorded, or the read that went for it failed. Standing the elapsed
     * time in for it counted the reader's clock as an observation and let a wallet nobody
     * has looked at since January claim two hundred days of being watched. Such a wallet
     * is absent here, and `observation_wallets` says how many the average was taken over.
     */
    private function observedDays(Collection $genuine): Collection
    {
        return $genuine
            ->map(fn ($wallet) => $wallet->observed_seconds === null
                ? null
                : $wallet->observed_seconds / CampaignWalletInsight::DAY)
            ->filter(fn ($days) => $days !== null)
            ->values();
    }

    /**
     * What happened inside thirty, sixty and ninety days of each claim.
     *
     * Three counts per window, not one, because they mean different things and only one of
     * them is a result:
     *
     *   observable  wallets whose claim is at least this old, measured against the moment
     *               the run read the chain, and whose follow-up can be placed against a
     *               boundary at all
     *   not_yet     wallets too recently claimed to have a window this long. Not a zero:
     *               nothing has failed to happen yet
     *   untimed     wallets that did something the provider gave no time for. Also not a
     *               zero: it happened, and where in the window is unknowable
     *   active      of the observable ones, how many acted inside the window
     *
     * A wallet that claimed nine days ago has no ninety-day answer, and folding it into
     * the denominator would report a campaign as having failed at something it has not
     * been given time to do. The boundary is inclusive, so a transaction exactly thirty
     * days after the claim is inside the thirty-day window.
     *
     * A wallet whose only follow-up transaction carries no block time is held out the same
     * way. It counts in the headline, because it did transact, and leaving it in a window's
     * denominator with nothing it could ever put in the numerator would report the same
     * wallet as having succeeded and failed on one screen.
     *
     * Transacting and delegating are timed apart, so they are held out apart. A wallet with
     * an untimed transaction and a timed delegation has no activity window and a perfectly
     * good delegation window, and each measure carries the population it was taken over.
     *
     * @return array<int, array<string, mixed>> one entry per window, in order
     */
    private function windows(Collection $windowed, Collection $windowedNew, callable $pct): array
    {
        // A list rather than an object keyed by day count. MySQL's JSON type reorders an
        // object's keys and SQLite does not, so an object here would make the stored
        // summary read differently on the two engines for no benefit.
        return array_map(function (int $days) use ($windowed, $windowedNew, $pct) {
            $watched = $windowed->filter(fn ($w) => $w->observableFor($days));
            $watchedNew = $windowedNew->filter(fn ($w) => $w->observableFor($days));

            $untimed = $watched->filter(fn ($w) => $w->activityTimingUnknown());
            $untimedNew = $watchedNew->filter(fn ($w) => $w->activityTimingUnknown());

            $observable = $watched->reject(fn ($w) => $w->activityTimingUnknown());
            $observableNew = $watchedNew->reject(fn ($w) => $w->activityTimingUnknown());

            $delegationUntimedNew = $watchedNew->filter(fn ($w) => $w->delegationTimingUnknown());
            $delegationObservableNew = $watchedNew->reject(fn ($w) => $w->delegationTimingUnknown());

            $active = $observable->filter(fn ($w) => $w->activeWithin($days));
            $activeNew = $observableNew->filter(fn ($w) => $w->activeWithin($days));
            $delegatedNew = $delegationObservableNew->filter(fn ($w) => $w->delegatedWithin($days));

            return [
                'days' => $days,
                'observable' => $observable->count(),
                'not_yet' => $windowed->count() - $watched->count(),
                'untimed' => $untimed->count(),
                'active' => $active->count(),
                'active_pct' => $pct($active->count(), $observable->count()),
                'new_observable' => $observableNew->count(),
                'new_not_yet' => $windowedNew->count() - $watchedNew->count(),
                'new_untimed' => $untimedNew->count(),
                'new_active' => $activeNew->count(),
                'new_active_pct' => $pct($activeNew->count(), $observableNew->count()),
                // The delegation window's own denominator. It is not the activity one: a
                // wallet is held out of a window by the event that window measures, and
                // nothing else.
                'new_delegation_observable' => $delegationObservableNew->count(),
                'new_delegation_untimed' => $delegationUntimedNew->count(),
                'new_delegated' => $delegatedNew->count(),
                'new_delegated_pct' => $pct($delegatedNew->count(), $delegationObservableNew->count()),
            ];
        }, self::WINDOW_DAYS);
    }

    /**
     * How much of the campaign the numbers above actually describe.
     *
     * Three counts rather than one, because they fail for different reasons and only one
     * of them is fixable: a claim with no transaction hash has not been confirmed yet and
     * a status check may still confirm it, while a confirmed claim presenting an
     * enterprise address has no stake key and can never be classified at all.
     *
     * Claims are counted by when they were recorded and per-wallet rows by the claim
     * timestamp stamped at analysis. For a range given in whole days the two agree except
     * for a claim made either side of a boundary midnight.
     *
     * @return array<string, int|float>
     */
    private function coverage(Campaign $campaign, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $inWindow = fn () => $campaign->claims()
            ->when($from, fn ($query) => $query->where('claims.created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('claims.created_at', '<=', $to));

        $total = $inWindow()->count();
        $confirmed = $inWindow()->whereNotNull('claims.transaction_hash')->count();
        $analyzable = $inWindow()
            ->whereNotNull('claims.transaction_hash')
            ->where('claims.stake_key', 'like', 'stake%')
            ->count();

        return [
            'claims_total' => $total,
            'claims_confirmed' => $confirmed,
            'claims_analyzable' => $analyzable,
            'claims_unconfirmed' => max(0, $total - $confirmed),
            'coverage_pct' => $total > 0 ? round(100 * $analyzable / $total, 1) : 0.0,
        ];
    }

    /**
     * Transaction detail from Koios, keyed by lower-case hash.
     *
     * Every optional section of tx_info defaults to off, so anything this analysis reads
     * has to be asked for by name: without `_inputs` the response still carries an
     * `inputs` key and it is an empty array, which would read as a transaction nobody
     * sent. `_certs` is asked for only where certificates are read, because it is the
     * post-claim transactions that have to be told apart from delegations and the claim
     * transactions are looked at for their height and time alone.
     *
     * @param  array<int, string>  $hashes
     * @param  bool  $certificates  also ask for the certificates in each transaction
     * @return array{rows: array<string, array>, unread: array<string, true>} rows keyed by
     *                                                                        lower-case tx hash, and the hashes that were asked for and not answered
     */
    private function txInfo(array $hashes, string $network, bool $certificates = false): array
    {
        $hashes = array_values(array_unique(array_filter(array_map(
            static fn ($h) => strtolower(trim((string) $h)),
            $hashes
        ))));

        $out = [];
        foreach (array_chunk($hashes, self::TX_BATCH) as $batch) {
            $body = ['_tx_hashes' => $batch, '_inputs' => true];

            if ($certificates) {
                $body['_certs'] = true;
            }

            $rows = $this->koios($network, 'tx_info', $body);

            foreach ($rows ?? [] as $tx) {
                $hash = $tx['tx_hash'] ?? null;

                if (! $hash) {
                    continue;
                }

                // The post-claim pass reads the inputs, the outputs and the certificates
                // of every transaction, and asks for all three by name. A row that comes
                // back without one of them is not an answer to what was asked, and reading
                // it as one would report a transaction nobody sent, a payment that went
                // nowhere, or a delegation that carried no certificate. Left out of the
                // rows, its hash falls into `unread` below and the wallets it belongs to
                // keep their windows unknown.
                if ($certificates && ! (
                    is_array($tx['inputs'] ?? null)
                    && is_array($tx['outputs'] ?? null)
                    && is_array($tx['certificates'] ?? null)
                )) {
                    Log::warning('Koios tx_info answered without the sections it was asked for.', [
                        'tx_hash' => $hash,
                    ]);

                    continue;
                }

                $out[strtolower($hash)] = $tx;
            }
        }

        // A hash asked for and not answered was not read, whether the whole batch failed
        // or the answer simply did not carry it. Either way the transaction's detail is
        // missing, and anything derived from it would be a guess.
        $unread = [];
        foreach ($hashes as $hash) {
            if (! isset($out[$hash])) {
                $unread[$hash] = true;
            }
        }

        return ['rows' => $out, 'unread' => $unread];
    }

    /**
     * @return ?array<int, array> null when the read failed, which is not an empty history
     */
    private function accountTxs(string $stakeKey, string $network): ?array
    {
        return $this->read(
            fn () => Http::koios($network)
                ->retry(3, 750, throw: false)
                ->get('account_txs', ['_stake_address' => $stakeKey]),
            'account_txs',
            ['stake_key' => $stakeKey],
        );
    }

    /**
     * Current delegation state per account.
     *
     * A stake key missing from a successful answer is a stake key the chain has no
     * registration for, which is a wallet that is not delegating. A stake key in a request
     * that failed is unknown, and the two are returned apart.
     *
     * @param  array<int, string>  $stakeKeys
     * @return array{rows: array<string, array{delegated: bool, pool: ?string}>, unread: array<string, true>}
     */
    private function accountInfo(array $stakeKeys, string $network): array
    {
        $out = [];
        $unread = [];
        foreach (array_chunk(array_values($stakeKeys), self::ACCOUNT_BATCH) as $batch) {
            $rows = $this->koios($network, 'account_info', ['_stake_addresses' => $batch]);

            if ($rows === null) {
                foreach ($batch as $stake) {
                    $unread[$stake] = true;
                }

                continue;
            }

            foreach ($rows as $account) {
                if ($stake = $account['stake_address'] ?? null) {
                    $out[$stake] = [
                        'delegated' => ! empty($account['delegated_pool']),
                        'pool' => $account['delegated_pool'] ?? null,
                    ];
                }
            }
        }

        return ['rows' => $out, 'unread' => $unread];
    }

    /**
     * @return ?array<int, array> null when the request failed, which is not an empty answer
     */
    private function koios(string $network, string $endpoint, array $body): ?array
    {
        return $this->read(
            fn () => Http::koios($network)
                ->retry(3, 750, throw: false)
                ->post($endpoint, $body),
            $endpoint,
        );
    }

    /**
     * One request to the query layer, and the rows it answered with, or null when there
     * are none to be had.
     *
     * Four ways a request produces no rows. They are one fact: this run was told nothing,
     * so whatever it was asking about stays unknown.
     *
     *   unreachable   the request never completed. DNS, a refused connection or a timeout,
     *                 after the retries have been spent.
     *   refused       it completed and the status says the body is not an answer: any 4xx
     *                 or any 5xx.
     *   undecodable   a 2xx whose body is not JSON. A proxy or a CDN interstitial is
     *                 exactly this, and it carries a 200.
     *   wrong shape   a 2xx that decodes to something these endpoints never return: an
     *                 object where a list of rows belongs, or a list with something in it
     *                 that is not a row. An error document is usually the first of those.
     *
     * The last two are why a body is not trusted for having decoded. Both used to reach
     * the caller as an empty list, which reads as a wallet with no history and an account
     * with no delegation, and those are measurements this run never made.
     *
     * An empty list is none of the four. It is the provider saying there is nothing, which
     * is an answer, and it is returned as one.
     *
     * @param  callable(): Response  $send
     * @param  array<string, mixed>  $context  what was being asked about, for the log line
     * @return ?array<int, array<string, mixed>>
     */
    private function read(callable $send, string $endpoint, array $context = []): ?array
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            $this->pace();

            Log::warning('Koios could not be reached.', $context + [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->pace();

        if ($response->failed()) {
            Log::warning('Koios request failed.', $context + [
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            return null;
        }

        return $this->rows($response, $endpoint, $context);
    }

    /**
     * The rows in a successful answer, or null when the body is not rows.
     *
     * @param  array<string, mixed>  $context
     * @return ?array<int, array<string, mixed>>
     */
    private function rows(Response $response, string $endpoint, array $context): ?array
    {
        $body = $response->json();

        if (! is_array($body) || ! array_is_list($body)) {
            Log::warning('Koios answered 2xx with something that is not a list of rows.', $context + [
                'endpoint' => $endpoint,
                'status' => $response->status(),
            ]);

            return null;
        }

        foreach ($body as $row) {
            if (! is_array($row)) {
                Log::warning('Koios answered 2xx with a list that does not hold rows.', $context + [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                ]);

                return null;
            }
        }

        return $body;
    }

    private function pace(): void
    {
        if ($this->paced) {
            usleep(self::PACE_MICROSECONDS);
        }
    }
}

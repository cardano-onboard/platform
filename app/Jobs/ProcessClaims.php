<?php

namespace App\Jobs;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessClaims implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $campaign_id)
    {
        Log::debug('Building ProcessClaims job...', ['id' => $campaign_id]);
    }

    /**
     * Auto-release the unique lock after 2x the configured push delay
     * so a stuck or crashed job can never permanently block future dispatches.
     * Minimum of 60 seconds to ensure the lock outlives normal job execution.
     */
    public function uniqueFor(): int
    {
        return max(60, ((int) config('cardano.push_delay', 5)) * 60 * 2);
    }

    public function handle(): void
    {
        Log::info('ProcessClaims: starting', ['campaign_id' => $this->campaign_id]);

        $campaign = Campaign::with('wallet')
            ->find($this->campaign_id);

        if (! $campaign) {
            Log::error('ProcessClaims: campaign not found', ['campaign_id' => $this->campaign_id]);

            return;
        }

        if (! $campaign->wallet) {
            Log::error('ProcessClaims: campaign has no wallet', ['campaign_id' => $this->campaign_id]);

            return;
        }

        $unfinished_claims = $campaign->claims()
            ->with(['code.rewards'])
            ->whereNull('transaction_id')
            ->get();

        Log::info('ProcessClaims: found unfinished claims', [
            'campaign_id' => $this->campaign_id,
            'unfinished_count' => $unfinished_claims->count(),
        ]);

        if ($unfinished_claims->isEmpty()) {
            return;
        }

        $backend = $campaign->wallet->resolveBackend();
        Log::info('ProcessClaims: resolved backend', [
            'campaign_id' => $this->campaign_id,
            'backend_class' => get_class($backend),
            'wallet_backend' => $campaign->wallet->backend,
        ]);

        $recipients = [];
        foreach ($unfinished_claims as $claim) {
            $claimed_code = [
                'pooCode' => $claim->code->code,
                'address' => $claim->address,
                'lovelace' => $claim->code->lovelace,
                'tokens' => [],
            ];

            foreach ($claim->code->rewards as $token) {
                $claimed_code['tokens'][] = [
                    'policy' => $token->policy_hex,
                    'name' => $token->asset_hex,
                    'amount' => $token->quantity,
                ];
            }

            Log::info('ProcessClaims: built recipient', [
                'campaign_id' => $this->campaign_id,
                'claim_id' => $claim->id,
                'code' => $claim->code->code,
                'address' => $claim->address,
                'lovelace' => $claim->code->lovelace,
                'token_count' => count($claimed_code['tokens']),
                'tokens' => $claimed_code['tokens'],
            ]);

            $recipients[] = $claimed_code;
        }

        Log::info('ProcessClaims: submitting payment', [
            'campaign_id' => $this->campaign_id,
            'wallet_key' => $campaign->wallet->key,
            'network' => $campaign->network,
            'txn_msg' => $campaign->txn_msg,
            'recipient_count' => count($recipients),
            'total_tokens' => array_sum(array_map(fn ($r) => count($r['tokens']), $recipients)),
        ]);

        $result = $backend->submitPayment(
            $campaign->wallet->key,
            $recipients,
            $campaign->network,
            $campaign->txn_msg
        );

        Log::info('ProcessClaims: backend response received', [
            'campaign_id' => $this->campaign_id,
            'result_keys' => array_keys($result),
            'purchase_id_count' => isset($result['purchaseIds']) ? count($result['purchaseIds']) : 0,
            'result' => $result,
        ]);

        $ids = $result['purchaseIds'] ?? [];

        if (empty($ids)) {
            Log::warning('ProcessClaims: no purchase IDs returned from backend', [
                'campaign_id' => $this->campaign_id,
                'recipient_count' => count($recipients),
            ]);

            return;
        }

        $updated_count = 0;
        foreach ($unfinished_claims as $claim) {
            $purchaseId = $ids[$claim->code->code][$claim->address] ?? null;
            if ($purchaseId) {
                $claim->transaction_id = $purchaseId;
                $claim->save();
                $updated_count++;
            } else {
                Log::warning('ProcessClaims: claim has no matching purchase ID', [
                    'campaign_id' => $this->campaign_id,
                    'claim_id' => $claim->id,
                    'code' => $claim->code->code,
                    'address' => $claim->address,
                ]);
            }
        }

        Log::info('ProcessClaims: completed', [
            'campaign_id' => $this->campaign_id,
            'claims_processed' => $unfinished_claims->count(),
            'claims_updated' => $updated_count,
        ]);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->campaign_id))->releaseAfter(config('cardano.push_delay', 5) * 60),
        ];
    }

    public function uniqueId(): string
    {
        return $this->campaign_id;
    }
}

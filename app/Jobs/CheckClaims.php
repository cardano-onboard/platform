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

class CheckClaims implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_RETRIES = 5;

    public function __construct(public string $campaign_id)
    {
        //
    }

    /**
     * Unique key per campaign — without this, Laravel uses the class name
     * as the unique key, blocking ALL campaigns when one job is queued.
     */
    public function uniqueId(): string
    {
        return $this->campaign_id;
    }

    /**
     * Auto-release the unique lock after 2x the configured push delay
     * so a stuck or crashed job can never permanently block future status checks.
     * Minimum of 60 seconds to ensure the lock outlives normal job execution.
     */
    public function uniqueFor(): int
    {
        return max(60, ((int) config('cardano.push_delay', 5)) * 60 * 2);
    }

    public function handle(): void
    {
        Log::info('CheckClaims: starting', ['campaign_id' => $this->campaign_id]);

        $campaign = Campaign::with('wallet')
            ->find($this->campaign_id);

        if (! $campaign) {
            Log::error('CheckClaims: campaign not found', ['campaign_id' => $this->campaign_id]);

            return;
        }

        $check_claims = $campaign->claims()
            ->with(['code.rewards'])
            ->whereNotNull('transaction_id')
            ->whereNull('transaction_hash')
            ->whereNotIn('status', ['failed', 'completed'])
            ->get();

        Log::info('CheckClaims: found pending claims', [
            'campaign_id' => $this->campaign_id,
            'pending_count' => $check_claims->count(),
        ]);

        if ($check_claims->isEmpty()) {
            return;
        }

        $backend = $campaign->wallet->resolveBackend();

        $stats = ['completed' => 0, 'timeout' => 0, 'retried' => 0, 'failed' => 0, 'processing' => 0, 'unknown' => 0];

        foreach ($check_claims as $claim) {
            if (! $claim->transaction_id) {
                continue;
            }

            $result = $backend->checkStatus($claim->transaction_id, $campaign->network);

            Log::info('CheckClaims: checkStatus result', [
                'campaign_id' => $this->campaign_id,
                'claim_id' => $claim->id,
                'transaction_id' => $claim->transaction_id,
                'result_status' => $result['status'] ?? null,
                'tx_hash' => $result['txHash'] ?? null,
            ]);

            switch ($result['status']) {
                case 'completed':
                    $claim->transaction_hash = $result['txHash'];
                    $claim->status = 'completed';
                    $claim->save();
                    $stats['completed']++;
                    break;

                case 'timeout':
                    $claim->retry_count++;
                    if ($claim->retry_count >= self::MAX_RETRIES) {
                        $claim->status = 'failed';
                        $claim->save();
                        $stats['failed']++;
                        Log::warning('CheckClaims: claim exceeded max retries, marked failed', [
                            'campaign_id' => $this->campaign_id,
                            'claim_id' => $claim->id,
                            'retry_count' => $claim->retry_count,
                        ]);
                    } else {
                        $claim->transaction_id = null;
                        $claim->status = 'pending';
                        $claim->save();
                        $stats['retried']++;
                        Log::info('CheckClaims: retrying claim', [
                            'campaign_id' => $this->campaign_id,
                            'claim_id' => $claim->id,
                            'retry_count' => $claim->retry_count,
                        ]);
                        ProcessClaims::dispatch($this->campaign_id)
                            ->delay((int) config('cardano.push_delay', 5) * 60);
                    }
                    break;

                case 'processing':
                    // Phyrhose is working on the transaction but it hasn't hit the chain yet.
                    // No action needed — the next scheduler tick will check again.
                    $stats['processing']++;
                    Log::info('CheckClaims: claim still processing', [
                        'campaign_id' => $this->campaign_id,
                        'claim_id' => $claim->id,
                        'transaction_id' => $claim->transaction_id,
                    ]);
                    break;

                default:
                    $stats['unknown']++;
                    Log::warning('CheckClaims: unknown status from backend', [
                        'campaign_id' => $this->campaign_id,
                        'claim_id' => $claim->id,
                        'result' => $result,
                    ]);
                    break;
            }
        }

        Log::info('CheckClaims: completed', [
            'campaign_id' => $this->campaign_id,
            'total_checked' => $check_claims->count(),
            'stats' => $stats,
        ]);
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->campaign_id))->dontRelease()
                ->expireAfter(180),
        ];
    }
}

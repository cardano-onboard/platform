<?php

namespace App\Services;

use App\Contracts\ReportsTaskProgress;
use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Asks the transaction backend what happened to every claim still waiting on an answer,
 * and records it.
 *
 * This is the body of the scheduled status check, lifted out of the job so the onboarding
 * analysis can run the same pass before it measures anything. The analysis is blind to a
 * claim with no transaction hash, and a hash only arrives here, so an analysis that did
 * not do this first would report percentages of whichever claims someone happened to have
 * checked.
 *
 * Only one pass per campaign runs at a time. Two concurrent passes would ask the backend
 * about the same claim twice, and a timed-out claim would have its retry count advanced
 * twice for one failure, which brings it to the permanent failure ceiling early.
 */
class ClaimStatusChecker
{
    public const MAX_RETRIES = 5;

    /** How long a pass may hold the per-campaign lock before it is assumed dead. */
    private const LOCK_SECONDS = 300;

    /**
     * @param  int  $waitSeconds  how long to wait for a pass already in flight, rather
     *                            than returning immediately. The analysis waits, because
     *                            proceeding without those answers is the failure it
     *                            exists to avoid; the scheduler does not, because it will
     *                            come round again.
     * @param  ReportsTaskProgress|null  $progress  where to say how many claims have been
     *                                              asked about, for a run somebody is watching
     * @return array{checked: int, completed: int, timeout: int, retried: int, failed: int, processing: int, unknown: int, skipped: bool}
     */
    public function check(Campaign $campaign, int $waitSeconds = 0, ?ReportsTaskProgress $progress = null): array
    {
        $stats = [
            'checked' => 0,
            'completed' => 0,
            'timeout' => 0,
            'retried' => 0,
            'failed' => 0,
            'processing' => 0,
            'unknown' => 0,
            'skipped' => false,
        ];

        $lock = Cache::lock('claim-status-check:'.$campaign->id, self::LOCK_SECONDS);

        try {
            if ($waitSeconds > 0) {
                $lock->block($waitSeconds);
            } elseif (! $lock->get()) {
                throw new LockTimeoutException;
            }
        } catch (LockTimeoutException) {
            Log::info('Claim status check skipped: another pass is already running.', [
                'campaign_id' => $campaign->id,
            ]);

            return ['skipped' => true] + $stats;
        }

        try {
            return $this->pass($campaign, $stats, $progress);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    private function pass(Campaign $campaign, array $stats, ?ReportsTaskProgress $progress = null): array
    {
        $claims = $campaign->claims()
            ->awaitingConfirmation()
            ->with(['code.rewards'])
            ->get();

        Log::info('Claim status check: pending claims found', [
            'campaign_id' => $campaign->id,
            'pending_count' => $claims->count(),
        ]);

        if ($claims->isEmpty()) {
            return $stats;
        }

        // A campaign whose wallet has not been provisioned has no backend to ask. It
        // cannot hold claims either, so this is a corrupt row rather than a state to
        // handle, and it is said out loud instead of fataling on a null.
        if (! $campaign->wallet) {
            Log::error('Claim status check: campaign has no wallet.', [
                'campaign_id' => $campaign->id,
            ]);

            return $stats;
        }

        $backend = $campaign->wallet->resolveBackend();
        $total = $claims->count();

        $progress?->progress(0, $total);

        foreach ($claims as $claim) {
            $result = $backend->checkStatus($claim->transaction_id, $campaign->network);
            $stats['checked']++;
            $progress?->progress($stats['checked'], $total);

            Log::info('Claim status check: backend result', [
                'campaign_id' => $campaign->id,
                'claim_id' => $claim->id,
                'transaction_id' => $claim->transaction_id,
                'result_status' => $result['status'] ?? null,
                'tx_hash' => $result['txHash'] ?? null,
            ]);

            switch ($result['status'] ?? null) {
                case 'completed':
                    $claim->transaction_hash = $result['txHash'];
                    $claim->status = 'completed';
                    $claim->save();
                    $stats['completed']++;
                    break;

                case 'timeout':
                    $stats['timeout']++;
                    $this->handleTimeout($campaign, $claim, $stats);
                    break;

                case 'processing':
                    // The backend is working on the transaction but it has not hit the
                    // chain yet. Nothing to do: the next pass asks again.
                    $stats['processing']++;
                    Log::info('Claim status check: claim still processing', [
                        'campaign_id' => $campaign->id,
                        'claim_id' => $claim->id,
                        'transaction_id' => $claim->transaction_id,
                    ]);
                    break;

                default:
                    $stats['unknown']++;
                    Log::warning('Claim status check: unknown status from backend', [
                        'campaign_id' => $campaign->id,
                        'claim_id' => $claim->id,
                        'result' => $result,
                    ]);
                    break;
            }
        }

        Log::info('Claim status check: complete', [
            'campaign_id' => $campaign->id,
            'stats' => $stats,
        ]);

        return $stats;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function handleTimeout(Campaign $campaign, Claim $claim, array &$stats): void
    {
        $claim->retry_count++;

        if ($claim->retry_count >= self::MAX_RETRIES) {
            $claim->status = 'failed';
            $claim->save();
            $stats['failed']++;

            Log::warning('Claim status check: claim exceeded max retries, marked failed', [
                'campaign_id' => $campaign->id,
                'claim_id' => $claim->id,
                'retry_count' => $claim->retry_count,
            ]);

            return;
        }

        $claim->transaction_id = null;
        $claim->status = 'pending';
        $claim->save();
        $stats['retried']++;

        Log::info('Claim status check: retrying claim', [
            'campaign_id' => $campaign->id,
            'claim_id' => $claim->id,
            'retry_count' => $claim->retry_count,
        ]);

        ProcessClaims::dispatch($campaign->id)
            ->delay((int) config('cardano.push_delay', 5) * 60);
    }
}

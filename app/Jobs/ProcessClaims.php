<?php

namespace App\Jobs;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends a campaign's unsent claims to the transaction backend in one submission.
 *
 * Unique only until a worker starts it. A claim taken while a run is going queues a run of
 * its own rather than vanishing into the running one's lock, and WithoutOverlapping keeps
 * that run from starting until this one has finished. Whatever a run leaves unsent, it
 * queues another run for, so an unsent claim never waits on the next claim to arrive.
 */
class ProcessClaims implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The shortest wait before sending again after a run that left claims it tried to send
     * unsent. Doubled for each such run in a row, up to MAX_DELAY_SECONDS, so a backend that
     * keeps refusing is not asked again in a tight loop even with no push delay configured.
     */
    public const FAILED_SUBMIT_BACKOFF_SECONDS = 30;

    /** The longest delay a queued job may carry. SQS refuses anything longer. */
    public const MAX_DELAY_SECONDS = 900;

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
        return max(60, ((int) config('cardano.push_delay_seconds', 30)) * 2);
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

        $this->followUp($campaign, $this->send($campaign));
    }

    /**
     * @return bool|null null when there was nothing to send, true when every claim tried
     *                   went out, false when the backend left any of them unsent
     */
    private function send(Campaign $campaign): ?bool
    {
        // A claim held for credit is accepted and recorded but not sent. Sending it would
        // deliver a reward the campaign has not paid for, and on the dollar path that is
        // network fees and minimum UTxO out of our own pocket. It is released when the
        // operator tops up, oldest first. The scope leaves held claims out, and claims still
        // waiting out a retry backoff, which the run that follows is scheduled for.
        $unfinished_claims = $campaign->claims()
            ->with(['code.rewards'])
            ->dueForSubmission()
            ->get();

        Log::info('ProcessClaims: found unfinished claims', [
            'campaign_id' => $this->campaign_id,
            'unfinished_count' => $unfinished_claims->count(),
        ]);

        if ($unfinished_claims->isEmpty()) {
            return null;
        }

        $backend = $campaign->wallet->resolveBackend();
        Log::info('ProcessClaims: resolved backend', [
            'campaign_id' => $this->campaign_id,
            'backend_class' => get_class($backend),
            'wallet_backend' => $campaign->wallet->backend,
        ]);

        $recipients = [];

        // What each claim is being sent, kept beside it so the claim can record it once the
        // backend accepts the payment. Built here rather than read back afterwards, because
        // the code is free to change between now and the next run of this job and the
        // question the record answers is what went out, not what is configured.
        $rewards = [];

        foreach ($unfinished_claims as $claim) {
            $claimed_code = [
                'claimId' => $claim->id,
                'pooCode' => $claim->code->code,
                'address' => $claim->address,
                'lovelace' => $claim->code->lovelace,
                'tokens' => [],
            ];

            $rewards[$claim->id] = [
                'lovelace' => (int) $claim->code->lovelace,
                'tokens' => [],
            ];

            foreach ($claim->code->rewards as $token) {
                $claimed_code['tokens'][] = [
                    'policy' => $token->policy_hex,
                    'name' => $token->asset_hex,
                    'amount' => $token->quantity,
                ];

                $rewards[$claim->id]['tokens'][] = [
                    'policy' => $token->policy_hex,
                    'asset' => $token->asset_hex,
                    'quantity' => (int) $token->quantity,
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

        // A backend that pays claim by claim answers under claimIds. The others answer under
        // the code and address, which two claims of one wallet share.
        $ids = $result['purchaseIds'] ?? [];
        $byClaim = $result['claimIds'] ?? [];

        if (empty($ids) && empty($byClaim)) {
            Log::warning('ProcessClaims: no purchase IDs returned from backend', [
                'campaign_id' => $this->campaign_id,
                'recipient_count' => count($recipients),
            ]);

            return false;
        }

        $updated_count = 0;
        foreach ($unfinished_claims as $claim) {
            $purchaseId = $byClaim[$claim->id] ?? $ids[$claim->code->code][$claim->address] ?? null;
            if ($purchaseId) {
                $claim->transaction_id = $purchaseId;
                // Stamped only for a claim the backend actually took. A submission that
                // produced no purchase id sent nothing, and recording a reward against it
                // would put a payment in the record that never happened. Force-filled
                // because these are money columns and nothing a claimant sends may reach
                // them.
                $claim->forceFill([
                    'reward_lovelace' => $rewards[$claim->id]['lovelace'] ?? null,
                    'reward_tokens' => $rewards[$claim->id]['tokens'] ?? [],
                ]);
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

        // The backend does not say when a transaction lands, so ask once, a little after
        // it has had time to. Without this a claim nobody is watching would wait for the
        // scheduler, and a deployment that runs none would wait for somebody to press
        // Check claims.
        if ($updated_count > 0) {
            CheckClaims::afterSubmission($this->campaign_id);
        }

        Log::info('ProcessClaims: completed', [
            'campaign_id' => $this->campaign_id,
            'claims_processed' => $unfinished_claims->count(),
            'claims_updated' => $updated_count,
        ]);

        return $updated_count === $unfinished_claims->count();
    }

    /**
     * Queue the next run for whatever is still unsent.
     *
     * Claims can be left because the backend refused or skipped them, or because they
     * arrived while this run was going. Either way they go out without waiting for another
     * claim to prompt it. After a run that left claims it tried to send, the wait doubles
     * with each such run in a row, and a run that sends everything it tried resets it.
     */
    private function followUp(Campaign $campaign, ?bool $allSent): void
    {
        $failuresKey = 'process-claims-failed-runs:'.$campaign->id;

        if ($allSent === false) {
            $failures = (int) Cache::get($failuresKey, 0) + 1;
            Cache::put($failuresKey, $failures, now()->addDay());
        } else {
            $failures = 0;
            Cache::forget($failuresKey);
        }

        if ($campaign->claims()->dueForSubmission()->exists()) {
            $delay = $failures > 0
                ? self::backoffAfterFailedRuns($failures)
                : min(self::MAX_DELAY_SECONDS, (int) config('cardano.push_delay_seconds', 30));
        } else {
            // Nothing due now. A claim waiting out a retry backoff gets a run for when its
            // wait ends, or for the longest delay a queue allows if that is sooner, and that
            // run schedules the next.
            $next = $campaign->claims()->awaitingSubmission()->min('claims.retry_after');

            if ($next === null) {
                return;
            }

            $delay = (int) max(1, min(self::MAX_DELAY_SECONDS, now()->diffInSeconds(Carbon::parse($next), false)));
        }

        Log::info('ProcessClaims: claims still unsent, queueing another run', [
            'campaign_id' => $campaign->id,
            'failed_runs_in_a_row' => $failures,
            'delay_seconds' => $delay,
        ]);

        self::dispatch($campaign->id)->delay($delay);
    }

    /**
     * Seconds to wait before sending again after this many runs in a row that left claims
     * they tried to send unsent.
     */
    public static function backoffAfterFailedRuns(int $failures): int
    {
        $base = max(self::FAILED_SUBMIT_BACKOFF_SECONDS, (int) config('cardano.push_delay_seconds', 30));

        return (int) min(self::MAX_DELAY_SECONDS, $base * 2 ** max(0, $failures - 1));
    }

    /**
     * A run that finds another running for the same campaign is dropped, not released back
     * onto the queue. The running one queues another run for whatever it leaves unsent, so
     * nothing is lost, and a released job counts an attempt each time it comes back, which
     * on a worker allowing one try ends in the failed jobs table.
     *
     * The lock lapses after overlapLockSeconds(), so a run whose worker died holding it
     * holds off the next run only that long, not for good.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->campaign_id))->dontRelease()
                ->expireAfter(self::overlapLockSeconds()),
        ];
    }

    /**
     * How long a run may hold the campaign: three times the send timeout, which leaves the
     * longest send the backend is allowed plus the database work around it well inside.
     */
    public static function overlapLockSeconds(): int
    {
        return 3 * max(1, (int) config('cardano.phyrhose.submit_timeout_seconds', 60));
    }

    public function uniqueId(): string
    {
        return $this->campaign_id;
    }
}

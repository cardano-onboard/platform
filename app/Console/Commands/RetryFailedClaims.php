<?php

namespace App\Console\Commands;

use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Give a campaign's failed claims another go.
 *
 * A claim is marked failed once its transaction has timed out as many times as a claim is
 * retried. When the cause has been dealt with, a bucket topped up or a backend back on its
 * feet, this puts those claims back to where a new claim starts, with a clean retry count,
 * and queues a send run for them.
 *
 * A failed claim that carries a real transaction hash was paid, so it is left alone as well:
 * sending it again would pay the reward twice. So is a failed claim the backend now reports
 * completed, which is completed instead.
 *
 * A held claim is left alone even if it is also marked failed. It is held because the
 * campaign cannot pay for it, and it is released when the operator tops up, not here.
 */
class RetryFailedClaims extends Command
{
    protected $signature = 'claims:retry-failed
        {campaign : The ID of the campaign whose failed claims to send again}
        {--dry-run : Report how many claims would be reset without changing anything}';

    protected $description = 'Reset a campaign\'s failed claims so they are sent again';

    public function handle(): int
    {
        $campaign = Campaign::find($this->argument('campaign'));

        if ($campaign === null) {
            $this->error('No campaign has that ID.');

            return self::FAILURE;
        }

        $ids = $campaign->claims()
            ->where('claims.status', 'failed')
            ->lacksRealTransactionHash()
            ->whereNull('claims.held_reason')
            ->pluck('claims.id');

        $ids = $this->withoutPaidClaims($campaign, $ids, ! $this->option('dry-run'));

        if ($this->option('dry-run')) {
            $this->info("{$ids->count()} failed claim(s) would be reset and sent again.");

            return self::SUCCESS;
        }

        if ($ids->isEmpty()) {
            $this->info('No failed claims to reset.');

            return self::SUCCESS;
        }

        $reset = Claim::whereIn('id', $ids)
            ->where('status', 'failed')
            ->lacksRealTransactionHash()
            ->whereNull('held_reason')
            ->update([
                'status' => 'pending',
                'retry_count' => 0,
                'transaction_id' => null,
                'retry_after' => null,
            ]);

        ProcessClaims::dispatch($campaign->id);

        Log::info('Failed claims reset to be sent again.', [
            'campaign_id' => $campaign->id,
            'claims_reset' => $reset,
        ]);

        $this->info("{$reset} failed claim(s) reset and queued to be sent again.");

        return self::SUCCESS;
    }

    /**
     * Keeps only the claims the backend says timed out.
     *
     * A claim fails after its transaction timed out as many times as a claim is retried, and
     * the transaction can still land after that. A claim with a numeric purchase id is asked
     * about before it is reset. It is reset only on an explicit timeout answer. One reported
     * completed is marked completed (with its hash when the answer has one) and not sent
     * again. Any other answer, such as processing, failed or one that cannot be read, leaves
     * the claim failed, because nothing says it was not paid.
     *
     * The pass stops at the first sign of provider trouble, an unreachable backend or an
     * answer that cannot be read (which is how a rate limit or a gateway error surfaces), and
     * the claims after it stay failed and are not asked about.
     *
     * @param  Collection<int, int>  $ids
     * @param  bool  $record  false for a dry run, which asks but writes nothing
     * @return Collection<int, int>
     */
    private function withoutPaidClaims(Campaign $campaign, Collection $ids, bool $record): Collection
    {
        $backend = $campaign->wallet?->resolveBackend();

        if ($backend === null) {
            return $ids;
        }

        $keep = collect();

        foreach ($ids->values() as $position => $id) {
            $claim = Claim::find($id);

            if ($claim === null) {
                continue;
            }

            if (! ctype_digit((string) $claim->transaction_id)) {
                $keep->push($id);

                continue;
            }

            try {
                $result = $backend->checkStatus((string) $claim->transaction_id, $campaign->network);
            } catch (ConnectionException) {
                $result = null;
            }

            $answer = $result['status'] ?? null;

            if ($result === null || $answer === 'unknown' || $answer === null) {
                $skipped = $ids->count() - $position - 1;
                $this->warn("Claim {$claim->id} was not reset: the transaction backend could not be asked, or its answer could not be read. Stopped here; {$skipped} claim(s) were not asked and stay failed.");

                return $keep;
            }

            if ($answer === 'timeout') {
                $keep->push($id);

                continue;
            }

            if ($answer !== 'completed') {
                $this->warn("Claim {$claim->id} was not reset: the backend says {$answer}.");

                continue;
            }

            if ($record && ! $claim->markConfirmed($result['txHash'] ?? null)) {
                $claim->markCompletedWithoutHash();
            }

            $this->line($record
                ? "Claim {$claim->id} was paid, so it is completed and not sent again."
                : "Claim {$claim->id} was paid, so it would be completed and not sent again.");
        }

        return $keep;
    }
}

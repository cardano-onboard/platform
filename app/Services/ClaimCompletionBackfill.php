<?php

namespace App\Services;

use App\Models\Claim;

/**
 * Completes claims that carry a real transaction hash and still say pending or failed.
 *
 * The status column arrived with a default of pending and no backfill, and the status check
 * that ran before it recorded only the hash. A claim confirmed by the inline lookup kept the
 * same gap afterwards. Either way the claim has a hash and a status that disagrees with it,
 * and since a claim with a hash is never revisited, nothing else repairs it. A failed claim
 * with a real hash was paid before it was marked failed, and it has to stop reading as
 * unpaid or the retry command would send it again.
 *
 * A hash starting null-tx- comes from the null backend and was never on a chain, and an empty
 * hash is no hash, so neither is touched. Completed claims are left as they are.
 *
 * Walked by id in chunks, so no statement holds locks on more than one chunk of rows, and each
 * update repeats the status test, so a claim changed since it was read is not overwritten.
 * A second run finds nothing to do.
 */
class ClaimCompletionBackfill
{
    public const STATUSES = ['pending', 'failed'];

    /**
     * How many claims run() would complete now. Writes nothing.
     */
    public function count(): int
    {
        return Claim::query()
            ->hasRealTransactionHash()
            ->whereIn('claims.status', self::STATUSES)
            ->count();
    }

    /**
     * @return int the number of claims completed
     */
    public function run(int $chunk = 1000): int
    {
        $chunk = max(1, $chunk);
        $lastId = 0;
        $completed = 0;

        while (true) {
            $ids = Claim::query()
                ->hasRealTransactionHash()
                ->where('claims.id', '>', $lastId)
                ->whereIn('claims.status', self::STATUSES)
                ->orderBy('claims.id')
                ->limit($chunk)
                ->toBase()
                ->pluck('claims.id');

            if ($ids->isEmpty()) {
                return $completed;
            }

            $completed += Claim::query()
                ->hasRealTransactionHash()
                ->whereIn('claims.id', $ids)
                ->whereIn('claims.status', self::STATUSES)
                ->toBase()
                ->update(['status' => 'completed']);

            $lastId = $ids->last();
        }
    }
}

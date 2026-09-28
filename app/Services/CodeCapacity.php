<?php

namespace App\Services;

use App\Models\Campaign;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Enforces the operator's own cap on how many codes a campaign may ever hold.
 *
 * Separate from the credit ledger's spend limit, which asks whether a campaign can afford
 * to pay for a code once it is claimed. This asks a plainer question the operator sets
 * directly: how many codes this campaign is allowed to have at all. A campaign with no cap
 * (max_codes is null) is unaffected by anything here.
 *
 * Every code-creation path goes through reserve(), a small number of rows at a time: the
 * campaign page's own form and the code-creation API reserve their whole (small, bounded)
 * batch in one call, and the bulk import reserves one row at a time inside its loop. A
 * single large upfront reservation for the whole import file was tried and rejected: it
 * counted a re-run of an already-imported file against the cap a second time even though
 * nothing new would actually be inserted, and it could not tell two files racing for the
 * same remaining room apart from one file safely fitting under it alone. Reserving row by
 * row, immediately before that row's own insert, is what makes the check and the write the
 * same atomic thing no matter which path is calling it.
 */
class CodeCapacity
{
    /**
     * Check that $count more codes fit under the cap, and create them while the campaign
     * row is still locked.
     *
     * $create runs inside the same transaction as the check, so two requests racing for
     * the last code under the limit cannot both read "one left" and both write, and a
     * refusal here leaves nothing behind. The lock is held only for the length of this one
     * call: a caller inserting many rows calls this once per row (or per small batch)
     * rather than once for the whole run, so the campaign row is never locked for longer
     * than one insert actually takes.
     *
     * A campaign with no cap never takes the lock at all. There is nothing for it to
     * protect: no count is ever compared against a limit that does not exist, so a request
     * against an uncapped campaign costs this class nothing beyond the transaction $create
     * already needs for its own atomicity.
     *
     * @template T
     *
     * @param  Closure(): T  $create
     * @return T
     */
    public function reserve(Campaign $campaign, int $count, Closure $create): mixed
    {
        if ($campaign->max_codes === null) {
            return DB::transaction($create);
        }

        return DB::transaction(function () use ($campaign, $count, $create) {
            $this->lockAndCheck($campaign, $count);

            return $create();
        });
    }

    private function lockAndCheck(Campaign $campaign, int $count): void
    {
        $locked = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

        if ($locked->max_codes === null) {
            return;
        }

        $existing = $locked->codes()->count();
        $limit = (int) $locked->max_codes;

        if ($existing + $count > $limit) {
            throw ValidationException::withMessages([
                'max_codes' => [$this->message($limit, $existing, $count)],
            ]);
        }
    }

    private function message(int $limit, int $existing, int $count): string
    {
        $room = max(0, $limit - $existing);

        if ($room === 0) {
            return "This campaign is limited to {$limit} codes and already has {$existing}, so no more can be created.";
        }

        return $count === 1
            ? "This campaign is limited to {$limit} codes and already has {$existing}; there is no room left for another."
            : "This campaign is limited to {$limit} codes and already has {$existing}, room for {$room} more, not {$count}.";
    }
}

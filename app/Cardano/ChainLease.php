<?php

namespace App\Cardano;

use App\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The right to build the next transaction in a campaign's chain.
 *
 * A campaign's funds live in one output at a time. Paying a batch of claims spends that output and
 * makes a new one, so the next batch has to spend what the last one produced. Two workers that both
 * read the same outputs build two transactions spending them, the chain accepts one, and the other
 * is refused after its claims have already been marked as sent.
 *
 * The framework's own protections do not cover this. Both are process-level and time-bounded: they
 * stop two runs of the same job overlapping for a while, and know nothing about whether a chain has
 * moved. A worker that runs longer than its lock still holds outputs nobody has taken from it.
 *
 * So the lease is a row, and it is taken with a conditional update rather than a read followed by a
 * write. That distinction is the whole thing. A read and then a write is two statements with a gap,
 * and two workers can both pass the read before either writes. An update with the condition in its
 * WHERE clause is one statement, and the database decides: exactly one of them changes a row, and
 * the other is told it changed none.
 *
 * A holder that dies without releasing its lease is the ordinary case rather than a failure worth
 * designing around, so a lease expires. What it must never do is expire while its holder is still
 * working, so the window is longer than any batch should take and a holder renews while it runs.
 */
final class ChainLease
{
    public function __construct(private readonly string $holder) {}

    /**
     * Take the lease, or be told somebody else has it.
     *
     * @return bool true where this holder now has it
     */
    public function take(Campaign $campaign, int $seconds = 600): bool
    {
        $now = CarbonImmutable::now();

        DB::table('campaign_chain_leases')->insertOrIgnore([
            'campaign_id' => $campaign->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // One statement. Everything that decides whether this holder may have the lease is in the
        // WHERE clause, so the database does the deciding and exactly one of any number of racing
        // workers sees a row change.
        DB::table('campaign_chain_leases')
            ->where('campaign_id', $campaign->id)
            ->where(function ($query) use ($now) {
                $query->whereNull('holder')
                    ->orWhere('holder', $this->holder)
                    ->orWhere('expires_at', '<=', $now);
            })
            ->update([
                'holder' => $this->holder,
                'expires_at' => $now->addSeconds($seconds),
                'updated_at' => $now,
            ]);

        // Who holds it now, read back rather than inferred from the affected-row count. MySQL
        // counts a row as affected only where a value changed, so a holder taking its own lease
        // again within the same second is told it changed nothing. The update above is still the
        // one statement that decides between racing workers; this only asks which of them won.
        return $this->holds($campaign);
    }

    private function holds(Campaign $campaign): bool
    {
        return DB::table('campaign_chain_leases')
            ->where('campaign_id', $campaign->id)
            ->where('holder', $this->holder)
            ->exists();
    }

    /**
     * Push the expiry out while still working.
     *
     * A batch that takes longer than expected should not have its lease taken from underneath it,
     * and lengthening the window for everybody to cover the slowest case would mean a dead holder
     * blocking a campaign for that long.
     */
    public function renew(Campaign $campaign, int $seconds = 600): bool
    {
        DB::table('campaign_chain_leases')
            ->where('campaign_id', $campaign->id)
            ->where('holder', $this->holder)
            ->update([
                'expires_at' => CarbonImmutable::now()->addSeconds($seconds),
                'updated_at' => CarbonImmutable::now(),
            ]);

        return $this->holds($campaign);
    }

    /**
     * Give it back, so the next batch does not wait out the expiry.
     */
    public function release(Campaign $campaign): void
    {
        DB::table('campaign_chain_leases')
            ->where('campaign_id', $campaign->id)
            ->where('holder', $this->holder)
            ->update(['holder' => null, 'expires_at' => null, 'updated_at' => CarbonImmutable::now()]);
    }
}

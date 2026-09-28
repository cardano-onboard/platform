<?php

namespace App\Services;

use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Support\Pricing;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Grants credit, spends it, and answers whether a claim can be served.
 *
 * A balance is the sum of the ledger rather than a column beside it, so it cannot drift.
 * At this volume the sum is cheap, and it is the only version of a balance that can still
 * explain itself a year later.
 */
class CreditLedger
{
    public const HELD_NO_CREDIT = 'no_credit';

    public const HELD_SPEND_LIMIT = 'spend_limit';

    public function balanceMicro(User $user): int
    {
        return (int) CreditTransaction::query()
            ->where('user_id', $user->id)
            ->sum('delta_micro');
    }

    /** What one campaign has consumed, as a positive figure. */
    public function spentMicro(Campaign $campaign): int
    {
        return -1 * (int) CreditTransaction::query()
            ->where('campaign_id', $campaign->id)
            ->where('kind', CreditTransaction::KIND_DEBIT)
            ->sum('delta_micro');
    }

    /**
     * Sell a pack.
     *
     * The tier is read once, here, from the size of this pack, and written onto the row.
     * Every credit in it keeps that price for as long as it exists, so a later change to
     * the tiers cannot re-rate credits somebody has already bought.
     */
    public function grant(User $user, int $credits, ?string $note = null, ?string $path = null): CreditTransaction
    {
        $path ??= Pricing::pathFor($user);

        if ($reason = Pricing::pathUnavailableReason($path)) {
            throw new \RuntimeException($reason);
        }

        $unit = Pricing::unitPrice($path, $credits);

        if ($unit === null) {
            throw new \RuntimeException("No rate is configured on the {$path} path, so a pack cannot be priced.");
        }

        return CreditTransaction::create([
            'user_id' => $user->id,
            'kind' => CreditTransaction::KIND_GRANT,
            'path' => $path,
            'delta_micro' => $credits * CreditTransaction::MICRO,
            'unit_price_lovelace' => Pricing::currencyOf($path) === 'ada' ? (int) $unit : null,
            'unit_price_usd' => Pricing::currencyOf($path) === 'usd' ? (string) $unit : null,
            'note' => $note,
        ]);
    }

    /**
     * Why this claim cannot be served, or null when it can.
     *
     * An unpriced path never holds anything. A deployment that charges nothing has no
     * balance to run out of, and a self-hosted instance must not have claims held against
     * a fee it was never going to collect.
     */
    public function holdReasonFor(Campaign $campaign, int $costMicro): ?string
    {
        $path = Pricing::pathFor($campaign);

        if (! Pricing::billingEnabled() || ! Pricing::isConfigured($path)) {
            return null;
        }

        // The path and the backend have to agree, and a mismatch is ours to notice
        // because nobody else can. It costs the customer nothing at the till, so no
        // complaint arrives, and it costs us the margin. It also costs the claimant the
        // reward: a bucket on this path is funded by us out of what the credit paid for,
        // and until that mechanism exists the ADA to send is simply never anywhere, so
        // every claim is accepted and none is delivered.
        //
        // Logged rather than refused, because a claimant is not the right person to stop
        // for a configuration error nobody told them about, and because a claim recorded
        // is a claim that can still be honoured once the bucket is funded.
        if (! Pricing::backendAgreesWithPath($campaign->wallet?->backend, $path)) {
            Log::error('Billing path and transaction backend disagree: this campaign cannot deliver what it is charging for.', [
                'campaign_id' => $campaign->id,
                'path' => $path,
                'backend' => $campaign->wallet?->backend,
            ]);
        }

        // A path that collects in band has already been paid by the time this is asked.
        // The fee came out of the campaign's own bucket as the claim was sent, so there
        // is nothing to debit and nothing to hold, and the bucket balance is the control
        // rather than a credit balance.
        if (Pricing::collectsInBand($path)) {
            return null;
        }

        if ($campaign->spend_limit_micro !== null
            && $this->spentMicro($campaign) + $costMicro > (int) $campaign->spend_limit_micro) {
            return self::HELD_SPEND_LIMIT;
        }

        if ($campaign->user === null || $this->balanceMicro($campaign->user) < $costMicro) {
            return self::HELD_NO_CREDIT;
        }

        return null;
    }

    /**
     * Spend credit against a claim, oldest pack first.
     *
     * A debit that crosses the end of a pack becomes two rows rather than one row at an
     * averaged price, because the value consumed has to stay traceable to the price it
     * was bought at.
     */
    public function debit(Claim $claim, Campaign $campaign, int $costMicro): void
    {
        $path = Pricing::pathFor($campaign);

        // Nothing to spend where the fee was already taken from the campaign's own
        // bucket. The guard lives here rather than at the call site so there is one
        // answer to "was this claim charged twice" and it is in the ledger.
        if (! Pricing::billingEnabled() || Pricing::collectsInBand($path)) {
            return;
        }

        DB::transaction(function () use ($claim, $campaign, $costMicro, $path) {
            $remaining = $costMicro;

            foreach ($this->grantsWithRemaining($campaign->user) as [$grant, $available]) {
                if ($remaining <= 0) {
                    break;
                }

                $take = min($remaining, $available);

                if ($take <= 0) {
                    continue;
                }

                CreditTransaction::create([
                    'user_id' => $campaign->user->id,
                    'campaign_id' => $campaign->id,
                    'claim_id' => $claim->id,
                    'source_id' => $grant->id,
                    'kind' => CreditTransaction::KIND_DEBIT,
                    'path' => $path,
                    'delta_micro' => -$take,
                    'unit_price_lovelace' => $grant->unit_price_lovelace,
                    'unit_price_usd' => $grant->unit_price_usd,
                ]);

                $remaining -= $take;
            }

            $claim->forceFill([
                'credits_micro' => $costMicro - max(0, $remaining),
                'held_reason' => null,
            ])->save();
        });
    }

    /**
     * Let go of everything that was waiting on credit, oldest claim first.
     *
     * Oldest first because the queue is the order people stood in. Returns how many were
     * released, and re-dispatches the campaigns they belong to.
     */
    public function release(User $user): int
    {
        $held = Claim::query()
            ->whereNotNull('held_reason')
            ->whereHas('code.campaign', static fn ($q) => $q->where('user_id', $user->id))
            ->with('code.campaign.user')
            ->orderBy('created_at')
            ->get();

        $released = 0;
        $campaigns = [];

        foreach ($held as $claim) {
            $campaign = $claim->code?->campaign;

            if ($campaign === null) {
                continue;
            }

            $cost = (int) ($claim->credits_micro ?: Pricing::claimCostMicro($claim->code->assetCount()));

            if ($this->holdReasonFor($campaign, $cost) !== null) {
                // Still short. Everything behind this one is newer, so stopping here
                // keeps the queue in the order people stood in rather than serving
                // whichever cheap claim happens to fit.
                break;
            }

            $this->debit($claim, $campaign, $cost);
            $campaigns[$campaign->id] = $campaign->id;
            $released++;
        }

        foreach ($campaigns as $campaignId) {
            // Dispatched through the bus rather than the facade's static helper, which
            // returns an object that dispatches when it is destroyed. Released claims
            // are queued from a command and from a service, where that destruction can
            // land after the caller has finished with the request or the test that
            // triggered it.
            Bus::dispatch(new ProcessClaims($campaignId));
        }

        if ($released > 0) {
            Log::info('Released claims held for credit.', [
                'user_id' => $user->id,
                'released' => $released,
                'campaigns' => count($campaigns),
            ]);
        }

        return $released;
    }

    /** @return Collection<int, array{0: CreditTransaction, 1: int}> */
    private function grantsWithRemaining(User $user): Collection
    {
        $used = CreditTransaction::query()
            ->where('user_id', $user->id)
            ->whereNotNull('source_id')
            ->selectRaw('source_id, SUM(delta_micro) as used')
            ->groupBy('source_id')
            ->pluck('used', 'source_id');

        return CreditTransaction::query()
            ->where('user_id', $user->id)
            ->where('kind', CreditTransaction::KIND_GRANT)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(static fn (CreditTransaction $grant) => [
                $grant,
                (int) $grant->delta_micro + (int) ($used[$grant->id] ?? 0),
            ]);
    }
}

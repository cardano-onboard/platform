<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One claimant wallet's onboarding classification for a campaign.
 *
 * `is_new` means the wallet had no on-chain history before its claim, so the campaign is
 * what put it on chain. Null means no run has managed to read that history: a chain read
 * that failed is not a wallet with nothing behind it, and `delegated` is null for the same
 * reason when the account could not be read.
 *
 * Activity and delegation are separate answers. `self_initiated_count` is every post-claim
 * transaction the wallet put an input into; `activity_count` is the subset that did
 * something other than submit one of the wallet's own certificates, and
 * `delegation_events` is the subset that did. A wallet that registered a stake key and
 * delegated is an input of that transaction, because it pays the deposit and the fee, so
 * counting inputs alone would report it as having transacted for itself.
 *
 * `windows_observed_at` is what tells an unanswered question from an answer of nothing.
 * Null means no run has read this wallet since the two were told apart, so every window
 * below is unknown. Set, with `first_activity_seconds` null, means the run looked and the
 * wallet had done nothing, which is a result and is shown as one.
 */
class CampaignWalletInsight extends Model
{
    use HasFactory;

    /** Seconds in a day, for turning a stored offset into a window. */
    public const DAY = 86400;

    protected $guarded = [];

    protected $casts = [
        'is_new' => 'boolean',
        'delegated' => 'boolean',
        'prior_tx_count' => 'integer',
        'is_operator' => 'boolean',
        'claimed_at' => 'datetime',
        'analyzed_at' => 'datetime',
        'windows_observed_at' => 'datetime',
        'first_activity_seconds' => 'integer',
        'first_delegation_seconds' => 'integer',
        'observed_seconds' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** Has any run read this wallet since delegation and activity were told apart. */
    public function isWindowed(): bool
    {
        return $this->windows_observed_at !== null;
    }

    /** Did a run read this wallet's history, which is what says whether it was new. */
    public function isClassified(): bool
    {
        return $this->is_new !== null;
    }

    /**
     * Did this wallet transact at a time the chain query returned no time for.
     *
     * Such a wallet belongs in no activity window. It transacted, so it counts in the
     * headline, but nothing places it inside or outside thirty days, and leaving it in a
     * window's denominator would count it as a wallet that was watched and did nothing.
     *
     * Asked separately from the delegation question below, and never folded into it. The
     * two are different events with different timestamps: one untimed transaction used to
     * hold the wallet out of the delegation window as well, which reported a delegation
     * that was read and timed as one that could not be placed.
     */
    public function activityTimingUnknown(): bool
    {
        return (int) $this->activity_count > 0 && $this->first_activity_seconds === null;
    }

    /** Did this wallet delegate at a time the chain query returned no time for. */
    public function delegationTimingUnknown(): bool
    {
        return (int) $this->delegation_events > 0 && $this->first_delegation_seconds === null;
    }

    /**
     * Could this wallet have been watched for a whole window of $days.
     *
     * Measured against when the run read the chain, not against now. A campaign analysed
     * in July gives the same answer in December, because what it saw did not change; only
     * a new run can change it.
     */
    public function observableFor(int $days): bool
    {
        return $this->isWindowed()
            && $this->observed_seconds !== null
            && $this->observed_seconds >= $days * self::DAY;
    }

    /**
     * Did this wallet do something of its own within $days of claiming.
     *
     * The boundary is inclusive: a transaction exactly thirty days after the claim is
     * inside the thirty-day window.
     */
    public function activeWithin(int $days): bool
    {
        return $this->first_activity_seconds !== null
            && $this->first_activity_seconds <= $days * self::DAY;
    }

    /** Did this wallet submit a stake certificate within $days of claiming. */
    public function delegatedWithin(int $days): bool
    {
        return $this->first_delegation_seconds !== null
            && $this->first_delegation_seconds <= $days * self::DAY;
    }
}

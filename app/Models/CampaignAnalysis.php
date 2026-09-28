<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The state and headline results of the most recent onboarding analysis for a campaign.
 */
class CampaignAnalysis extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETE = 'complete';

    /**
     * The run finished, and part of what it went to read did not answer.
     *
     * Distinct from complete because the difference is the whole point of the feature: the
     * wallets behind a failed read are unknown, not measured at nothing, and a result with
     * holes in it must not present itself as a reading of the campaign.
     */
    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'summary' => 'array',
        'unread_wallets' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'windowed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    /** Did the run finish, whether or not everything it went to read answered. */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETE, self::STATUS_PARTIAL], true);
    }

    /** Did part of the chain read fail, leaving wallets unknown rather than measured. */
    public function isPartial(): bool
    {
        return $this->status === self::STATUS_PARTIAL;
    }

    /**
     * Was this result produced by a run that could tell delegation from activity.
     *
     * A summary stored before that distinction existed holds an activation figure that
     * counted a wallet which had only delegated, so the panel has to know it is looking at
     * one and say the windows are unknown rather than print a zero.
     */
    public function isWindowed(): bool
    {
        return $this->windowed_at !== null;
    }
}

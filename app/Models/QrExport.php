<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One generated sticker archive.
 *
 * The row describes an artifact that already exists. It is written after a build succeeds
 * and never before, so nothing here has to be read as "might be there": either the archive
 * was made, or there is no row.
 *
 * Nothing about the run that produced it belongs here. Progress, failure and the worker's
 * heartbeat live on the campaign task, which is done in minutes, while this row is what the
 * operator downloads from for a week afterwards.
 */
class QrExport extends Model
{
    use HasFactory;
    use HasUlids;

    /** The archive is on the disk and can be downloaded. */
    public const STATUS_READY = 'ready';

    /**
     * The archive is gone from the disk.
     *
     * The row stays: it is the history of what was exported and the settings a regenerate
     * replays, and an operator who printed from it still has to be able to find out what it
     * was.
     */
    public const STATUS_EXPIRED = 'expired';

    protected $guarded = [];

    protected $casts = [
        'settings' => 'array',
        'manifest' => 'array',
        'bytes' => 'integer',
        'codes_total' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Whether the advertised expiry has passed.
     *
     * Advertised, not established. The archive is pruned on a schedule and a bucket
     * lifecycle rule may take it earlier or later, so the disk is what decides whether a
     * download works. This answers what the page can say without asking the disk.
     */
    public function hasExpired(?Carbon $now = null): bool
    {
        if ($this->status === self::STATUS_EXPIRED) {
            return true;
        }

        return $this->expires_at !== null && $this->expires_at->lte($now ?? now());
    }

    /** Record that the archive this row describes is no longer on the disk. */
    public function markExpired(): void
    {
        $this->forceFill([
            'status' => self::STATUS_EXPIRED,
            // The path described bytes that are gone. Leaving it would keep handing out a
            // location nothing answers at.
            'path' => null,
        ])->save();
    }
}

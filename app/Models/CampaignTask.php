<?php

namespace App\Models;

use App\Support\CampaignTaskTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One run of one background job on one campaign, in a state an operator can read.
 *
 * The row is a run, not a result. What a run produced belongs in the feature's own table,
 * because the two have different lifetimes: an export outlives the job that built it.
 */
class CampaignTask extends Model
{
    use HasFactory;
    use HasUlids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    /** A run that has not finished. The poller keeps asking while any of these exist. */
    public const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    /** A run that has finished, either way. */
    public const TERMINAL_STATUSES = [self::STATUS_COMPLETE, self::STATUS_FAILED];

    /** One run per campaign per type, which is what most jobs want. */
    public const DEFAULT_KEY = 'default';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'checkpoint' => 'array',
        'progress_done' => 'integer',
        'progress_total' => 'integer',
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /**
     * Take ownership of a run, or refuse.
     *
     * Dispatch is a claim rather than a create, because the row is the lock. A caller that
     * gets a task back may dispatch the job; a caller that gets null must not, and the
     * reason is already on screen.
     *
     * A finished or failed run can be claimed again: regenerating an expired export and
     * re-running an analysis are both exactly that, and putting the rule in a click handler
     * instead would mean every caller reimplementing it. A run that must never happen twice
     * is stopped by its dedupe key, not by its state.
     *
     * A run whose worker stopped reporting is claimed too. Without that, a worker killed
     * mid-job wedges its campaign for that job type forever.
     *
     * The affected-row count is what makes this safe: two requests arriving together find
     * one row, and only one of them changes it.
     */
    public static function claim(
        Campaign|string $campaign,
        string $type,
        string $dedupeKey = self::DEFAULT_KEY,
        array $payload = [],
        ?int $requestedBy = null,
        ?int $staleAfterSeconds = null,
    ): ?self {
        $campaignId = $campaign instanceof Campaign ? $campaign->id : $campaign;

        $task = static::firstOrCreate(
            [
                'campaign_id' => $campaignId,
                'type' => $type,
                'dedupe_key' => $dedupeKey,
            ],
            [
                'status' => self::STATUS_QUEUED,
                'payload' => $payload,
                'requested_by' => $requestedBy,
                'progress_done' => 0,
                // Set at creation, so a row is never briefly indistinguishable from one
                // whose worker has died.
                'heartbeat_at' => now(),
            ],
        );

        if ($task->wasRecentlyCreated) {
            return $task;
        }

        $cutoff = now()->subSeconds($staleAfterSeconds ?? static::staleAfterSeconds());

        $claimed = DB::table('campaign_tasks')
            ->where('id', $task->id)
            ->where(static function ($query) use ($cutoff) {
                $query->whereIn('status', self::TERMINAL_STATUSES)
                    ->orWhere(static function ($query) use ($cutoff) {
                        $query->whereIn('status', self::ACTIVE_STATUSES)
                            ->where(static function ($query) use ($cutoff) {
                                $query->whereNull('heartbeat_at')
                                    ->orWhere('heartbeat_at', '<', $cutoff);
                            });
                    });
            })
            ->update([
                'status' => self::STATUS_QUEUED,
                'stage' => null,
                'progress_done' => 0,
                // Cleared with the numerator: a denominator left over from the previous run
                // would describe work this one is not doing.
                'progress_total' => null,
                // The new run's options, so a re-run with different settings runs the new
                // ones rather than replaying what the row happened to hold.
                'payload' => json_encode($payload),
                'requested_by' => $requestedBy,
                // The task's own copy of the result. What the previous run produced lives in
                // the feature's table and stays on screen while this one is in flight.
                'result' => null,
                // Where the previous run got to, which this one is not entitled to resume
                // from: it was claimed with its own payload, and a cursor into work done
                // under different settings would put half of one export inside another.
                'checkpoint' => null,
                'error' => null,
                'started_at' => null,
                'completed_at' => null,
                'heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        return $claimed === 1 ? $task->refresh() : null;
    }

    /**
     * How long a run may go without a heartbeat before it is treated as dead.
     *
     * Derived from the longest job timeout in the application plus a grace period rather
     * than picked on its own: the worker kills the job at its timeout, so silence for
     * longer than the timeout plus the grace means nothing is running.
     */
    public static function staleAfterSeconds(): int
    {
        return (int) config('cardano.tasks.stale_after_seconds', 1020);
    }

    /** Whether this run's worker has stopped reporting. */
    public function isStale(?Carbon $now = null): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        $cutoff = ($now ?? now())->copy()->subSeconds(static::staleAfterSeconds());

        return $this->heartbeat_at === null || $this->heartbeat_at->lt($cutoff);
    }

    /**
     * What the poller sends the browser. Deliberately small: the page asks for this every
     * few seconds, and everything it carries is something the panel actually renders.
     */
    public function toPollPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'stage' => $this->stage,
            'progress_done' => (int) $this->progress_done,
            'progress_total' => $this->progress_total,
            'error' => $this->error,
            'result' => $this->result,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            // The Inertia props whose values this run's completion changes. The client
            // reloads exactly these, once, instead of reloading the whole page.
            'reloads' => CampaignTaskTypes::reloads($this->type),
        ];
    }
}

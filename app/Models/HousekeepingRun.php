<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The bookkeeping for one housekeeping task. See App\Support\Housekeeping.
 *
 * Not soft-deleted: a row is keyed uniquely on its task, and a trashed row would still
 * hold that key while reading as absent.
 */
class HousekeepingRun extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $casts = [
        'last_run_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'last_failed_at' => 'datetime',
        'failures' => 'integer',
        'locked_until' => 'datetime',
    ];
}

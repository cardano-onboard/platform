<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The catalogue of distinct user agent strings seen on an accepted claim.
 *
 * Scoped to no campaign and no claim, which is what lets it hold the raw string at all. Its
 * job is to keep an unrecognised client identifiable later: the tally records only the
 * shorthand, and a shorthand alone forecloses ever working out what "unknown" was.
 *
 * No timestamps and no surrogate key, deliberately. See the migration for why.
 */
class ClaimUserAgent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'claim_user_agents';

    protected $primaryKey = 'fingerprint';

    protected $keyType = 'string';

    protected $guarded = [];
}

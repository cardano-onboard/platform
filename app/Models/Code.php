<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Code extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'partner_id',
        'code',
        'reference',
        'perWallet',
        'uses',
        'lovelace',
        'nmkr_project_uid',
        'nmkr_count_nft',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'campaign_id',
        // The integrator's own identifier for whoever a code-API code was made for
        // (an attendee, a station, a claim slot). Nothing on the campaign page shows it,
        // and it is not this application's to hand to anyone who can merely view the
        // campaign. Reachable only through the endpoints that already deal in it by hand:
        // CodeApiController::store(), which returns the code it names rather than
        // the row, and the code's own attribute access everywhere else in the app.
        'reference',
    ];

    protected $withCount = [
        'rewards',
        'claims',
    ];

    /**
     * A cryptographically-random claim code using Crockford base32 (uppercase, minus
     * the ambiguous I/L/O/U so it's easy to read and type). Each code is INDEPENDENTLY
     * random — deliberately not a monotonic ULID, whose batch values are sequentially
     * related and therefore guessable from a single leaked code. ~5 bits/char, so the
     * 20-char default is ~100 bits of entropy. Length does not affect QR density (the
     * claim URL dominates the payload), so it's chosen purely for guess-resistance.
     */
    public static function generateCode(int $length = 20): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Generate a claim code unique WITHIN the given campaign. Uniqueness is scoped per
     * campaign (not global) so separate SaaS tenants can never collide with — or block —
     * each other; the composite unique index (campaign_id, code) is the backstop. Codes
     * are only ever looked up together with their campaign, so this scope is sufficient.
     */
    public static function generateUniqueCode(string $campaignId, int $length = 20): string
    {
        do {
            $code = static::generateCode($length);
        } while (static::where('campaign_id', $campaignId)->where('code', $code)->exists());

        return $code;
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * Who this code was generated for, if anybody.
     *
     * Assigned once, when the code is generated, and never afterwards. A code attributed to
     * a partner after the fact was not necessarily handed out by them, and a denominator
     * that can be edited after the numerator is visible is not a denominator.
     *
     * Not in $hidden, so the campaign page can name the partner a code was generated for:
     * the name is the operator's own, not a claimant's.
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    /**
     * How many assets a claim on this code delivers.
     *
     * What a claim costs is priced against this, because it is what actually varies: a
     * second asset drags extra minimum UTxO into the same output, and that is a fraction
     * of a claim rather than another whole one.
     *
     * Minted NFTs count. They arrive in the same parcel and cost the same minimum UTxO as
     * any other asset, whoever produced them.
     */
    public function assetCount(): int
    {
        $rewards = $this->relationLoaded('rewards')
            ? $this->rewards->count()
            : $this->rewards()->count();

        return $rewards + (int) ($this->nmkr_count_nft ?? 0);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }
}

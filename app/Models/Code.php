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
        'code',
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

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }
}

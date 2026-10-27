<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reward extends Model
{
    use HasFactory;

    /** A policy id is a 28-byte blake2b-224 hash: exactly 56 hex characters. */
    public const POLICY_HEX = '/^[0-9a-fA-F]{56}$/';

    /** An asset name is at most 32 bytes: an even number of hex characters, up to 64. */
    public const ASSET_HEX = '/^([0-9a-fA-F]{2}){0,32}$/';

    protected $fillable = [
        'code_id',
        'policy_hex',
        'asset_hex',
        'quantity',
        'policy_id',
        'metadata',
        'is_minted',
    ];

    protected $hidden = [
        'is_minted',
        'policy_id',
        'metadata',
        'created_at',
        'updated_at',
    ];

    public function code(): BelongsTo
    {
        return $this->belongsTo(Code::class);
    }
}

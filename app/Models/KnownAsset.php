<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnownAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticker',
        'name',
        'policy_id',
        'asset_name',
        'fingerprint',
        'decimals',
        'logo',
        'description',
        'network',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'decimals' => 'integer',
    ];

    protected $appends = [
        'subject',
    ];

    /**
     * The asset subject (policy id + asset name hex) used by Koios and registries.
     */
    public function getSubjectAttribute(): string
    {
        return $this->policy_id.$this->asset_name;
    }
}

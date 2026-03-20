<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Code extends Model {

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

    public function campaign(): BelongsTo {
        return $this->belongsTo(Campaign::class);
    }

    public function rewards(): HasMany {
        return $this->hasMany(Reward::class);
    }

    public function claims(): HasMany {
        return $this->hasMany(Claim::class);
    }

}

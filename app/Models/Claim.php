<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Claim extends Model
{
    use HasFactory;

    protected $fillable = [
        'code_id',
        'address',
        'stake_key',
        'transaction_id',
        'transaction_hash',
        'status',
        'retry_count',
        'nmkr_mint_status',
        'nmkr_mint_body',
    ];

    protected $hidden = [
        'deleted_at',
        'created_at',
        'updated_at',
    ];

    public function code(): BelongsTo {
        return $this->belongsTo(Code::class);
    }

    public function campaign(): HasOneThrough {
        return $this->hasOneThrough(Campaign::class, Code::class);
    }
}

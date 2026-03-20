<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reward extends Model {

    use HasFactory;

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
        "is_minted",
        "policy_id",
        "metadata",
        "created_at",
        "updated_at",
    ];

    public function code(): BelongsTo {
        return $this->belongsTo(Code::class);
    }
}

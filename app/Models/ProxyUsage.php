<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProxyUsage extends Model
{
    public $timestamps = false;

    protected $table = 'proxy_usage';

    protected $fillable = [
        'user_id',
        'endpoint',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

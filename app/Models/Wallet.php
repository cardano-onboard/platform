<?php

namespace App\Models;

use App\Contracts\TransactionBackend;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'address',
        'key',
        'backend',
        'skey',
        'vkey',
        'wrapping_key_version',
        'recovery_record',
    ];

    protected $casts = [
        // The record a recovery reads when there is no application left to derive it with, so it
        // is held as data rather than as a string somebody has to know the shape of.
        'recovery_record' => 'array',
    ];

    protected $hidden = [
        'key',
        'backend',
        'skey',
        'vkey',
        'wrapping_key_version',
        'recovery_record',
        'deleted_at',
        'created_at',
        'updated_at',
    ];

    public $balance = [];

    /**
     * Resolve the TransactionBackend that created this wallet.
     * Always uses the wallet's stored backend, not the current global setting.
     */
    public function resolveBackend(): TransactionBackend
    {
        return AppServiceProvider::resolveBackend($this->backend ?? 'phyrhose');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}

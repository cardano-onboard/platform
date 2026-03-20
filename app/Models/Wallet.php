<?php

namespace App\Models;

use App\Contracts\TransactionBackend;
use App\Providers\AppServiceProvider;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class Wallet extends Model {

    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'address',
        'key',
        'backend',
        'skey',
        'vkey',
    ];

    protected $hidden = [
        'key',
        'backend',
        'skey',
        'vkey',
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

    public function getBalance(): array {
        switch ($this->campaign->network) {
            case 'mainnet':
                $phyrhose = Http::mainnet_phyrhose();
                break;
            case 'preprod':
                $phyrhose = Http::preprod_phyrhose();
                break;
            default:
                throw new Exception("Unknown network!");
        }
        if (empty($this->balance)) {
            $balance = (object)$phyrhose->get('/firehose/queryLiveUtxos', [
                'address' => $this->address,
                'era'     => 'ALONZO',
            ])
                                        ->json();

//            dd($balance->data[1]['liveUtxos']);
            if (isset($balance->status) && $balance->status === "ok") {
                $this->balance = $balance->data[1]['liveUtxos'];
            }
        }

        return $this->balance;
    }

    public function campaign(): BelongsTo {
        return $this->belongsTo(Campaign::class);
    }
}

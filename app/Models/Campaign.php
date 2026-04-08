<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Http;

class Campaign extends Model {

    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'start_date',
        'end_date',
        'one_per_wallet',
        'network',
        'txn_msg',
        'nmkr_api_key',
    ];

    protected $hidden = [
        'deleted_at',
        'created_at',
        'updated_at',
    ];

    public $balance = [];

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function wallet(): HasOne {
        return $this->hasOne(Wallet::class);
    }

    public function codes(): HasMany {
        return $this->hasMany(Code::class);
    }

    public function rewards(): HasManyThrough {
        return $this->through('codes')->has('rewards');
    }

    public function claims(): HasManyThrough {
        return $this->hasManyThrough(Claim::class, Code::class);
    }

//    public function balance(): Collection {
//        if (empty($this->balance)) {
//            $balance = (object)Http::phyrhose()
//                                   ->get('/firehose/queryLiveUtxos', [
//                                       'address' => $this->wallet->address,
//                                       'era'     => 'ALONZO',
//                                   ])
//                                   ->json();
//
//            if ($balance->status === "ok") {
//                $this->balance = new Collection($balance->data[1]['liveUtxos']);
//            }
////            dd($this->balance);
//        }
//
//        return $this->balance;
//    }
}

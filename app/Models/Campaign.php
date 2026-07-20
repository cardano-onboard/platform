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
use Illuminate\Support\Facades\Route;

class Campaign extends Model
{
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

    protected $appends = [
        'status',
    ];

    public $balance = [];

    /**
     * Campaign status based on current date relative to start/end dates.
     *
     * @return string active|ended|upcoming|draft
     */
    public function getStatusAttribute(): string
    {
        if (! $this->start_date || ! $this->end_date) {
            return 'draft';
        }

        $now = now()->startOfDay();
        $start = \Carbon\Carbon::parse($this->start_date)->startOfDay();
        $end = \Carbon\Carbon::parse($this->end_date)->endOfDay();

        if ($now->lt($start)) {
            return 'upcoming';
        }

        if ($now->gt($end)) {
            return 'ended';
        }

        return 'active';
    }

    /** Whether the campaign's redemption window has closed (claims are rejected past this). */
    public function hasEnded(): bool
    {
        return $this->status === 'ended';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(Code::class);
    }

    public function rewards(): HasManyThrough
    {
        return $this->through('codes')->has('rewards');
    }

    public function claims(): HasManyThrough
    {
        return $this->hasManyThrough(Claim::class, Code::class);
    }

    /**
     * Canonical claim endpoint URL for this campaign — the short subdomain route
     * (https://<claim_domain>/v1/{campaign}) when a claim domain is configured,
     * otherwise the default /api/claim/v1/{campaign} route. Used for the displayed
     * claim URL and the QR deep-link payload so both follow the same source of truth.
     */
    public function claimUrl(): string
    {
        // Prefer the short subdomain route, but only if it is actually registered.
        // The claim.v1.short route is registered conditionally on claim_domain, and on
        // Vapor a build-time route cache can omit it even when config resolves the domain
        // at runtime — so guard on Route::has() to degrade to the always-registered long
        // route instead of throwing RouteNotFoundException on a core page.
        return config('cardano.claim_domain') && Route::has('claim.v1.short')
            ? route('claim.v1.short', $this)
            : route('claim.v1', $this);
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
    // //            dd($this->balance);
    //        }
    //
    //        return $this->balance;
    //    }
}

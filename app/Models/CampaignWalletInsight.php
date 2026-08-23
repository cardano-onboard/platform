<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One claimant wallet's onboarding classification for a campaign.
 *
 * `is_new` means the wallet had no on-chain history before its claim, so the campaign is
 * what put it on chain. `activated` means it later INITIATED a transaction of its own —
 * it has to appear as an input, because a wallet that merely receives another payout has
 * not done anything.
 */
class CampaignWalletInsight extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_new' => 'boolean',
        'activated' => 'boolean',
        'delegated' => 'boolean',
        'is_operator' => 'boolean',
        'claimed_at' => 'datetime',
        'analyzed_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}

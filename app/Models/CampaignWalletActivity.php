<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One post-claim transaction a claimant wallet was seen in, as one run observed it.
 *
 * The per-wallet insight row carries totals and cannot answer a thirty-day question
 * afterwards, so this is where the time each transaction landed is kept, together with the
 * certificates the wallet submitted in it.
 *
 * `is_activity` and `is_delegation` are both recorded because a transaction can be either,
 * both or neither. A wallet that registers a stake key and delegates is an input of that
 * transaction and has still done nothing with its tokens; a wallet that delegates and pays
 * somebody in the same transaction has done both.
 */
class CampaignWalletActivity extends Model
{
    use HasFactory;

    protected $table = 'campaign_wallet_activity';

    protected $guarded = [];

    protected $casts = [
        'certificate_types' => 'array',
        'self_initiated' => 'boolean',
        'is_delegation' => 'boolean',
        'is_activity' => 'boolean',
        'script_interaction' => 'boolean',
        'occurred_at' => 'datetime',
        'observed_at' => 'datetime',
        'seconds_after_claim' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}

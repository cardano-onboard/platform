<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    /**
     * What this claim was charged and what it paid out, stamped when each happened.
     *
     * Absent from $fillable on purpose. These are money columns and nothing a claimant
     * sends may reach them, so they are written with forceFill at the one place a claim
     * is created and the one place a payment is submitted.
     *
     * The charge is stamped at creation from the rate then in force. The reward is stamped
     * at fulfilment from the bundle the payment was actually built out of, so a later edit
     * to the code applies forward and leaves this alone.
     */
    protected $casts = [
        'revenue_lovelace' => 'integer',
        'revenue_usd' => 'decimal:6',
        'reward_lovelace' => 'integer',
        'reward_tokens' => 'array',
    ];

    /**
     * What this claim was paid, or null where that was never recorded.
     *
     * Null is returned rather than the code's current reward. A claim that has not been
     * sent yet has not been paid anything, and one taken before rewards were recorded has
     * an answer nobody wrote down. Reading today's code in either case would report a
     * configuration as a payment.
     *
     * @return array{lovelace: int, tokens: array<int, array{policy: ?string, asset: ?string, quantity: int}>}|null
     */
    public function rewardPaid(): ?array
    {
        if ($this->reward_lovelace === null) {
            return null;
        }

        return [
            'lovelace' => (int) $this->reward_lovelace,
            'tokens' => (array) ($this->reward_tokens ?? []),
        ];
    }

    /**
     * Claims that were handed to the transaction backend and can still gain a hash.
     *
     * One definition, used everywhere the number is counted or acted on: the status
     * check itself, the control that triggers it, and the onboarding analysis that is
     * blind to anything not in this state. Two copies of this filter would drift, and
     * the symptom would be a panel offering to check claims that nothing ever checks.
     *
     * Columns are qualified because the campaign reaches claims through codes, and an
     * unqualified `status` is ambiguous across that join.
     */
    public function scopeAwaitingConfirmation(Builder $query): Builder
    {
        return $query->whereNotNull('claims.transaction_id')
            ->whereNull('claims.transaction_hash')
            ->whereNotIn('claims.status', ['failed', 'completed']);
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(Code::class);
    }

    public function campaign(): HasOneThrough
    {
        return $this->hasOneThrough(Campaign::class, Code::class);
    }
}

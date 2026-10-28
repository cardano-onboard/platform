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
        'retry_after' => 'datetime',
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

    /**
     * Claims that have not been handed to the transaction backend and should be.
     *
     * Held claims are left out, because sending one would deliver a reward the campaign has
     * not paid for. Failed and completed claims are left out, because neither is waiting
     * to be sent whatever its transaction id says.
     */
    public function scopeAwaitingSubmission(Builder $query): Builder
    {
        return $query->whereNull('claims.transaction_id')
            ->whereNull('claims.held_reason')
            ->whereNotIn('claims.status', ['failed', 'completed']);
    }

    /**
     * Claims awaiting submission whose retry backoff, if they have one, has run out.
     */
    public function scopeDueForSubmission(Builder $query): Builder
    {
        return $query->awaitingSubmission()
            ->where(fn (Builder $query) => $query->whereNull('claims.retry_after')
                ->orWhere('claims.retry_after', '<=', now()));
    }

    /**
     * The same test as scopeAwaitingConfirmation, for a claim already loaded. Kept near it
     * so the two are read, and changed, together.
     */
    public function isAwaitingConfirmation(): bool
    {
        return $this->transaction_id !== null
            && $this->transaction_hash === null
            && ! in_array($this->status, ['failed', 'completed'], true);
    }

    /**
     * Claims whose transaction hash is a real one: present, not empty, and not the stand-in
     * the null backend writes. A real hash is only ever written from the backend's completed
     * answer or the chain, so a claim with one has been paid.
     */
    public function scopeHasRealTransactionHash(Builder $query): Builder
    {
        return $query->whereNotNull('claims.transaction_hash')
            ->where('claims.transaction_hash', '!=', '')
            ->where('claims.transaction_hash', 'not like', 'null-tx-%');
    }

    /**
     * The claims scopeHasRealTransactionHash leaves out.
     */
    public function scopeLacksRealTransactionHash(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNull('claims.transaction_hash')
            ->orWhere('claims.transaction_hash', '')
            ->orWhere('claims.transaction_hash', 'like', 'null-tx-%'));
    }

    /**
     * Records that the transaction backend confirmed this claim's transaction: the hash and
     * the completed status together, so a claim never holds one without the other. Every
     * place a hash is learned goes through here, because a claim with a hash leaves
     * awaitingConfirmation() and nothing revisits it to fix a status left behind.
     *
     * A blank hash is refused and nothing is written. The backend's completed answer that
     * carries no hash goes through markCompletedWithoutHash() instead.
     */
    public function markConfirmed(?string $transactionHash): bool
    {
        if ($transactionHash === null || trim($transactionHash) === '') {
            return false;
        }

        $this->transaction_hash = $transactionHash;
        $this->status = 'completed';
        $this->save();

        return true;
    }

    /**
     * Records a completed answer from the backend that carried no transaction hash. The
     * transaction was paid, so the claim is final: left waiting, a later timeout answer would
     * clear its transaction id and send the reward again. The hash stays null, and the
     * caller says so in the log.
     */
    public function markCompletedWithoutHash(): void
    {
        $this->status = 'completed';
        $this->save();
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

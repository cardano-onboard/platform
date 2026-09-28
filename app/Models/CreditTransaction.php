<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of credit, and the only thing a balance is made of.
 *
 * The balance is the sum of these rows rather than a column beside them, so it cannot
 * drift out of agreement with the movements that explain it, and "why is the balance
 * what it is" stays answerable a year later.
 *
 * A grant is positive and carries the price the credits were sold at. A debit is
 * negative and carries the grant it drew from, because credits bought in different packs
 * were bought at different prices and a debit that did not say which ones it spent would
 * make the value consumed unknowable.
 */
class CreditTransaction extends Model
{
    use HasUlids;

    public const KIND_GRANT = 'grant';

    public const KIND_DEBIT = 'debit';

    public const KIND_ADJUSTMENT = 'adjustment';

    /** One credit, in the millionths everything here is counted in. */
    public const MICRO = 1_000_000;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'delta_micro' => 'integer',
            'unit_price_lovelace' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }

    /** The grant this debit spent, where it is one. */
    public function source(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_id');
    }
}

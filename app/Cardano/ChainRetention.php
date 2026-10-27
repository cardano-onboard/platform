<?php

namespace App\Cardano;

use App\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a campaign remembers about a transaction, written before it is sent.
 *
 * The order is the whole point. A worker killed between submitting and recording leaves money moved
 * with nothing pointing at it: the retry finds no record, builds a second transaction, and pays the
 * same claims again. Writing first inverts that. The retry finds the transaction, offers the same
 * bytes again, and the chain takes them once however many times they are offered, because a
 * transaction is identified by its hash and a hash is of its bytes.
 *
 * So only a rebuild can pay twice, and this exists to make sure nothing ever rebuilds where it
 * could resubmit.
 *
 * The claims a transaction paid are kept here as well, append-only. The claims table has its own
 * transaction id and that column is cleared when a payment is rebuilt, so it answers what is
 * outstanding rather than what has already gone out. Settlement needs the second question, and a
 * column that forgets cannot answer it.
 */
final class ChainRetention
{
    /**
     * Record a transaction and what it pays, before anybody tries to submit it.
     *
     * A payout that collects platform fees names each claim's fee and ordinal, which are written
     * with the transaction and stamped on the claim as its platform fee in the same step, and the
     * fees output it spent and created. A claim's stamp is therefore never ahead of the record of
     * the payout it belongs to, nor behind it.
     *
     * @param  array<int, array{hash: string, index: int}>  $inputs
     * @param  array<int, array{claim_id: int, lovelace: int, fee_lovelace?: int, ordinal?: int}>  $claims
     * @param  ?array{input: ?string, output_index: int}  $fees
     */
    public function record(
        Campaign $campaign,
        string $txHash,
        string $signedCbor,
        array $inputs,
        int $validityEnd,
        array $claims,
        ?array $fees = null,
    ): void {
        DB::transaction(function () use ($campaign, $txHash, $signedCbor, $inputs, $validityEnd, $claims, $fees) {
            $now = CarbonImmutable::now();

            // Ignored rather than updated where it already exists, because a retry that reaches
            // here is re-recording what it already wrote and the stored bytes are the authority.
            // Overwriting them with a fresh build is the rebuild this whole table exists to stop.
            // The same bytes built again after being refused hash the same, and are being offered
            // again on purpose. The finished mark belonged to the earlier offer, so it comes off,
            // or nothing would build on this transaction if it lands this time.
            $revived = DB::table('campaign_transactions')
                ->where('tx_hash', $txHash)
                ->where('failure', 'rejected')
                ->update(['failed_at' => null, 'failure' => null, 'updated_at' => $now]);

            $inserted = DB::table('campaign_transactions')->insertOrIgnore([
                'campaign_id' => $campaign->id,
                'sequence' => $this->nextSequence($campaign),
                'tx_hash' => $txHash,
                // Hex, because the column is text and the bytes are not: MySQL refuses bytes that
                // are not valid in the column's character set, and a transaction that cannot be
                // recorded cannot be sent.
                'signed_cbor' => bin2hex($signedCbor),
                'inputs_consumed' => json_encode($inputs, JSON_THROW_ON_ERROR),
                'fees_input' => $fees['input'] ?? null,
                'fees_output_index' => $fees['output_index'] ?? null,
                'validity_end' => $validityEnd,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Written with the transaction and not again: a retry re-recording it has these already.
            // Its claims lost their stamps when the earlier offer was marked finished, so a
            // transaction taken back into service has them put back.
            if ($inserted === 0) {
                if ($revived > 0) {
                    $this->stamp($claims);
                }

                return;
            }

            foreach ($claims as $claim) {
                DB::table('campaign_transaction_claims')->insert([
                    'tx_hash' => $txHash,
                    'claim_id' => $claim['claim_id'],
                    'lovelace' => $claim['lovelace'],
                    'fee_lovelace' => $claim['fee_lovelace'] ?? null,
                    'ordinal' => $claim['ordinal'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->stamp($claims);
        });
    }

    /**
     * The platform fee on each claim of a payout, stamped as the claim goes into it.
     *
     * A claim is stamped once, with exactly the figure its payout collects: the column holds one
     * value, so there is nothing to add to, and a payout that replaces a failed one overwrites it.
     *
     * @param  array<int, array{claim_id: int, fee_lovelace?: int}>  $claims
     */
    private function stamp(array $claims): void
    {
        foreach ($claims as $claim) {
            if (isset($claim['fee_lovelace'])) {
                DB::table('claims')->where('id', $claim['claim_id'])->update(['revenue_lovelace' => $claim['fee_lovelace']]);
            }
        }
    }

    /**
     * The campaign's unconfirmed transactions, split into those that are finished and those that
     * are still real.
     *
     * A transaction is finished when it failed, or when anything it spends came from one that is
     * finished: nothing built on a transaction that will never land can land. That reading is
     * shared by whatever asks whether a transaction can still land, so the count of paid claims
     * and the outputs a new payout may build on cannot disagree about it.
     *
     * @return array{array<string, true>, list<object>} the hashes of the finished ones, and the rows still real
     */
    public function unconfirmedByFate(Campaign $campaign): array
    {
        $open = DB::table('campaign_transactions')
            ->where('campaign_id', $campaign->id)
            ->whereNull('confirmed_at')
            ->orderBy('sequence')
            ->get(['tx_hash', 'sequence', 'confirmed_at', 'failed_at', 'inputs_consumed', 'fees_input', 'fees_output_index']);

        $finished = [];
        $real = [];

        foreach ($open as $row) {
            // Its parents are the payouts whose change it spends and the payout whose fees output
            // it spends. It is dead if it failed or if either kind of parent is dead. One that
            // merely left the mempool, with its window still open, is resubmitted from its stored
            // bytes and is not dead, so what is built on it stays live.
            $feesParent = $row->fees_input === null ? null : explode('#', (string) $row->fees_input)[0];
            $changeParents = array_map(
                static fn (array $input): string => (string) $input['hash'],
                array_filter(
                    json_decode((string) $row->inputs_consumed, true, 512, JSON_THROW_ON_ERROR),
                    static fn (array $input): bool => $row->fees_input !== $input['hash'].'#'.$input['index'],
                ),
            );

            if ($row->failed_at !== null
                || array_intersect($changeParents, array_keys($finished)) !== []
                || ($feesParent !== null && isset($finished[$feesParent]))) {
                $finished[$row->tx_hash] = true;

                continue;
            }

            $real[] = $row;
        }

        return [$finished, $real];
    }

    /**
     * Mark finished every transaction built on one that is, so its claims are free to be paid again.
     *
     * A transaction whose parent can never land cannot land either, whatever its own window says.
     * Its claims go back to the queue as soon as the parent is marked, not when the child's window
     * passes, and the stamps its payout gave them go with it.
     *
     * @return int how many were marked
     */
    public function finishDead(Campaign $campaign): int
    {
        [$finished] = $this->unconfirmedByFate($campaign);
        $marked = 0;

        foreach (DB::table('campaign_transactions')->where('campaign_id', $campaign->id)->whereNull('confirmed_at')->whereNull('failed_at')->whereIn('tx_hash', array_keys($finished))->pluck('tx_hash') as $hash) {
            $this->markFailed($hash, 'parent');
            $marked++;
        }

        return $marked;
    }

    /**
     * What the campaign's chain says about its platform fees: how many of its claims have paid one,
     * and which transaction made its current fees output.
     *
     * Counted over the transactions that are still real. Confirmed ones are. An unconfirmed one is
     * real until it is finished, and it is not real if anything it spends came from a transaction
     * that is finished, because nothing built on a transaction that will never land can land. A
     * claim in such a payout has not paid, and counting it would give the claims behind it
     * ordinals that skip the one it held when it is paid again.
     *
     * @return array{paid: int, latest: ?object}
     */
    public function feesState(Campaign $campaign): array
    {
        [, $real] = $this->unconfirmedByFate($campaign);

        $hashes = array_map(static fn (object $row): string => (string) $row->tx_hash, $real);

        $paid = DB::table('campaign_transaction_claims as paid')
            ->join('campaign_transactions as tx', 'tx.tx_hash', '=', 'paid.tx_hash')
            ->where('tx.campaign_id', $campaign->id)
            ->whereNotNull('paid.fee_lovelace')
            ->where(static fn ($query) => $query->whereNotNull('tx.confirmed_at')->orWhereIn('tx.tx_hash', $hashes))
            ->count();

        $latest = DB::table('campaign_transactions')
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('confirmed_at')
            ->whereNotNull('fees_output_index')
            ->orderByDesc('sequence')
            ->first(['tx_hash', 'sequence', 'confirmed_at', 'fees_input', 'fees_output_index']);

        foreach ($real as $row) {
            if ($row->fees_output_index !== null && ($latest === null || $row->sequence > $latest->sequence)) {
                $latest = $row;
            }
        }

        return ['paid' => $paid, 'latest' => $latest];
    }

    /**
     * Every transaction this campaign recorded as paying any of these claims.
     *
     * The question a worker asks before building anything: is somebody in this batch already
     * being paid? Whether each answer is still live, confirmed, in flight or finished, is for the
     * caller to decide.
     *
     * @param  list<int>  $claimIds
     * @return array<int, list<object>> keyed by claim id, oldest transaction first
     */
    public function linkedTo(Campaign $campaign, array $claimIds): array
    {
        if ($claimIds === []) {
            return [];
        }

        // Only the columns the caller decides on. The signed bytes stay in their table.
        $rows = DB::table('campaign_transaction_claims as paid')
            ->join('campaign_transactions as tx', 'tx.tx_hash', '=', 'paid.tx_hash')
            ->where('tx.campaign_id', $campaign->id)
            ->whereIn('paid.claim_id', $claimIds)
            ->orderBy('tx.sequence')
            ->get(['paid.claim_id', 'tx.tx_hash', 'tx.confirmed_at', 'tx.failed_at']);

        $linked = [];

        foreach ($rows as $row) {
            $linked[(int) $row->claim_id][] = $row;
        }

        return $linked;
    }

    /**
     * The transactions that may still land, oldest first: not confirmed, not finished, and inside
     * their validity. These are what a new payment builds on.
     *
     * @return list<object>
     */
    public function inFlight(Campaign $campaign, int $currentSlot): array
    {
        return DB::table('campaign_transactions')
            ->where('campaign_id', $campaign->id)
            ->whereNull('confirmed_at')
            ->whereNull('failed_at')
            ->where('validity_end', '>', $currentSlot)
            ->orderBy('sequence')
            ->get()
            ->all();
    }

    /**
     * Every transaction not yet confirmed and not finished, oldest first, without its bytes.
     *
     * @return list<object>
     */
    public function open(Campaign $campaign): array
    {
        return DB::table('campaign_transactions')
            ->where('campaign_id', $campaign->id)
            ->whereNull('confirmed_at')
            ->whereNull('failed_at')
            ->orderBy('sequence')
            ->get(['tx_hash', 'validity_end'])
            ->all();
    }

    /**
     * Record that a transaction will never land: refused by the node, or past its validity with
     * the chain never having seen it. Nothing builds on it afterwards, and the claims it would
     * have paid are free to be paid by another.
     */
    public function markFailed(string $txHash, string $reason): void
    {
        DB::transaction(function () use ($txHash, $reason) {
            $finished = DB::table('campaign_transactions')
                ->where('tx_hash', $txHash)
                ->whereNull('confirmed_at')
                ->whereNull('failed_at')
                ->update([
                    'failed_at' => CarbonImmutable::now(),
                    'failure' => $reason,
                    'updated_at' => CarbonImmutable::now(),
                ]);

            // The fees these claims were stamped with were never collected, so the stamp goes: the
            // payout that replaces this one stamps them afresh, and until then they have paid
            // nothing. Only where this call finished the transaction, so one already finished, whose
            // claims may be in a payout of their own by now, leaves them alone.
            if ($finished > 0) {
                DB::table('claims')
                    ->whereIn('id', DB::table('campaign_transaction_claims')
                        ->where('tx_hash', $txHash)
                        ->whereNotNull('fee_lovelace')
                        ->select('claim_id'))
                    // Only claims still bound to this transaction or to none. One that another
                    // worker has since bound to a payout of its own is being paid by that payout,
                    // and keeps what that payout stamped on it.
                    ->where(static fn ($query) => $query->whereNull('transaction_id')->orWhere('transaction_id', $txHash))
                    ->update(['revenue_lovelace' => null]);
            }
        });
    }

    /**
     * The exact bytes a recorded transaction was signed as, ready to offer to the chain again.
     */
    public static function signedBytes(object $row): string
    {
        $bytes = hex2bin((string) $row->signed_cbor);

        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException(sprintf('The stored bytes for %s are not readable.', $row->tx_hash));
        }

        return $bytes;
    }

    /**
     * The bytes of one recorded transaction, read only when it is about to be offered again.
     */
    public function bytesOf(string $txHash): string
    {
        return self::signedBytes(DB::table('campaign_transactions')->where('tx_hash', $txHash)->first(['tx_hash', 'signed_cbor']));
    }

    public function markConfirmed(string $txHash): void
    {
        DB::table('campaign_transactions')
            ->where('tx_hash', $txHash)
            ->update(['confirmed_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);
    }

    private function nextSequence(Campaign $campaign): int
    {
        return ((int) DB::table('campaign_transactions')
            ->where('campaign_id', $campaign->id)
            ->max('sequence')) + 1;
    }
}

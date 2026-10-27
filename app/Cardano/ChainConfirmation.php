<?php

namespace App\Cardano;

use App\Models\Campaign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Finding out what the chain did with a campaign's transactions.
 *
 * Three answers are possible and they are not symmetrical.
 *
 * Confirmed, meaning the chain has it and has built on it. Depth matters here: a transaction in the
 * most recent block can still be rolled back, and a campaign's transactions form a chain, so a
 * rollback takes everything built on top of it too. Waiting for a few blocks costs a minute and
 * saves reconciling a tail.
 *
 * Expired, meaning the chain is past the last slot the transaction could have been accepted in. The
 * important word is provably: nothing is inferred from silence. A transaction that is merely slow
 * and one that will never be accepted look identical until its validity interval passes, which is
 * the whole reason every transaction carries one.
 *
 * And pending, which is neither, and is the answer most of the time.
 */
final class ChainConfirmation
{
    public function __construct(
        private readonly ChainData $chain,
        private readonly ChainRetention $retention,
        private readonly ChainStatusReader $status,
        private readonly ChainSubmitter $submitter,
    ) {}

    /**
     * Offer a recorded transaction to the chain again, without letting the answer decide anything.
     *
     * The chain takes the same bytes once however often they are offered. Spent inputs mean this
     * landed or something else spent them, and a refusal of a transaction that may already be in a
     * block is not a refusal of the payment.
     */
    private function offerAgain(Campaign $campaign, string $network, string $txHash): void
    {
        try {
            $this->submitter->submit($network, $this->retention->bytesOf($txHash), $txHash);
        } catch (\Throwable $e) {
            Log::info('A campaign transaction offered again was not taken.', [
                'campaign_id' => $campaign->id,
                'tx_hash' => $txHash,
                'answer' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A transaction was refused because an input of it is already spent. Whether it can still land
     * is settled by two reads, in this order.
     *
     * First an input of it that is not the output of one of our own transactions still open: if the
     * provider says that is spent, somebody spent it. Then the transaction itself: if the provider
     * does not know it, it did not do the spending. The order is the point. Read the other way, a
     * provider that moved on between the reads could be asked about the transaction before it
     * landed and about the input after, and call a transaction dead that had landed. Read this way,
     * the most it can say is that ours landed. It waits for nothing.
     *
     * Such a transaction is finished, with everything built on it, in every campaign's chain it
     * belongs to, so their claims are free to be paid again at once.
     *
     * @param  list<string>  $refs  what it spends, as hash#index
     * @return bool whether it is dead, and has been marked so
     */
    public function settleRefusedInputs(string $network, string $hash, array $refs): bool
    {
        $ours = [$hash => true];

        foreach (DB::table('campaign_transactions')->whereNull('confirmed_at')->whereNull('failed_at')->pluck('tx_hash') as $open) {
            $ours[$open] = true;
        }

        $spent = false;

        foreach ($refs as $ref) {
            [$parent, $index] = explode('#', $ref);

            if (! isset($ours[$parent]) && $this->chain->outputIsSpent($network, $parent, (int) $index)) {
                $spent = true;

                break;
            }
        }

        if (! $spent || isset($this->status->depths($network, [$hash])[$hash])) {
            return false;
        }

        $campaigns = DB::table('campaign_transactions')->where('tx_hash', $hash)->pluck('campaign_id');
        $this->retention->markFailed($hash, 'inputs spent');

        foreach (Campaign::whereIn('id', $campaigns)->get() as $campaign) {
            $this->retention->finishDead($campaign);
        }

        return true;
    }

    /**
     * Bring a campaign's record of its transactions up to date with the chain.
     *
     * @return array{confirmed: int, expired: int, pending: int}
     */
    public function reconcile(Campaign $campaign, string $network): array
    {
        $unconfirmed = $this->retention->open($campaign);

        if ($unconfirmed === []) {
            return ['confirmed' => 0, 'expired' => 0, 'pending' => 0];
        }

        $tip = $this->chain->tip($network);
        $depths = $this->status->depths($network, array_column($unconfirmed, 'tx_hash'));
        $required = max(1, (int) config('campaign_script.confirmation_depth', 3));

        $counts = ['confirmed' => 0, 'expired' => 0, 'pending' => 0];

        foreach ($unconfirmed as $row) {
            $depth = $depths[$row->tx_hash] ?? null;

            if ($depth !== null && $depth >= $required) {
                $this->retention->markConfirmed($row->tx_hash);
                $counts['confirmed']++;

                continue;
            }

            // Only where the chain has moved past the last slot this could have been accepted in,
            // and only where the chain has not seen it. A transaction the chain holds is confirmed
            // whatever its validity said, because the interval bounds when it may be accepted and
            // not how long it stays accepted.
            if ($depth === null && (int) $row->validity_end <= $tip['slot']) {
                $counts['expired']++;

                // Finished: it can never be accepted now. Recorded so that nothing builds on its
                // outputs, the claims it would have paid can be paid by another, and it stops being
                // asked about.
                $this->retention->markFailed($row->tx_hash, 'expired');

                Log::warning('A campaign transaction expired without being accepted.', [
                    'campaign_id' => $campaign->id,
                    'tx_hash' => $row->tx_hash,
                    'validity_end' => (int) $row->validity_end,
                    'tip' => $tip['slot'],
                ]);

                continue;
            }

            // Unseen and still inside its validity: offered again, once. A submission that was
            // lost on the way, or that reached a node without its parent, takes this way. The
            // answer decides nothing; the next reconciliation reads what the chain did with it.
            if ($depth === null) {
                $this->offerAgain($campaign, $network, $row->tx_hash);
            }

            $counts['pending']++;
        }

        // What was built on a transaction that has just been given up can never land either.
        $this->retention->finishDead($campaign);

        // A tail is not independent. Each transaction spends the one before it, so an expiry part
        // way down means nothing behind it can ever be accepted either, whatever its own validity
        // says. Reported rather than acted on, because rebuilding a tail is a decision with money
        // in it and belongs to whatever is watching this rather than to the reconciliation.
        if ($counts['expired'] > 0 && $counts['pending'] > 0) {
            Log::error('A campaign chain has expired transactions with others queued behind them.', [
                'campaign_id' => $campaign->id,
                'expired' => $counts['expired'],
                'stranded' => $counts['pending'],
            ]);
        }

        return $counts;
    }
}

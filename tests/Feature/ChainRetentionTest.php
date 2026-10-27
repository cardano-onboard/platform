<?php

namespace Tests\Feature;

use App\Cardano\ChainRetention;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Remembering a transaction before sending it, and what that buys.
 */
class ChainRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(): Campaign
    {
        return Campaign::factory()->create(['network' => 'preprod']);
    }

    private function claim(Campaign $campaign): Claim
    {
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        return Claim::factory()->create(['code_id' => $code->id]);
    }

    private function retention(): ChainRetention
    {
        return new ChainRetention;
    }

    /**
     * Bytes that are not text, as a signed transaction's are. A string that happens to be valid
     * UTF-8 would pass through a text column on every database and prove nothing about storing a
     * real transaction.
     */
    private static function bytesFor(string $hash): string
    {
        return "\x84\xa4\x00\xff\xfe".hex2bin($hash);
    }

    private function record(Campaign $campaign, string $hash, int $validityEnd = 200_000_000, array $claims = []): void
    {
        $this->retention()->record(
            $campaign,
            $hash,
            self::bytesFor($hash),
            [['hash' => str_repeat('f', 64), 'index' => 0]],
            $validityEnd,
            $claims,
        );
    }

    public function test_a_transaction_is_remembered_with_the_bytes_that_were_signed(): void
    {
        $campaign = $this->campaign();
        $this->record($campaign, str_repeat('a', 64));

        $row = DB::table('campaign_transactions')->where('tx_hash', str_repeat('a', 64))->first();

        $this->assertNotNull($row);
        $this->assertSame(self::bytesFor(str_repeat('a', 64)), ChainRetention::signedBytes($row));
        $this->assertSame(1, (int) $row->sequence);
    }

    /**
     * Recording the same transaction again keeps the bytes that were already stored.
     *
     * A retry reaching here is re-recording what it wrote before, and the stored bytes are the
     * authority. Overwriting them with a fresh build is exactly the rebuild this table exists to
     * prevent, because a rebuild is the only thing that can pay twice.
     */
    public function test_recording_the_same_transaction_twice_does_not_replace_the_stored_bytes(): void
    {
        $campaign = $this->campaign();
        $hash = str_repeat('a', 64);

        $this->record($campaign, $hash);

        $this->retention()->record(
            $campaign, $hash, "\x00DIFFERENT", [], 200_000_000, [],
        );

        $rows = DB::table('campaign_transactions')->where('tx_hash', $hash)->get();

        $this->assertCount(1, $rows);
        $this->assertSame(self::bytesFor($hash), ChainRetention::signedBytes($rows->first()));
    }

    /**
     * What a worker builds on: transactions that may still land. Unexpired, unconfirmed and not
     * finished, oldest first.
     */
    public function test_only_transactions_that_may_still_land_are_in_flight(): void
    {
        $campaign = $this->campaign();
        $this->record($campaign, str_repeat('a', 64), validityEnd: 200_000_000);
        $this->record($campaign, str_repeat('b', 64), validityEnd: 100);
        $this->record($campaign, str_repeat('c', 64), validityEnd: 200_000_000);
        $this->record($campaign, str_repeat('d', 64), validityEnd: 200_000_000);
        $this->record($campaign, str_repeat('e', 64), validityEnd: 200_000_000);

        $this->retention()->markConfirmed(str_repeat('c', 64));
        $this->retention()->markFailed(str_repeat('d', 64), 'rejected');

        $this->assertSame(
            [str_repeat('a', 64), str_repeat('e', 64)],
            array_column($this->retention()->inFlight($campaign, 199_000_000), 'tx_hash'),
        );
    }

    /**
     * What a transaction paid is found again by the claim it paid.
     */
    public function test_a_payment_is_found_again_by_claim(): void
    {
        $campaign = $this->campaign();
        $claim = $this->claim($campaign);

        $this->record($campaign, str_repeat('a', 64), claims: [
            ['claim_id' => $claim->id, 'lovelace' => 1_500_000],
        ]);

        $linked = $this->retention()->linkedTo($campaign, [$claim->id]);

        $this->assertCount(1, $linked[$claim->id]);
        $this->assertSame(str_repeat('a', 64), $linked[$claim->id][0]->tx_hash);

        // And only within the campaign that recorded it.
        $this->assertSame([], $this->retention()->linkedTo($this->campaign(), [$claim->id]));
    }

    /**
     * Only the columns a caller decides on are read, never the signed bytes, and only for the
     * claims asked about. A shared code has thousands of payments and each would carry its
     * transaction.
     */
    public function test_looking_a_payment_up_reads_neither_the_bytes_nor_other_claims(): void
    {
        $campaign = $this->campaign();
        $claim = $this->claim($campaign);
        $other = Claim::factory()->create(['code_id' => $claim->code_id]);

        $this->record($campaign, str_repeat('a', 64), claims: [
            ['claim_id' => $claim->id, 'lovelace' => 1_500_000],
            ['claim_id' => $other->id, 'lovelace' => 1_500_000],
        ]);

        $linked = $this->retention()->linkedTo($campaign, [$claim->id]);

        $this->assertSame([$claim->id], array_keys($linked));
        $this->assertObjectNotHasProperty('signed_cbor', $linked[$claim->id][0]);
    }

    /**
     * Sequence numbers count up per campaign, so a stored tail replays in the order it was built.
     */
    public function test_sequence_counts_up_within_a_campaign(): void
    {
        $campaign = $this->campaign();
        $other = $this->campaign();

        $this->record($campaign, str_repeat('a', 64));
        $this->record($campaign, str_repeat('b', 64));
        $this->record($other, str_repeat('c', 64));

        $this->assertSame(1, (int) DB::table('campaign_transactions')->where('tx_hash', str_repeat('a', 64))->value('sequence'));
        $this->assertSame(2, (int) DB::table('campaign_transactions')->where('tx_hash', str_repeat('b', 64))->value('sequence'));
        $this->assertSame(1, (int) DB::table('campaign_transactions')->where('tx_hash', str_repeat('c', 64))->value('sequence'));
    }

    /**
     * What is still open is every transaction not confirmed and not finished, oldest first.
     */
    public function test_open_lists_what_is_neither_confirmed_nor_finished(): void
    {
        $campaign = $this->campaign();

        foreach (['a', 'b', 'c'] as $letter) {
            $this->record($campaign, str_repeat($letter, 64));
        }

        $this->retention()->markConfirmed(str_repeat('a', 64));
        $this->retention()->markFailed(str_repeat('c', 64), 'expired');

        $this->assertSame([str_repeat('b', 64)], array_column($this->retention()->open($campaign), 'tx_hash'));
    }
}

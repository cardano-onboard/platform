<?php

namespace Tests\Feature;

use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClaimStatusChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A claim that learns its transaction hash is completed at the same moment.
 *
 * A claim with a hash leaves the set the status check revisits, so a status left behind when
 * the hash was written stays behind for good.
 */
class ClaimConfirmedStatusTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = 'addr1qxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9pf488uagw68fv50kjxv3wrx38829tay6zszthnccsradgqwt4upy';

    private const HASH = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private function campaign(): Campaign
    {
        $campaign = Campaign::factory()->for(User::factory()->create())->create([
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'one_per_wallet' => false,
        ]);
        Wallet::factory()->for($campaign)->create(['backend' => 'phyrhose']);

        return $campaign;
    }

    /** What Phyrhose answers about a purchase; read afresh by every request. */
    private mixed $purchaseAnswer = null;

    /**
     * @param  array<string, mixed>|null  $purchase  the purchase as Phyrhose describes it
     */
    private function phyrhoseAnswers(?array $purchase): void
    {
        $this->purchaseAnswer = ['status' => 'ok', 'data' => [null, $purchase]];

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'purchaseStatus')) {
                return $this->purchaseAnswer instanceof \Illuminate\Http\Client\Response
                    ? $this->purchaseAnswer
                    : Http::response($this->purchaseAnswer);
            }

            return Http::response([]);
        });
    }

    /** @param  array<string, mixed>|null  $purchase */
    private function phyrhoseNowSays(?array $purchase): void
    {
        $this->purchaseAnswer = ['status' => 'ok', 'data' => [null, $purchase]];
    }

    /**
     * Claims through the endpoint, then repeats it with the claim sent as purchase 4242.
     *
     * @param  array<string, int>  $limits
     * @return array{0: Campaign, 1: Code, 2: \Illuminate\Testing\TestResponse}
     */
    private function repeatClaim(array $limits, ?string $expectedHash = '', array $claimState = []): array
    {
        Queue::fake();
        $campaign = $this->campaign();
        $code = Code::factory()->for($campaign)->create($limits + ['lovelace' => 2_000_000]);
        $claim = fn () => $this->postJson(route('claim.v1', $campaign), [
            'code' => $code->code,
            'address' => self::ADDRESS,
        ]);

        $claim()->assertJson(['status' => 'accepted']);
        Queue::fake();
        $code->claims()->sole()->forceFill(['transaction_id' => '4242'] + $claimState)->save();

        $response = $claim();
        $response->assertJson(['status' => 'claimed', 'tx_hash' => $expectedHash]);

        return [$campaign, $code, $response];
    }

    /** @return array<string, array<int, array<string, int>>> */
    public static function repeatPaths(): array
    {
        return [
            'per-wallet path' => [['uses' => 10, 'perWallet' => 1]],
            'exhausted-code path' => [['uses' => 1, 'perWallet' => 1]],
        ];
    }

    #[DataProvider('repeatPaths')]
    public function test_a_repeat_request_completes_a_confirmed_claim(array $limits): void
    {
        $this->phyrhoseAnswers(['status' => 'completed', 'txId' => self::HASH]);

        [, $code] = $this->repeatClaim($limits, self::HASH);
        $claim = $code->claims()->sole();

        $this->assertSame(self::HASH, $claim->transaction_hash);
        $this->assertSame('completed', $claim->status);
    }

    #[DataProvider('repeatPaths')]
    public function test_a_completed_answer_without_a_hash_is_final_and_a_later_timeout_cannot_resend_it(array $limits): void
    {
        $this->phyrhoseAnswers(['status' => 'completed']);
        Log::spy();

        [$campaign, $code] = $this->repeatClaim($limits);
        $claim = $code->claims()->sole();

        $this->assertSame('completed', $claim->status);
        $this->assertNull($claim->transaction_hash);
        $this->assertSame('4242', $claim->transaction_id);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'completed without a transaction hash'))
            ->once();

        // Phyrhose now contradicts itself. The claim was paid, so it must not be sent again.
        $this->phyrhoseNowSays(['status' => 'timeout']);
        Queue::fake();

        $this->postJson(route('claim.v1', $campaign), ['code' => $code->code, 'address' => self::ADDRESS])
            ->assertJson(['status' => 'claimed', 'tx_hash' => '']);

        Queue::assertNotPushed(ProcessClaims::class);
        $this->assertSame('4242', $claim->fresh()->transaction_id);
        $this->assertSame('completed', $claim->fresh()->status);
    }

    #[DataProvider('repeatPaths')]
    public function test_a_failed_claim_the_backend_reports_completed_is_completed_and_not_sent_again(array $limits): void
    {
        $this->phyrhoseAnswers(['status' => 'completed', 'txId' => self::HASH]);

        [$campaign, $code] = $this->repeatClaim($limits, self::HASH, ['status' => 'failed', 'retry_count' => 5]);
        $claim = $code->claims()->sole();

        $this->assertSame('completed', $claim->status);
        $this->assertSame(self::HASH, $claim->transaction_hash);

        $this->artisan('claims:retry-failed', ['campaign' => $campaign->id])
            ->expectsOutput('No failed claims to reset.')
            ->assertSuccessful();

        $this->assertSame('4242', $claim->fresh()->transaction_id);
        $this->assertSame('completed', $claim->fresh()->status);
    }

    #[DataProvider('repeatPaths')]
    public function test_a_timeout_answer_never_touches_a_failed_claim(array $limits): void
    {
        $this->phyrhoseAnswers(['status' => 'timeout']);

        [, $code] = $this->repeatClaim($limits, '', ['status' => 'failed', 'retry_count' => 5]);
        $claim = $code->claims()->sole();

        $this->assertSame('failed', $claim->status);
        $this->assertSame('4242', $claim->transaction_id);
        Queue::assertNotPushed(ProcessClaims::class);
    }

    #[DataProvider('repeatPaths')]
    public function test_a_timeout_answer_still_resends_a_claim_that_is_waiting(array $limits): void
    {
        $this->phyrhoseAnswers(['status' => 'timeout']);

        [, $code] = $this->repeatClaim($limits);

        $this->assertNull($code->claims()->sole()->transaction_id);
        Queue::assertPushed(ProcessClaims::class, 1);
    }

    public function test_the_status_check_treats_completed_without_a_hash_as_final(): void
    {
        $campaign = $this->campaign();
        $claim = Claim::factory()->for(Code::factory()->for($campaign))->withTransaction()->create(['status' => 'pending']);
        $transactionId = $claim->transaction_id;
        $this->phyrhoseAnswers(['status' => 'completed']);
        Log::spy();

        $stats = app(ClaimStatusChecker::class)->check($campaign);

        $this->assertSame(1, $stats['completed']);
        $this->assertSame('completed', $claim->fresh()->status);
        $this->assertNull($claim->fresh()->transaction_hash);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'completed without a transaction hash'))
            ->once();

        $this->phyrhoseNowSays(['status' => 'timeout']);
        Queue::fake();

        $again = app(ClaimStatusChecker::class)->check($campaign);

        $this->assertSame(0, $again['checked']);
        Queue::assertNotPushed(ProcessClaims::class);
        $this->assertSame($transactionId, $claim->fresh()->transaction_id);
    }

    public function test_the_status_check_still_completes_a_confirmed_claim(): void
    {
        $campaign = $this->campaign();
        $claim = Claim::factory()->for(Code::factory()->for($campaign))->withTransaction()->create(['status' => 'pending']);
        $this->phyrhoseAnswers(['status' => 'completed', 'txId' => self::HASH]);

        $stats = app(ClaimStatusChecker::class)->check($campaign);

        $this->assertSame(1, $stats['completed']);
        $this->assertSame(self::HASH, $claim->fresh()->transaction_hash);
        $this->assertSame('completed', $claim->fresh()->status);
    }

    public function test_mark_confirmed_refuses_a_blank_hash(): void
    {
        $claim = Claim::factory()->for(Code::factory()->for($this->campaign()))->withTransaction()->create(['status' => 'pending']);

        foreach ([null, '', '  '] as $blank) {
            $this->assertFalse($claim->markConfirmed($blank));
        }

        $this->assertSame('pending', $claim->fresh()->status);
        $this->assertNull($claim->fresh()->transaction_hash);
    }

    #[DataProvider('repeatPaths')]
    public function test_an_unreadable_phyrhose_answer_gets_the_same_reply_on_both_repeat_paths(array $limits): void
    {
        $answer = null;
        Http::fake(function (Request $request) use (&$answer) {
            return str_contains($request->url(), 'purchaseStatus') ? $answer : Http::response([]);
        });

        foreach ([
            'a gateway error with no JSON' => Http::response('Bad Gateway', 502),
            'ok with no purchase in the data' => Http::response(['status' => 'ok', 'data' => [null]]),
            'a purchase with no status' => Http::response(['status' => 'ok', 'data' => [null, ['foo' => 1]]]),
        ] as $label => $response) {
            $answer = $response;
            Claim::query()->delete();
            Code::query()->delete();

            [, $code, $reply] = $this->repeatClaim($limits);

            $reply->assertJson(['code' => 202, 'status' => 'claimed', 'tx_hash' => '']);
            $this->assertSame('pending', $code->claims()->sole()->status, $label);
            $this->assertSame('4242', $code->claims()->sole()->transaction_id, $label);
        }
    }

    public function test_the_backfill_completes_exactly_the_claims_that_have_a_real_hash(): void
    {
        $code = Code::factory()->for($this->campaign())->create();
        $make = fn (array $attributes) => Claim::factory()->for($code)->create($attributes + ['transaction_id' => '77'])->id;

        $stuck = $make(['transaction_hash' => self::HASH, 'status' => 'pending']);
        $stuckToo = $make(['transaction_hash' => strrev(self::HASH), 'status' => 'pending']);
        $nullTx = $make(['transaction_hash' => 'null-tx-'.str_repeat('a', 64), 'status' => 'pending']);
        $empty = $make(['transaction_hash' => '', 'status' => 'pending']);
        $noHash = $make(['transaction_hash' => null, 'status' => 'pending']);
        $failedPaid = $make(['transaction_hash' => self::HASH, 'status' => 'failed']);
        $failedNullTx = $make(['transaction_hash' => 'null-tx-'.str_repeat('b', 64), 'status' => 'failed']);
        $failedNoHash = $make(['transaction_hash' => null, 'status' => 'failed']);
        $done = $make(['transaction_hash' => self::HASH, 'status' => 'completed']);

        $migration = $this->backfill();
        $migration->up();

        $statuses = Claim::pluck('status', 'id');
        $this->assertSame('completed', $statuses[$stuck]);
        $this->assertSame('completed', $statuses[$stuckToo]);
        $this->assertSame('pending', $statuses[$nullTx]);
        $this->assertSame('pending', $statuses[$empty]);
        $this->assertSame('pending', $statuses[$noHash]);
        $this->assertSame('completed', $statuses[$failedPaid]);
        $this->assertSame('failed', $statuses[$failedNullTx]);
        $this->assertSame('failed', $statuses[$failedNoHash]);
        $this->assertSame('completed', $statuses[$done]);

        // Running it again changes nothing, and its down() leaves the data alone.
        $before = Claim::pluck('status', 'id');
        $migration->up();
        $migration->down();
        $this->assertEquals($before, Claim::pluck('status', 'id'));
    }

    public function test_the_backfill_covers_more_than_one_chunk(): void
    {
        $code = Code::factory()->for($this->campaign())->create();
        Claim::factory()->count(5)->for($code)->create(['transaction_id' => '77', 'transaction_hash' => self::HASH, 'status' => 'pending']);

        $migration = $this->backfill();
        $migration->chunk = 2;
        $migration->up();

        $this->assertSame(5, Claim::where('status', 'completed')->count());
    }

    public function test_each_chunk_continues_after_the_last_id_of_the_one_before(): void
    {
        $code = Code::factory()->for($this->campaign())->create();
        $ids = Claim::factory()->count(5)->for($code)->create(['transaction_id' => '77', 'transaction_hash' => self::HASH, 'status' => 'pending'])->pluck('id');

        $selects = [];
        DB::listen(function ($query) use (&$selects) {
            if (str_starts_with($query->sql, 'select')) {
                $selects[] = $query->bindings;
            }
        });

        $migration = $this->backfill();
        $migration->chunk = 2;
        $migration->up();

        // Three chunks of 2, 2 and 1, then the select that finds nothing left.
        $this->assertCount(4, $selects);
        $this->assertContains($ids[1], $selects[1], 'the second chunk did not start after the first one ended');
        $this->assertContains($ids[3], $selects[2], 'the third chunk did not start after the second one ended');
        $this->assertContains($ids[4], $selects[3], 'the last select did not start after the last claim');
    }

    public function test_a_claim_changed_between_the_read_and_the_update_is_not_overwritten(): void
    {
        $code = Code::factory()->for($this->campaign())->create();
        $claim = Claim::factory()->for($code)->create(['transaction_id' => '77', 'transaction_hash' => self::HASH, 'status' => 'pending']);

        $changed = false;
        DB::listen(function ($query) use (&$changed, $claim) {
            if (! $changed && str_starts_with($query->sql, 'select')) {
                $changed = true;
                DB::table('claims')->where('id', $claim->id)->update(['status' => 'held']);
            }
        });

        $this->backfill()->up();

        $this->assertTrue($changed);
        $this->assertSame('held', $claim->fresh()->status);
    }

    public function test_the_command_completes_the_same_claims_and_a_second_run_does_nothing(): void
    {
        $code = Code::factory()->for($this->campaign())->create();
        $paid = Claim::factory()->for($code)->create(['transaction_id' => '77', 'transaction_hash' => self::HASH, 'status' => 'pending']);
        $paidThenFailed = Claim::factory()->for($code)->create(['transaction_id' => '78', 'transaction_hash' => self::HASH, 'status' => 'failed']);
        $nullTx = Claim::factory()->for($code)->create(['transaction_id' => '79', 'transaction_hash' => 'null-tx-'.str_repeat('c', 64), 'status' => 'pending']);
        $unpaid = Claim::factory()->for($code)->create(['transaction_id' => null, 'transaction_hash' => null, 'status' => 'failed']);

        $this->artisan('claims:complete-confirmed')->expectsOutput('2 claim(s) completed.')->assertSuccessful();

        $this->assertSame('completed', $paid->fresh()->status);
        $this->assertSame('completed', $paidThenFailed->fresh()->status);
        $this->assertSame('pending', $nullTx->fresh()->status);
        $this->assertSame('failed', $unpaid->fresh()->status);

        $this->artisan('claims:complete-confirmed')->expectsOutput('0 claim(s) completed.')->assertSuccessful();
    }

    public function test_the_command_dry_run_counts_and_writes_nothing(): void
    {
        $code = Code::factory()->for($this->campaign())->create();
        $paid = Claim::factory()->for($code)->create(['transaction_id' => '77', 'transaction_hash' => self::HASH, 'status' => 'failed']);
        Claim::factory()->for($code)->create(['transaction_id' => '78', 'transaction_hash' => self::HASH, 'status' => 'pending']);
        Claim::factory()->for($code)->create(['transaction_id' => '79', 'transaction_hash' => null, 'status' => 'pending']);

        $this->artisan('claims:complete-confirmed', ['--dry-run' => true])
            ->expectsOutput('2 claim(s) would be completed.')
            ->assertSuccessful();

        $this->assertSame('failed', $paid->fresh()->status);
        $this->assertSame(0, Claim::where('status', 'completed')->count());
    }

    public function test_the_migration_carries_its_own_queries(): void
    {
        $source = file_get_contents(database_path('migrations/2026_10_26_100000_complete_claims_that_have_a_transaction_hash.php'));

        foreach (['ClaimCompletionBackfill', 'hasRealTransactionHash', 'App\\Models'] as $dependency) {
            $this->assertStringNotContainsString($dependency, $source);
        }
    }

    private function backfill(): object
    {
        $path = collect(glob(database_path('migrations/*_complete_claims_that_have_a_transaction_hash.php')))->sole();

        return require $path;
    }
}

<?php

namespace Tests\Feature;

use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RetryFailedClaimsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campaign = $this->campaign();
    }

    private function campaign(): Campaign
    {
        $campaign = Campaign::factory()->for(User::factory())->create(['network' => 'preprod']);
        Wallet::factory()->for($campaign)->create(['key' => 'bucket', 'backend' => 'phyrhose']);

        return $campaign;
    }

    private function claim(?Campaign $campaign = null, array $attributes = []): Claim
    {
        $claim = Claim::factory()
            ->for(Code::factory()->for($campaign ?? $this->campaign)->create(['lovelace' => 2_000_000]))
            ->create();
        $claim->forceFill($attributes)->save();

        return $claim;
    }

    private function failed(?Campaign $campaign = null, array $attributes = []): Claim
    {
        return $this->claim($campaign, $attributes + [
            'status' => 'failed',
            'retry_count' => 5,
            'transaction_id' => 'purchase-timed-out',
            'retry_after' => now()->addMinutes(16),
        ]);
    }

    public function test_failed_claims_are_reset_and_queued_to_be_sent(): void
    {
        Queue::fake();

        $failed = [$this->failed(), $this->failed()];

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])
            ->expectsOutputToContain('2 failed claim(s) reset')
            ->assertSuccessful();

        foreach ($failed as $claim) {
            $claim->refresh();
            $this->assertSame('pending', $claim->status);
            $this->assertSame(0, (int) $claim->retry_count);
            $this->assertNull($claim->transaction_id);
            $this->assertNull($claim->retry_after);
        }

        Queue::assertPushed(ProcessClaims::class, 1);
        Queue::assertPushed(ProcessClaims::class, fn (ProcessClaims $job) => $job->campaign_id === $this->campaign->id);
    }

    public function test_a_failed_claim_that_carries_a_real_hash_was_paid_and_is_not_sent_again(): void
    {
        Queue::fake();

        $paid = $this->failed(null, ['transaction_hash' => str_repeat('ab', 32)]);
        $unpaid = $this->failed();
        $nullBackend = $this->failed(null, ['transaction_hash' => 'null-tx-'.str_repeat('c', 64)]);
        $blank = $this->failed(null, ['transaction_hash' => '']);

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id, '--dry-run' => true])
            ->expectsOutputToContain('3 failed claim(s) would be reset')
            ->assertSuccessful();

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])
            ->expectsOutputToContain('3 failed claim(s) reset')
            ->assertSuccessful();

        $this->assertSame('failed', $paid->fresh()->status);
        $this->assertSame('purchase-timed-out', $paid->fresh()->transaction_id);
        foreach ([$unpaid, $nullBackend, $blank] as $claim) {
            $this->assertSame('pending', $claim->fresh()->status);
            $this->assertNull($claim->fresh()->transaction_id);
        }
    }

    /**
     * @param  array<string, array<string, mixed>|\Throwable>  $answers  purchase id to its answer
     */
    private function phyrhoseSays(array $answers): void
    {
        Http::fake(function (Request $request) use ($answers) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $answer = $answers[$query['purchaseId'] ?? ''] ?? ['status' => 'processing'];

            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return Http::response(['status' => 'ok', 'data' => [null, $answer]]);
        });
    }

    public function test_a_failed_claim_the_backend_reports_paid_is_completed_instead_of_sent_again(): void
    {
        Queue::fake();
        $this->phyrhoseSays([
            '5001' => ['status' => 'completed', 'txId' => str_repeat('cd', 32)],
            '5002' => ['status' => 'completed'],
            '5003' => ['status' => 'timeout'],
        ]);

        $paid = $this->failed(null, ['transaction_id' => '5001']);
        $paidNoHash = $this->failed(null, ['transaction_id' => '5002']);
        $unpaid = $this->failed(null, ['transaction_id' => '5003']);

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])
            ->expectsOutputToContain('1 failed claim(s) reset')
            ->assertSuccessful();

        $this->assertSame('completed', $paid->fresh()->status);
        $this->assertSame(str_repeat('cd', 32), $paid->fresh()->transaction_hash);
        $this->assertSame('5001', $paid->fresh()->transaction_id);
        $this->assertSame('completed', $paidNoHash->fresh()->status);
        $this->assertNull($paidNoHash->fresh()->transaction_hash);
        $this->assertSame('5002', $paidNoHash->fresh()->transaction_id);
        $this->assertSame('pending', $unpaid->fresh()->status);
        $this->assertNull($unpaid->fresh()->transaction_id);
    }

    public function test_a_claim_the_backend_cannot_be_asked_about_is_not_reset(): void
    {
        Queue::fake();
        $this->phyrhoseSays(['5004' => new \Illuminate\Http\Client\ConnectionException('down')]);

        $claim = $this->failed(null, ['transaction_id' => '5004']);

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])->assertSuccessful();

        $this->assertSame('failed', $claim->fresh()->status);
        $this->assertSame('5004', $claim->fresh()->transaction_id);
        Queue::assertNothingPushed();
    }

    /** @return array<string, array{0: \Closure}> */
    public static function answersThatAreNotATimeout(): array
    {
        return [
            'a rate limit' => [fn () => Http::response(['message' => 'Too Many Requests'], 429)],
            'a gateway error with no JSON' => [fn () => Http::response('Bad Gateway', 502)],
            'a body that is not ok' => [fn () => Http::response(['status' => 'error', 'message' => 'nope'])],
            'processing' => [fn () => Http::response(['status' => 'ok', 'data' => [null, ['status' => 'processing']]])],
            'unknown' => [fn () => Http::response(['status' => 'ok', 'data' => [null, ['status' => 'unknown']]])],
            'failed' => [fn () => Http::response(['status' => 'ok', 'data' => [null, ['status' => 'failed']]])],
        ];
    }

    #[DataProvider('answersThatAreNotATimeout')]
    public function test_only_an_explicit_timeout_lets_a_failed_claim_be_sent_again(\Closure $answer): void
    {
        Queue::fake();
        Http::fake(fn (Request $request) => $answer());

        $claim = $this->failed(null, ['transaction_id' => '5201']);

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])->assertSuccessful();

        $this->assertSame('failed', $claim->fresh()->status);
        $this->assertSame('5201', $claim->fresh()->transaction_id);
        Queue::assertNothingPushed();
    }

    public function test_an_explicit_timeout_lets_a_failed_claim_be_sent_again(): void
    {
        Queue::fake();
        $this->phyrhoseSays(['5202' => ['status' => 'timeout']]);

        $claim = $this->failed(null, ['transaction_id' => '5202']);

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])
            ->expectsOutputToContain('1 failed claim(s) reset')
            ->assertSuccessful();

        $this->assertSame('pending', $claim->fresh()->status);
        $this->assertNull($claim->fresh()->transaction_id);
        Queue::assertPushed(ProcessClaims::class, 1);
    }

    public function test_a_processing_claim_is_left_failed_and_the_pass_goes_on(): void
    {
        Queue::fake();
        $this->phyrhoseSays(['5301' => ['status' => 'processing'], '5302' => ['status' => 'timeout']]);

        $processing = $this->failed(null, ['transaction_id' => '5301']);
        $timedOut = $this->failed(null, ['transaction_id' => '5302']);

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])
            ->expectsOutputToContain("Claim {$processing->id} was not reset: the backend says processing.")
            ->assertSuccessful();

        $this->assertSame('failed', $processing->fresh()->status);
        $this->assertSame('pending', $timedOut->fresh()->status);
    }

    public function test_the_pass_stops_at_the_first_trouble_and_leaves_the_rest_failed(): void
    {
        Queue::fake();
        $asked = collect();
        Http::fake(function (Request $request) use ($asked) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $asked->push($query['purchaseId']);

            return $query['purchaseId'] === '5402'
                ? Http::response(['message' => 'Too Many Requests'], 429)
                : Http::response(['status' => 'ok', 'data' => [null, ['status' => 'timeout']]]);
        });

        $first = $this->failed(null, ['transaction_id' => '5401']);
        $trouble = $this->failed(null, ['transaction_id' => '5402']);
        $after = [$this->failed(null, ['transaction_id' => '5403']), $this->failed(null, ['transaction_id' => '5404'])];

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])
            ->expectsOutputToContain('2 claim(s) were not asked and stay failed.')
            ->assertSuccessful();

        $this->assertSame(['5401', '5402'], $asked->all());
        $this->assertSame('pending', $first->fresh()->status);
        $this->assertSame('failed', $trouble->fresh()->status);
        foreach ($after as $claim) {
            $this->assertSame('failed', $claim->fresh()->status);
            $this->assertNotNull($claim->fresh()->transaction_id);
        }
    }

    public function test_a_dry_run_says_a_paid_claim_would_be_completed(): void
    {
        $this->phyrhoseSays(['5001' => ['status' => 'completed', 'txId' => str_repeat('cd', 32)]]);

        $paid = $this->failed(null, ['transaction_id' => '5001']);

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id, '--dry-run' => true])
            ->expectsOutputToContain("Claim {$paid->id} was paid, so it would be completed and not sent again.")
            ->assertSuccessful();

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])
            ->expectsOutputToContain("Claim {$paid->id} was paid, so it is completed and not sent again.")
            ->assertSuccessful();
    }

    public function test_a_dry_run_asks_the_backend_but_records_nothing(): void
    {
        $this->phyrhoseSays(['5001' => ['status' => 'completed', 'txId' => str_repeat('cd', 32)], '5003' => ['status' => 'timeout']]);

        $paid = $this->failed(null, ['transaction_id' => '5001']);
        $this->failed(null, ['transaction_id' => '5003']);

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id, '--dry-run' => true])
            ->expectsOutputToContain('1 failed claim(s) would be reset')
            ->assertSuccessful();

        $this->assertSame('failed', $paid->fresh()->status);
        $this->assertNull($paid->fresh()->transaction_hash);
    }

    /**
     * Held claims are released by a top-up, not by this. Claims that did not fail, and
     * failed claims on another campaign, are none of its business.
     */
    public function test_only_this_campaigns_failed_unheld_claims_are_touched(): void
    {
        Queue::fake();

        $held = $this->failed(null, ['held_reason' => 'no_credit']);
        $pending = $this->claim(null, ['status' => 'pending', 'transaction_id' => 'purchase-live', 'retry_count' => 2]);
        $completed = $this->claim(null, ['status' => 'completed', 'transaction_id' => 'purchase-done', 'transaction_hash' => 'hash']);
        $elsewhere = $this->failed($this->campaign());
        $failed = $this->failed();

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])
            ->expectsOutputToContain('1 failed claim(s) reset')
            ->assertSuccessful();

        $this->assertSame('pending', $failed->fresh()->status);

        $held->refresh();
        $this->assertSame('failed', $held->status);
        $this->assertSame('purchase-timed-out', $held->transaction_id);
        $this->assertSame(5, (int) $held->retry_count);

        $this->assertSame('purchase-live', $pending->fresh()->transaction_id);
        $this->assertSame(2, (int) $pending->fresh()->retry_count);
        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame('failed', $elsewhere->fresh()->status);
    }

    public function test_a_dry_run_reports_and_changes_nothing(): void
    {
        Queue::fake();

        $claim = $this->failed();

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id, '--dry-run' => true])
            ->expectsOutputToContain('1 failed claim(s) would be reset')
            ->assertSuccessful();

        $this->assertSame('failed', $claim->fresh()->status);
        $this->assertSame('purchase-timed-out', $claim->fresh()->transaction_id);
        Queue::assertNotPushed(ProcessClaims::class);
    }

    public function test_an_unknown_campaign_is_refused(): void
    {
        Queue::fake();

        $this->artisan('claims:retry-failed', ['campaign' => '01JZZZZZZZZZZZZZZZZZZZZZZZ'])
            ->expectsOutputToContain('No campaign has that ID.')
            ->assertFailed();

        Queue::assertNotPushed(ProcessClaims::class);
    }

    public function test_nothing_to_reset_queues_nothing(): void
    {
        Queue::fake();

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])
            ->expectsOutputToContain('No failed claims to reset.')
            ->assertSuccessful();

        Queue::assertNotPushed(ProcessClaims::class);
    }

    /**
     * End to end on the sync queue: the reset claim goes to the backend in the send run the
     * command queues, and comes back with a new transaction id.
     */
    public function test_a_reset_claim_is_sent_again(): void
    {
        $claim = $this->failed();

        Http::fake(function (Request $request) {
            $recipient = $request->data()['recipients'][0] ?? null;

            return Http::response(['status' => 'ok', 'data' => [null, ['items' => $recipient === null ? [] : [[
                'pooCode' => $recipient['pooCode'],
                'recipientAddress' => $recipient['address'],
                'purchaseId' => 'purchase-resent',
            ]]]]]);
        });
        Queue::fake([\App\Jobs\CheckClaims::class]);

        $this->artisan('claims:retry-failed', ['campaign' => $this->campaign->id])->assertSuccessful();

        $this->assertSame('purchase-resent', $claim->fresh()->transaction_id);
    }
}

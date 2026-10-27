<?php

namespace Tests\Feature;

use App\Jobs\CheckClaims;
use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClaimStatusChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Confirming claims without a scheduler.
 *
 * The transaction backend does not call back, so a claim's hash is only recorded when
 * something asks. Where no scheduler runs, the things that ask are a status request about
 * a claim still waiting, and the one check queued shortly after claims are sent. Both have
 * to reach the backend without letting a hall full of pollers turn into a request each.
 */
class ClaimCheckOnDemandTest extends TestCase
{
    use RefreshDatabase;

    private const WINDOW = 15;

    private User $user;

    private Campaign $campaign;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cardano.claim_check.debounce_seconds' => self::WINDOW]);

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create(['network' => 'preprod']);
        Wallet::factory()->for($this->campaign)->create(['backend' => 'phyrhose']);

        $this->token = $this->user->createToken('code-api', ['codes:status'])->plainTextToken;
    }

    private function waitingClaim(): Claim
    {
        $code = Code::factory()->for($this->campaign)->create();

        return Claim::factory()->for($code)->withTransaction()->create(['status' => 'pending']);
    }

    private function poll(Claim $claim)
    {
        return $this->withToken($this->token)
            ->getJson(route('api.codes.status', [$this->campaign, $claim->code->code]));
    }

    /**
     * Twenty attendees polling inside one window queue one check, and the first poll after
     * the window queues the next. The second half is what fails if the job's unique lock
     * outlives the window: nothing runs a faked job, so only the lock's own expiry lets the
     * next window's check through, as it would after a worker died holding it.
     */
    public function test_many_polls_in_one_window_queue_one_check_and_the_next_window_queues_another(): void
    {
        Queue::fake();

        $claims = collect(range(1, 20))->map(fn () => $this->waitingClaim());

        foreach ($claims as $claim) {
            $this->poll($claim)->assertOk()->assertJson(['transaction_hash' => null]);
        }

        Queue::assertPushed(CheckClaims::class, 1);

        $this->travel(self::WINDOW + 1)->seconds();

        foreach ($claims as $claim) {
            $this->poll($claim)->assertOk();
        }

        Queue::assertPushed(CheckClaims::class, 2);
        Queue::assertPushed(CheckClaims::class, fn (CheckClaims $job) => $job->campaign_id === $this->campaign->id
            && $job->task_id === null);
    }

    /**
     * With checks that actually run, the unique lock is released the moment each one
     * finishes, so it cannot be what keeps pollers from each reaching the backend. The
     * debounce is. Twenty polls in a window ask the backend about the claim once, the next
     * window asks again, and the poll after the check has found the hash carries it.
     */
    public function test_polls_that_run_their_check_still_reach_the_backend_once_per_window(): void
    {
        $claim = $this->waitingClaim();
        $asked = 0;
        $landed = false;

        Http::fake(function (Request $request) use (&$asked, &$landed) {
            if (! str_contains($request->url(), 'purchaseStatus')) {
                return Http::response([], 404);
            }

            $asked++;

            return Http::response([
                'status' => 'ok',
                'data' => [null, $landed
                    ? ['status' => 'completed', 'txId' => 'landedhash']
                    : ['status' => 'processing']],
            ]);
        });

        for ($i = 0; $i < 20; $i++) {
            $this->poll($claim)->assertOk()->assertJson(['transaction_hash' => null]);
        }

        $this->assertSame(1, $asked, 'twenty polls in one window should ask the backend once');

        $landed = true;
        $this->travel(self::WINDOW + 1)->seconds();

        // The poll that queues the check answers with what it knew before the check ran.
        $this->poll($claim)->assertOk();
        $this->assertSame(2, $asked);

        $this->poll($claim)->assertOk()->assertJson([
            'status' => 'completed',
            'transaction_hash' => 'landedhash',
        ]);
        $this->assertSame(2, $asked, 'a confirmed claim does not queue another check');
    }

    public function test_a_claim_not_yet_sent_or_already_confirmed_queues_nothing(): void
    {
        Queue::fake();

        $code = Code::factory()->for($this->campaign)->create();
        $unsent = Claim::factory()->for($code)->create();

        $landedCode = Code::factory()->for($this->campaign)->create();
        $landed = Claim::factory()->for($landedCode)->completed()->create();

        $this->poll($unsent)->assertOk();
        $this->poll($landed)->assertOk();

        Queue::assertNotPushed(CheckClaims::class);
    }

    public function test_each_campaign_gets_its_own_window(): void
    {
        Queue::fake();

        $claim = $this->waitingClaim();

        $other = Campaign::factory()->for($this->user)->create(['network' => 'preprod']);
        Wallet::factory()->for($other)->create(['backend' => 'phyrhose']);
        $otherClaim = Claim::factory()
            ->for(Code::factory()->for($other)->create())
            ->withTransaction()
            ->create(['status' => 'pending']);

        $this->poll($claim)->assertOk();
        $this->withToken($this->token)
            ->getJson(route('api.codes.status', [$other, $otherClaim->code->code]))
            ->assertOk();

        Queue::assertPushed(CheckClaims::class, 2);
        Queue::assertPushed(CheckClaims::class, fn (CheckClaims $job) => $job->campaign_id === $other->id);
    }

    /**
     * Claims that went to the backend get a check a little later, whether or not anybody
     * is watching. It is keyed on its chain, so a check already queued for the campaign,
     * which may run before this transaction is on chain, does not swallow it.
     */
    public function test_sending_claims_queues_one_delayed_check_that_a_queued_one_does_not_swallow(): void
    {
        Queue::fake();
        config(['queue.default' => 'database', 'cardano.claim_check.after_submit_seconds' => 40]);

        $code = Code::factory()->for($this->campaign)->create(['lovelace' => 2_000_000]);
        $claim = Claim::factory()->for($code)->create(['transaction_id' => null]);

        CheckClaims::dispatch($this->campaign->id);
        Queue::assertPushed(CheckClaims::class, 1);

        Http::fake([
            '*pooPurchaseOrder*' => Http::response([
                'status' => 'ok',
                'data' => [null, ['items' => [[
                    'pooCode' => $code->code,
                    'recipientAddress' => $claim->address,
                    'purchaseId' => 'purchase-123',
                ]]]],
            ]),
        ]);

        (new ProcessClaims($this->campaign->id))->handle();

        Queue::assertPushed(CheckClaims::class, 2);
        Queue::assertPushed(CheckClaims::class, fn (CheckClaims $job) => $job->chain_id !== null
            && $job->campaign_id === $this->campaign->id
            && $job->delay === 40);
    }

    public function test_a_submission_the_backend_refused_queues_no_check(): void
    {
        Queue::fake();

        $code = Code::factory()->for($this->campaign)->create();
        Claim::factory()->for($code)->create(['transaction_id' => null]);

        Http::fake(['*pooPurchaseOrder*' => Http::response(['status' => 'error'])]);

        (new ProcessClaims($this->campaign->id))->handle();

        Queue::assertNotPushed(CheckClaims::class);
    }

    /**
     * An unwatched pass asks about at most the configured number of claims, and it is the
     * ones that have waited longest. Created newest first, so insertion order and age
     * disagree and only an explicit ordering gets this right.
     */
    public function test_an_unwatched_pass_asks_about_the_oldest_claims_up_to_the_cap(): void
    {
        config(['cardano.claim_check.max_per_pass' => 2]);

        $ids = [];
        foreach ([1, 2, 3, 4, 5] as $minutesAgo) {
            $claim = $this->waitingClaim();
            $claim->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
            $ids[$minutesAgo] = $claim->transaction_id;
        }

        $asked = $this->recordStatusRequests();

        (new CheckClaims($this->campaign->id))->handle();

        $this->assertSame([$ids[5], $ids[4]], $asked->all());
    }

    /**
     * The Check claims button and the onboarding analysis need every claim answered, so
     * the cap is for unwatched passes only.
     */
    public function test_a_check_somebody_asked_for_is_not_capped(): void
    {
        config(['cardano.claim_check.max_per_pass' => 2]);

        foreach (range(1, 5) as $i) {
            $this->waitingClaim();
        }

        $asked = $this->recordStatusRequests();

        $task = CampaignTask::claim($this->campaign, CheckClaims::TASK_TYPE, CheckClaims::dedupeKey());
        (new CheckClaims($this->campaign->id, $task->id))->handle();

        $this->assertCount(5, $asked);

        $asked->splice(0);
        app(ClaimStatusChecker::class)->check($this->campaign);

        $this->assertCount(5, $asked);
    }

    /**
     * CIP-99 has no status endpoint: a wallet learns where its reward has got to by sending
     * the same claim again, and is answered 202 with the hash once there is one. So a repeat
     * about a claim that has been sent and not confirmed is a poll, and is debounced the
     * same way. A code with no uses left answers the repeat from one branch.
     */
    public function test_a_wallet_repeating_its_claim_on_a_used_up_code_queues_one_check_per_window(): void
    {
        $this->assertRepeatedClaimsQueueOneCheckPerWindow(['uses' => 1, 'perWallet' => 1]);
    }

    /**
     * A code with uses left, which this wallet has claimed as often as it may, answers the
     * repeat from the other.
     */
    public function test_a_wallet_repeating_its_claim_at_its_per_wallet_limit_queues_one_check_per_window(): void
    {
        $this->assertRepeatedClaimsQueueOneCheckPerWindow(['uses' => 10, 'perWallet' => 1]);
    }

    /**
     * @param  array<string, int>  $limits
     */
    private function assertRepeatedClaimsQueueOneCheckPerWindow(array $limits): void
    {
        Http::fake();
        Queue::fake();

        $address = 'addr1qxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9pf488uagw68fv50kjxv3wrx38829tay6zszthnccsradgqwt4upy';

        $campaign = Campaign::factory()->for($this->user)->create([
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'one_per_wallet' => false,
        ]);
        Wallet::factory()->for($campaign)->create();
        $code = Code::factory()->for($campaign)->create($limits + ['lovelace' => 2_000_000]);

        $claim = fn () => $this->postJson(route('claim.v1', $campaign), [
            'code' => $code->code,
            'address' => $address,
        ]);

        $claim()->assertJson(['status' => 'accepted']);
        Queue::assertNotPushed(CheckClaims::class);

        // Not numeric, so the inline lookup the repeat path makes for some ids is skipped
        // and the queued check is the only thing that will ever ask about this claim.
        $code->claims()->sole()->forceFill(['transaction_id' => '01HGTR1J1PEV8YABWNYX1F7G8K'])->save();

        for ($i = 0; $i < 10; $i++) {
            $claim()->assertJson(['code' => 202, 'status' => 'claimed', 'tx_hash' => '']);
        }

        Queue::assertPushed(CheckClaims::class, 1);
        Queue::assertPushed(CheckClaims::class, fn (CheckClaims $job) => $job->campaign_id === $campaign->id);

        $this->travel(self::WINDOW + 1)->seconds();
        $claim()->assertJson(['code' => 202]);

        Queue::assertPushed(CheckClaims::class, 2);
        $this->assertSame(1, $code->claims()->count());
    }

    /**
     * @return \Illuminate\Support\Collection<int, string> the purchase ids asked about, in order
     */
    private function recordStatusRequests(): \Illuminate\Support\Collection
    {
        $asked = collect();

        Http::fake(function (Request $request) use ($asked) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $asked->push($query['purchaseId'] ?? '');

            return Http::response(['status' => 'ok', 'data' => [null, ['status' => 'processing']]]);
        });

        return $asked;
    }
}

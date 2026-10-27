<?php

namespace Tests\Feature;

use App\Jobs\CheckClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The checks that follow a submission with nobody watching.
 *
 * One chain per campaign, each check waiting longer than the last, going on while claims
 * are still awaiting confirmation and for no longer than half an hour after the last
 * submission.
 */
class ClaimCheckChainTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    /** What the fake backend says about every transaction. */
    private string $answer = 'processing';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database', 'cardano.claim_check.after_submit_seconds' => 40]);
        $this->freezeSecond();
        Queue::fake();

        $this->campaign = Campaign::factory()->for(User::factory())->create(['network' => 'preprod']);
        Wallet::factory()->for($this->campaign)->create(['backend' => 'phyrhose']);

        Http::fake(fn () => Http::response(['status' => 'ok', 'data' => [null, $this->answer === 'completed'
            ? ['status' => 'completed', 'txId' => 'landedhash']
            : ['status' => $this->answer]]]));
    }

    private function waitingClaim(): Claim
    {
        return Claim::factory()
            ->for(Code::factory()->for($this->campaign))
            ->withTransaction()
            ->create(['status' => 'pending']);
    }

    /** @return \Illuminate\Support\Collection<int, CheckClaims> */
    private function chainJobs()
    {
        return Queue::pushed(CheckClaims::class)->filter(fn (CheckClaims $job) => $job->chain_id !== null)->values();
    }

    /** Run the latest queued chain check as a worker would, after its delay has passed. */
    private function runLatestChainCheck(): void
    {
        $job = $this->chainJobs()->last();
        $this->travel($job->delay)->seconds();
        $job->handle();
    }

    public function test_the_chain_waits_longer_each_time_while_claims_are_processing(): void
    {
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);

        foreach (range(1, 5) as $step) {
            $this->runLatestChainCheck();
        }

        $this->assertSame([40, 80, 160, 300, 300, 300], $this->chainJobs()->map->delay->all());
        $this->assertSame([0, 1, 2, 3, 4, 5], $this->chainJobs()->map->chain_step->all());
        $this->assertCount(1, $this->chainJobs()->map->chain_id->unique());
    }

    /**
     * An answer of unknown is not an answer either; the claim is still outstanding.
     */
    public function test_the_chain_goes_on_while_the_backend_answers_unknown(): void
    {
        $this->answer = 'unknown';
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);
        $this->runLatestChainCheck();

        $this->assertCount(2, $this->chainJobs());
    }

    public function test_the_chain_ends_once_nothing_is_outstanding_and_a_later_submission_starts_another(): void
    {
        $claim = $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);
        $this->runLatestChainCheck();
        $this->assertCount(2, $this->chainJobs());

        $this->answer = 'completed';
        $this->runLatestChainCheck();

        $this->assertSame('landedhash', $claim->fresh()->transaction_hash);
        $this->assertCount(2, $this->chainJobs(), 'nothing outstanding, so nothing more is queued');
        $this->assertNull(Cache::get(CheckClaims::chainKey($this->campaign->id)));

        $this->answer = 'processing';
        $this->waitingClaim();
        CheckClaims::afterSubmission($this->campaign->id);

        $this->assertCount(3, $this->chainJobs());
        $this->assertCount(2, $this->chainJobs()->map->chain_id->unique());
    }

    /**
     * The submission was recorded before the pass began, so the pass covers it. At whole
     * seconds the two looked the same when they fell in one second, and the chain
     * restarted itself.
     */
    public function test_a_submission_recorded_before_the_pass_began_does_not_restart_the_chain(): void
    {
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);
        $this->assertCount(1, $this->chainJobs());

        $this->answer = 'completed';
        $this->chainJobs()->last()->handle();

        $this->assertCount(1, $this->chainJobs(), 'nothing outstanding and nothing sent since, so no new chain');
        $this->assertNull(Cache::get(CheckClaims::chainKey($this->campaign->id)));
    }

    /**
     * A submission a millisecond after the pass began, in the same second, is one the pass
     * did not cover, so the chain that ends restarts for it. At whole seconds the two
     * stamps were equal and the claims it sent were left to nothing.
     */
    public function test_a_submission_a_millisecond_after_the_pass_began_restarts_the_chain(): void
    {
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);
        $this->assertCount(1, $this->chainJobs());

        $this->answer = 'completed';
        Http::fake(function () {
            // The stamp a submission would write a millisecond into the pass.
            $this->travel(1)->milliseconds();
            Cache::put(
                'claim-check-chain-submitted:'.$this->campaign->id,
                (new \ReflectionMethod(CheckClaims::class, 'preciseNow'))->invoke(null),
                CheckClaims::CHAIN_LIFETIME_SECONDS,
            );

            return Http::response(['status' => 'ok', 'data' => [null, ['status' => 'completed', 'txId' => 'landedhash']]]);
        });

        $this->chainJobs()->last()->handle();

        $this->assertCount(2, $this->chainJobs(), 'the submission after the pass began gets a chain of its own');
    }

    public function test_the_chain_ends_half_an_hour_after_it_started(): void
    {
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);

        $runs = 0;
        while ($runs < 20) {
            $before = $this->chainJobs()->count();
            $this->runLatestChainCheck();
            $runs++;

            if ($this->chainJobs()->count() === $before) {
                break;
            }
        }

        $this->assertLessThan(20, $runs, 'the chain never ended');
        $this->assertNull(Cache::get(CheckClaims::chainKey($this->campaign->id)));

        // The last check ran at or past the lifetime, and the one before it inside it.
        $started = $this->chainJobs()->first()->chain_started_at;
        $this->assertGreaterThanOrEqual(CheckClaims::CHAIN_LIFETIME_SECONDS, now()->getTimestamp() - $started);
        $this->assertLessThan(CheckClaims::CHAIN_LIFETIME_SECONDS + CheckClaims::CHAIN_MAX_INTERVAL_SECONDS, now()->getTimestamp() - $started);
    }

    /**
     * Two submissions at once start one chain: the second queues a check of that chain for
     * its own claims rather than a chain of its own.
     */
    public function test_two_submissions_at_once_start_one_chain(): void
    {
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);
        CheckClaims::afterSubmission($this->campaign->id);

        $this->assertCount(2, $this->chainJobs());
        $this->assertCount(1, $this->chainJobs()->map->chain_id->unique());
    }

    /**
     * A send late in a chain gets a check of that chain 40 seconds later, and the chain's
     * waits and half hour start again from it. The check that was queued before the send
     * still asks when it runs, but only the newer one goes on, so the chain is never
     * doubled.
     */
    public function test_a_send_during_a_chain_queues_a_check_at_the_first_wait_and_restarts_it(): void
    {
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);
        $this->runLatestChainCheck();
        $this->runLatestChainCheck();
        $this->assertSame([40, 80, 160], $this->chainJobs()->map->delay->all());

        $started = $this->chainJobs()->first()->chain_started_at;
        while (now()->getTimestamp() - $started < 25 * 60) {
            $this->runLatestChainCheck();
        }

        $superseded = $this->chainJobs()->last();
        $queued = $this->chainJobs()->count();

        CheckClaims::afterSubmission($this->campaign->id);

        $this->assertCount($queued + 1, $this->chainJobs());
        $joined = $this->chainJobs()->last();
        $this->assertSame(40, $joined->delay);
        $this->assertSame(0, $joined->chain_step);
        $this->assertSame($superseded->chain_id, $joined->chain_id);

        // The newer check runs first and carries the chain on.
        $this->travel(40)->seconds();
        $joined->handle();
        $this->assertCount($queued + 2, $this->chainJobs());
        $this->assertSame(80, $this->chainJobs()->last()->delay);

        // The one queued before the send asks, and queues nothing after itself.
        $superseded->handle();
        $this->assertCount($queued + 2, $this->chainJobs());

        $this->assertCount(1, $this->chainJobs()->map->chain_id->unique());

        // Past the first send's half hour, and still going on the second's, one check at a
        // time.
        while (now()->getTimestamp() - $started < 35 * 60) {
            $before = $this->chainJobs()->count();
            $this->runLatestChainCheck();
            $this->assertCount($before + 1, $this->chainJobs());
        }
    }

    /**
     * The other order: the check queued before the send runs first. It asks, and leaves
     * going on to the check queued for the send, which then does.
     */
    public function test_the_check_queued_before_a_send_leaves_the_chain_to_the_newer_one(): void
    {
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);
        $this->runLatestChainCheck();
        $earlier = $this->chainJobs()->last();

        $this->travel(10)->seconds();
        CheckClaims::afterSubmission($this->campaign->id);
        $joined = $this->chainJobs()->last();

        $this->travel(70)->seconds();
        $earlier->handle();
        $this->assertCount(3, $this->chainJobs(), 'the superseded check must not queue another');

        $joined->handle();
        $this->assertCount(4, $this->chainJobs());
        $this->assertSame(80, $this->chainJobs()->last()->delay);
        $this->assertCount(1, $this->chainJobs()->map->chain_id->unique());
    }

    /**
     * A check a poll asks for, or the scheduler's, asks once and queues nothing after
     * itself, whether or not a chain is running.
     */
    public function test_a_poll_triggered_check_does_not_start_a_chain(): void
    {
        $this->waitingClaim();

        CheckClaims::requestSoon($this->campaign->id);

        // Picked up after its unique lock has lapsed, so nothing but the job's own logic
        // stands between it and queueing another.
        $this->travel(20)->seconds();
        Queue::pushed(CheckClaims::class)->sole()->handle();

        $this->assertCount(1, Queue::pushed(CheckClaims::class));
        $this->assertCount(0, $this->chainJobs());
        $this->assertNull(Cache::get(CheckClaims::chainKey($this->campaign->id)));

        CheckClaims::afterSubmission($this->campaign->id);
        $this->travel(20)->seconds();
        CheckClaims::requestSoon($this->campaign->id);
        Queue::pushed(CheckClaims::class)->last()->handle();

        $this->assertCount(1, $this->chainJobs());
    }

    /**
     * A check that finds another pass holding the campaign skips its own, and the chain
     * carries on, because the claims are as outstanding as they were.
     */
    public function test_a_skipped_pass_keeps_the_chain_going(): void
    {
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);

        $lock = Cache::lock('claim-status-check:'.$this->campaign->id, 300);
        $this->assertTrue($lock->get());

        $this->runLatestChainCheck();
        Http::assertNothingSent();

        $this->assertCount(2, $this->chainJobs());
        // Not dropped by the overlap guard an unwatched check carries, which would end the
        // chain with the claims still waiting.
        $this->assertNotEmpty((new CheckClaims($this->campaign->id))->middleware());
        $this->assertSame([], $this->chainJobs()->last()->middleware());

        $lock->release();
    }

    /**
     * A check from a chain whose key has since gone to another chain ends quietly rather
     * than running alongside it.
     */
    public function test_a_chain_that_lost_the_campaign_to_another_ends(): void
    {
        $this->waitingClaim();

        CheckClaims::afterSubmission($this->campaign->id);
        $orphan = $this->chainJobs()->last();

        Cache::put(CheckClaims::chainKey($this->campaign->id), 'another-chain', 600);

        $this->travel($orphan->delay)->seconds();
        $orphan->handle();

        $this->assertCount(1, $this->chainJobs());
        $this->assertSame('another-chain', Cache::get(CheckClaims::chainKey($this->campaign->id)));
    }
}

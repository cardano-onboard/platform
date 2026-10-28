<?php

namespace Tests\Feature;

use App\Cardano\ChainLease;
use App\Models\Campaign;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Only one worker at a time may build on a campaign's chain.
 */
class ChainLeaseTest extends TestCase
{
    use RefreshDatabase;

    private function campaign(): Campaign
    {
        return Campaign::factory()->create(['network' => 'preprod']);
    }

    public function test_the_first_worker_to_ask_gets_it(): void
    {
        $campaign = $this->campaign();

        $this->assertTrue((new ChainLease('worker-a'))->take($campaign));
    }

    /**
     * And the second does not.
     *
     * The failure this prevents: both workers read the same tip, both build a transaction spending
     * it, the chain takes one, and the other is refused after its claims were marked as sent.
     */
    public function test_a_second_worker_is_refused_while_the_first_holds_it(): void
    {
        $campaign = $this->campaign();

        $this->assertTrue((new ChainLease('worker-a'))->take($campaign));
        $this->assertFalse((new ChainLease('worker-b'))->take($campaign));
    }

    /**
     * Racing workers produce one holder, not two.
     *
     * Taking the lease is one statement with its condition in the WHERE clause, so the database
     * decides. A read followed by a write would be two statements with a gap, and every worker in
     * this loop could pass the read before any of them wrote.
     */
    public function test_many_workers_racing_produce_exactly_one_holder(): void
    {
        $campaign = $this->campaign();

        $won = 0;

        foreach (range(1, 20) as $n) {
            if ((new ChainLease('worker-'.$n))->take($campaign)) {
                $won++;
            }
        }

        $this->assertSame(1, $won, 'More than one worker was told it held the lease.');
    }

    /**
     * Asking again while already holding it is not a refusal.
     *
     * A job that retries should carry on rather than deadlock against itself.
     */
    public function test_the_holder_may_take_its_own_lease_again(): void
    {
        $campaign = $this->campaign();
        $lease = new ChainLease('worker-a');

        $this->assertTrue($lease->take($campaign));
        $this->assertTrue($lease->take($campaign));
    }

    /**
     * A holder that dies does not block the campaign for ever.
     */
    public function test_a_lease_whose_holder_died_can_be_taken_once_it_expires(): void
    {
        $campaign = $this->campaign();

        $this->assertTrue((new ChainLease('worker-a'))->take($campaign, 60));
        $this->assertFalse((new ChainLease('worker-b'))->take($campaign, 60));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));

        $this->assertTrue((new ChainLease('worker-b'))->take($campaign, 60));

        CarbonImmutable::setTestNow();
    }

    /**
     * A holder still working keeps it.
     *
     * The window has to be longer than a batch takes, and a batch that runs long should renew
     * rather than have the lease taken from underneath it.
     */
    public function test_renewing_keeps_a_lease_a_slow_batch_would_otherwise_lose(): void
    {
        $campaign = $this->campaign();
        $lease = new ChainLease('worker-a');

        $this->assertTrue($lease->take($campaign, 60));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(50));
        $this->assertTrue($lease->renew($campaign, 60));

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));
        $this->assertFalse(
            (new ChainLease('worker-b'))->take($campaign, 60),
            'A renewed lease was taken from a holder that was still working.'
        );

        CarbonImmutable::setTestNow();
    }

    /**
     * Releasing lets the next batch start without waiting out the expiry.
     */
    public function test_releasing_hands_it_straight_on(): void
    {
        $campaign = $this->campaign();
        $lease = new ChainLease('worker-a');

        $lease->take($campaign);
        $lease->release($campaign);

        $this->assertTrue((new ChainLease('worker-b'))->take($campaign));
    }

    /**
     * Two campaigns do not wait on each other.
     */
    public function test_a_lease_on_one_campaign_does_not_block_another(): void
    {
        $first = $this->campaign();
        $second = $this->campaign();

        $this->assertTrue((new ChainLease('worker-a'))->take($first));
        $this->assertTrue((new ChainLease('worker-b'))->take($second));
    }
}

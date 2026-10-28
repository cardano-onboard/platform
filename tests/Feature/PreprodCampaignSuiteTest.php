<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Wallet;
use Cardano\Transaction\Codec\TransactionDecoder;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeCampaigns;
use Tests\TestCase;

/**
 * The suite against a fake chain, and the refusals that have to work before anybody points it at a
 * network.
 *
 * What it measures can only be measured on a real chain. What can be checked here is that it refuses
 * everything it should refuse before it reaches one, that it drives the production path, and that
 * it reports a chained batch and a failure of stickiness for what they are.
 */
class PreprodCampaignSuiteTest extends TestCase
{
    use NativeCampaigns;
    use RefreshDatabase;

    private function campaign(string $network, ?string $backend = 'native'): Campaign
    {
        $campaign = Campaign::factory()->create(['network' => $network]);

        if ($backend !== null) {
            Wallet::create([
                'campaign_id' => $campaign->id,
                'address' => 'addr_test1wqexample',
                'key' => $campaign->id,
                'backend' => $backend,
            ]);
        }

        return $campaign;
    }

    /**
     * It refuses mainnet outright.
     *
     * The one network where a mistake in a suite that submits transactions costs somebody's money
     * rather than test ada. Refused rather than warned about, because a warning is something a
     * person can be in a hurry past.
     */
    public function test_it_refuses_to_run_against_mainnet(): void
    {
        $campaign = $this->campaign('mainnet');

        $this->artisan('cardano:preprod-suite', ['campaign' => $campaign->id])
            ->expectsOutputToContain('This campaign is on mainnet')
            ->assertFailed();
    }

    /**
     * A campaign held by somebody else has nothing here to exercise.
     */
    public function test_it_refuses_a_campaign_that_does_not_hold_its_own_funds(): void
    {
        $campaign = $this->campaign('preprod', 'phyrhose');

        $this->artisan('cardano:preprod-suite', ['campaign' => $campaign->id])
            ->expectsOutputToContain('does not hold its own funds')
            ->assertFailed();
    }

    public function test_it_refuses_a_campaign_with_no_wallet(): void
    {
        $campaign = $this->campaign('preprod', null);

        $this->artisan('cardano:preprod-suite', ['campaign' => $campaign->id])
            ->expectsOutputToContain('does not hold its own funds')
            ->assertFailed();
    }

    public function test_it_refuses_a_campaign_that_does_not_exist(): void
    {
        $this->artisan('cardano:preprod-suite', ['campaign' => 'not-a-campaign'])
            ->expectsOutputToContain('No such campaign')
            ->assertFailed();
    }

    public function test_it_refuses_to_run_without_an_address_of_the_operators_to_pay(): void
    {
        $this->setUpNativeChain();
        $campaign = $this->nativeCampaign();

        $this->artisan('cardano:preprod-suite', ['campaign' => $campaign->id, '--to' => 'addr1notatestaddress'])
            ->expectsOutputToContain('--to must be a test network payment address')
            ->assertFailed();

        $this->assertSame(0, DB::table('claims')->count());
    }

    /**
     * Three batches, each paid by the claims job and each spending the unconfirmed change of the
     * one before, every claim completed by the status check, and what is left swept back.
     */
    public function test_a_full_run_chains_its_batches_and_closes_the_loop(): void
    {
        $this->setUpNativeChain();
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, 100_000_000);
        $this->chain->landOnStatusRead = 3;
        $operator = $this->claimant(7);
        $home = $this->claimant(8);

        $this->artisan('cardano:preprod-suite', [
            'campaign' => $campaign->id,
            '--to' => $operator,
            '--claims' => 2,
            '--chain' => 3,
            '--sweep' => $home,
            '--wait' => 0,
            '--poll' => 0,
        ])
            ->expectsOutputToContain('spending the unconfirmed change of batch 1')
            ->expectsOutputToContain('spending the unconfirmed change of batch 2')
            ->expectsOutputToContain('Sweep submitted')
            ->assertSuccessful();

        // Batches two and three were offered before anything was in a block.
        $this->assertCount(4, array_unique($this->chain->offered));

        $claims = DB::table('claims')->get();
        $this->assertCount(6, $claims);
        $this->assertSame(['completed'], $claims->pluck('status')->unique()->values()->all());
        $this->assertCount(3, $claims->pluck('transaction_id')->unique());

        $this->assertSame(12_000_000, $this->chain->balance($operator));
        $this->assertSame([], $this->chain->utxos[$campaign->wallet->address]);
        $this->assertGreaterThan(80_000_000, $this->chain->balance($home));
    }

    /**
     * With nothing confirming, each batch is built on a parent still in the mempool, which is the
     * case the chain has to accept. The fees output has to be carried through it: every child
     * spends the fees output its parent made. The run does not pass, because nothing confirmed, and
     * the report says exactly that and nothing about the fees.
     */
    public function test_chained_batches_carry_the_fees_output_before_anything_is_confirmed(): void
    {
        $this->setUpNativeChain();
        $this->collectPlatformFees();
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, 100_000_000);

        $exit = Artisan::call('cardano:preprod-suite', [
            'campaign' => $campaign->id,
            '--to' => $this->claimant(7),
            '--claims' => 1,
            '--chain' => 3,
            '--wait' => 0,
            '--poll' => 0,
        ]);
        $report = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Chained payouts carrying the fees output', $report);
        $this->assertStringContainsString('ok: 2 of 2', $report);
        $this->assertStringContainsString('no such payout was made', $report);
    }

    /**
     * A campaign that collects the fee cannot be exercised without the key it needs, and the suite
     * says so before it submits anything.
     */
    public function test_a_campaign_that_collects_the_fee_is_refused_without_the_fee_key(): void
    {
        $this->setUpNativeChain();
        $this->collectPlatformFees();
        $campaign = $this->nativeCampaign();
        DB::table('platform_fee_keys')->delete();

        $this->artisan('cardano:preprod-suite', ['campaign' => $campaign->id, '--to' => $this->claimant(7)])
            ->assertFailed();

        $this->assertSame(0, DB::table('claims')->count());
    }

    /**
     * A child refused because the node does not hold its parent is reported as a failure of
     * stickiness, and the run fails, rather than reading as one batch fewer.
     */
    public function test_a_child_the_endpoint_cannot_place_is_reported_as_a_stickiness_failure(): void
    {
        $this->setUpNativeChain();
        // The claims job queues its own follow-up; here it must not run inline and heal the batch.
        \Illuminate\Support\Facades\Queue::fake();
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, 100_000_000);

        $this->chain->refuse = function (string $hash, string $bytes) {
            $pending = $this->chain->pending();

            foreach (TransactionDecoder::decode($bytes)->body->inputs() as $input) {
                if (in_array($input->transactionIdHex(), $pending, true)) {
                    // Another node, which has never seen the parent.
                    $factory = new HttpFactory;

                    return $factory->createResponse(400)->withBody($factory->createStream('ConwayUtxowFailure (BadInputsUTxO ...)'));
                }
            }

            return null;
        };

        $this->artisan('cardano:preprod-suite', [
            'campaign' => $campaign->id,
            '--to' => $this->claimant(7),
            '--claims' => 1,
            '--chain' => 3,
            '--wait' => 0,
            '--poll' => 0,
        ])
            ->expectsOutputToContain('Batch 2 refused after 1 accepted')
            ->expectsOutputToContain('Either the endpoint is not sticky')
            ->expectsOutputToContain('FAILED: batch 2 reached a node without its parent')
            ->assertFailed();
    }
}

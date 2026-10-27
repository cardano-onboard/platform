<?php

namespace Tests\Feature;

use App\Jobs\CheckClaims;
use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use App\Services\CampaignAlerts;
use App\Services\CampaignCosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\NativeCampaigns;
use Tests\TestCase;

/**
 * Faults found in review of the platform fee, each made to happen and shown not to.
 */
class NativePlatformFeeFixesTest extends TestCase
{
    use NativeCampaigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpNativeChain();
        Queue::fake();
    }

    private function funded(int $lovelace = 500_000_000, array $attributes = []): Campaign
    {
        $campaign = $this->nativeCampaign($attributes);
        $this->chain->fund($campaign->wallet->address, $lovelace);

        return $campaign;
    }

    private function pay(Campaign $campaign): string
    {
        (new ProcessClaims($campaign->id))->handle();
        $hash = collect($this->chain->pending())->last();

        foreach ($this->chain->pending() as $pending) {
            $this->chain->land($pending, depth: 5);
        }

        (new CheckClaims($campaign->id))->handle();

        return $hash;
    }

    /**
     * With billing off on a hosted deployment, a native claim is stamped no fee at acceptance, no
     * payout collects one, and the cost statement, the funding estimate and the alerts all agree
     * that there is none.
     */
    public function test_a_native_claim_with_billing_off_carries_no_fee_anywhere(): void
    {
        $this->collectPlatformFees();
        config(['pricing.enabled' => false, 'cardano.network_fee_lovelace' => 200_000]);

        $user = User::factory()->create();
        $campaign = $this->funded(500_000_000, [
            'user_id' => $user->id,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id, 'uses' => 1, 'perWallet' => 1, 'lovelace' => 2_000_000]);
        Code::factory()->create(['campaign_id' => $campaign->id, 'uses' => 5, 'perWallet' => 5, 'lovelace' => 2_000_000]);

        $this->postJson(route('claim.v1', $campaign), ['code' => $code->code, 'address' => $this->claimant(1)])->assertOk();
        (new ProcessClaims($campaign->id))->handle();

        $claim = $code->claims()->firstOrFail();

        $this->assertNull($claim->revenue_lovelace);
        $this->assertSame(0, $this->chain->balance($this->feeAddress()));
        $this->assertSame(0, app(CampaignCosts::class)->for($campaign)['platform_fee_lovelace']);

        $this->actingAs($user)->get(route('campaigns.show', $campaign))->assertInertia(
            fn ($page) => $page->where('funding.platform_fee_lovelace', null),
        );

        $state = app(CampaignAlerts::class)->for($campaign->fresh()->load('codes'), [['lovelace' => '100000000', 'nativeAssets' => []]]);
        $this->assertSame(intdiv(100_000_000, 2_000_000 + 200_000), $state['claims_remaining']);
    }

    /**
     * A self-hosted install has no pricing configuration and no fee key. A native payout there
     * still goes out: it carries no fees output, records no fee on the claim, and never asks for a
     * key that was not made.
     */
    public function test_a_native_payout_with_no_pricing_configuration_carries_no_fee_output(): void
    {
        config(['pricing' => null]);

        $campaign = $this->funded(500_000_000, [
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
        $code = Code::factory()->create(['campaign_id' => $campaign->id, 'uses' => 1, 'perWallet' => 1, 'lovelace' => 2_000_000]);

        $this->postJson(route('claim.v1', $campaign), ['code' => $code->code, 'address' => $this->claimant(1)])->assertOk();
        (new ProcessClaims($campaign->id))->handle();

        $this->assertNotEmpty($this->chain->pending(), 'The payout was not submitted.');
        $this->assertNull($code->claims()->firstOrFail()->revenue_lovelace);
        $this->assertSame(1, DB::table('campaign_transactions')->where('campaign_id', $campaign->id)->count());
        $this->assertSame(0, DB::table('campaign_transactions')->whereNotNull('fees_output_index')->count());
        $this->assertSame(0, DB::table('platform_fee_keys')->count());
    }

    /**
     * The single-output lookup is tried again on a connection that fails and on a server error, as
     * the provider's other calls are.
     */
    public function test_the_output_lookup_is_tried_again_on_a_failure(): void
    {
        $calls = 0;
        $http = new class($calls) implements \Psr\Http\Client\ClientInterface
        {
            public function __construct(public int &$calls) {}

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $factory = new \GuzzleHttp\Psr7\HttpFactory;

                if (++$this->calls === 1) {
                    return $factory->createResponse(502);
                }

                if ($this->calls === 2) {
                    return $factory->createResponse(429);
                }

                if ($this->calls === 3) {
                    throw new class('down') extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {};
                }

                return $factory->createResponse(200)->withBody($factory->createStream(json_encode([['tx_hash' => str_repeat('ab', 32), 'tx_index' => 1, 'value' => '5000000', 'is_spent' => false]])));
            }
        };

        config(['cardano.koios.preprod_url' => 'https://koios.example.test/api/v1', 'cardano.koios.retries' => 4]);
        $factory = new \GuzzleHttp\Psr7\HttpFactory;
        $chain = new \App\Cardano\ChainData($http, $factory, $factory, app(\Illuminate\Contracts\Cache\Repository::class), new \Psr\Log\NullLogger);

        $this->assertSame(5_000_000, $chain->unspentOutput('preprod', str_repeat('ab', 32), 1));
        $this->assertSame(4, $calls, 'A server error, being told to slow down and a dropped connection are each tried again.');
    }

    /**
     * The output a campaign cares about is asked for by its reference, not found among everything
     * at the fee address, and the answer is read from the provider's own row.
     */
    public function test_one_output_is_looked_up_by_reference(): void
    {
        $captured = [];
        $http = new class($captured) implements \Psr\Http\Client\ClientInterface
        {
            public function __construct(public array &$captured) {}

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->captured[] = [(string) $request->getUri(), (string) $request->getBody()];
                $factory = new \GuzzleHttp\Psr7\HttpFactory;
                $hash = str_repeat('ab', 32);
                $body = str_contains((string) $request->getBody(), $hash.'#2')
                    ? [['tx_hash' => $hash, 'tx_index' => 2, 'value' => '5000000', 'is_spent' => true]]
                    : (str_contains((string) $request->getBody(), str_repeat('ab', 32).'#1')
                        ? [['tx_hash' => $hash, 'tx_index' => 1, 'value' => '5000000', 'is_spent' => false]]
                        : []);

                return $factory->createResponse(200)->withBody($factory->createStream(json_encode($body)));
            }
        };

        config(['cardano.koios.preprod_url' => 'https://koios.example.test/api/v1']);
        $factory = new \GuzzleHttp\Psr7\HttpFactory;
        $chain = new \App\Cardano\ChainData($http, $factory, $factory, app(\Illuminate\Contracts\Cache\Repository::class), new \Psr\Log\NullLogger);

        $this->assertSame(5_000_000, $chain->unspentOutput('preprod', str_repeat('ab', 32), 1));
        $this->assertNull($chain->unspentOutput('preprod', str_repeat('ab', 32), 0));
        $this->assertNull($chain->unspentOutput('preprod', str_repeat('ab', 32), 2), 'A spent output is not held.');
        $this->assertSame('https://koios.example.test/api/v1/utxo_info', $captured[0][0]);
        $this->assertSame([str_repeat('ab', 32).'#1'], json_decode($captured[0][1], true)['_utxo_refs']);
    }
}

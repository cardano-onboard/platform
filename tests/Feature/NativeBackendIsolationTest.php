<?php

namespace Tests\Feature;

use App\Jobs\CheckClaims;
use App\Jobs\CreateCampaignBucket;
use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\Wallet;
use App\Services\PhyrhoseBackend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeCampaigns;
use Tests\TestCase;

/**
 * A deployment switched to the native backend keeps every existing campaign where it was.
 *
 * The backend is stamped on each wallet when the wallet is made, and every later operation reads
 * it from there. So a deployment carrying hosted campaigns can set TRANSACTION_BACKEND=native and
 * only the campaigns created afterwards hold their own keys. These tests hold that line from both
 * sides: the hosted campaigns never reach the native path, and the native-only work never touches
 * a hosted campaign.
 */
class NativeBackendIsolationTest extends TestCase
{
    use NativeCampaigns;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The deployment as it would be after the switch.
        $this->setUpNativeChain();

        config([
            'cardano.phyrhose.preprod_url' => 'https://phyrhose.test/',
            'cardano.phyrhose.mainnet_url' => 'https://phyrhose-mainnet.test/',
        ]);
    }

    /**
     * A campaign created before the switch, with its wallet on the hosted backend or, older still,
     * with no backend recorded at all.
     */
    private function hostedCampaign(?string $backend, string $network = 'preprod'): Campaign
    {
        $campaign = Campaign::factory()->create(['network' => $network]);

        // A wallet from before backends were recorded carries the column's default.
        Wallet::create(array_filter([
            'campaign_id' => $campaign->id,
            'address' => 'addr_test1hosted'.$campaign->id,
            'key' => 'hosted-'.$campaign->id,
            'backend' => $backend,
        ]));

        return $campaign->fresh('wallet');
    }

    public static function hostedBackends(): array
    {
        return ['stamped phyrhose' => ['phyrhose'], 'column default' => [null]];
    }

    #[DataProvider('hostedBackends')]
    public function test_an_existing_hosted_wallet_resolves_to_the_hosted_backend(?string $backend): void
    {
        $this->assertInstanceOf(PhyrhoseBackend::class, $this->hostedCampaign($backend)->wallet->resolveBackend());
    }

    #[DataProvider('hostedBackends')]
    public function test_a_hosted_campaigns_claims_and_status_checks_never_reach_the_native_path(?string $backend): void
    {
        $campaign = $this->hostedCampaign($backend, 'mainnet');
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);
        $claim = Claim::factory()->create(['code_id' => $code->id, 'transaction_id' => null]);

        Http::fake([
            '*pooPurchaseOrder*' => Http::response([
                'status' => 'ok',
                'data' => [null, ['items' => [[
                    'pooCode' => $code->code,
                    'recipientAddress' => $claim->address,
                    'purchaseId' => '4711',
                ]]]],
            ]),
            '*purchaseStatus*' => Http::response([
                'status' => 'ok',
                'data' => [null, ['status' => 'completed', 'txId' => str_repeat('e', 64)]],
            ]),
        ]);

        (new ProcessClaims($campaign->id))->handle();
        (new CheckClaims($campaign->id))->handle();

        $claim->refresh();
        $this->assertSame('4711', $claim->transaction_id);
        $this->assertSame('completed', $claim->status);
        $this->assertSame(str_repeat('e', 64), $claim->transaction_hash);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'phyrhose-mainnet.test'));

        // Nothing was built, recorded or offered on the native side.
        $this->assertSame([], $this->chain->offered);
        $this->assertSame(0, DB::table('campaign_transactions')->count());
    }

    #[DataProvider('hostedBackends')]
    public function test_a_hosted_campaign_is_refunded_through_the_hosted_backend(?string $backend): void
    {
        $campaign = $this->hostedCampaign($backend);

        Http::fake(['*' => Http::response(['status' => 'ok'])]);

        $this->assertTrue($campaign->wallet->resolveBackend()->refund($campaign->wallet->key, $this->claimant(1), 'preprod'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'phyrhose.test'));
        $this->assertSame([], $this->chain->offered);
    }

    /**
     * The claim check settles a native campaign's transactions against the chain before it checks
     * claims, and never reads the chain for a hosted campaign, whatever rows sit against it.
     */
    public function test_the_claim_check_reconciles_native_campaigns_only(): void
    {
        $native = $this->nativeCampaign();
        $this->chain->fund($native->wallet->address, 50_000_000);
        $claim = $this->claim($native, 1);

        // Paid, with no claim marked as sent, so only the reconciliation can settle it.
        $this->backend()->submitPayment($native->id, [$this->recipient($claim)], 'preprod');
        [$hash] = $this->chain->pending();
        $this->chain->land($hash, depth: 5);

        $hosted = $this->hostedCampaign('phyrhose');
        DB::table('campaign_transactions')->insert([
            'campaign_id' => $hosted->id, 'sequence' => 1, 'tx_hash' => str_repeat('d', 64), 'signed_cbor' => '84',
            'inputs_consumed' => '[]', 'validity_end' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        (new CheckClaims($hosted->id))->handle();
        $this->assertNull(DB::table('campaign_transactions')->where('tx_hash', str_repeat('d', 64))->value('failed_at'));
        $this->assertNull(DB::table('campaign_transactions')->where('tx_hash', $hash)->value('confirmed_at'));

        (new CheckClaims($native->id))->handle();
        $this->assertNotNull(DB::table('campaign_transactions')->where('tx_hash', $hash)->value('confirmed_at'));
    }

    /**
     * A campaign created after the switch is stamped native by the creation job, and holds its
     * own key, while the campaigns before it keep theirs.
     */
    public function test_only_a_campaign_created_after_the_switch_is_native(): void
    {
        $before = $this->hostedCampaign('phyrhose');

        $after = Campaign::factory()->create([
            'network' => 'preprod',
            'expiry_slot' => $this->chain->slot + 90 * 86400,
            'funding_path' => 'credit',
        ]);

        (new CreateCampaignBucket($after->id))->handle(app(\App\Contracts\TransactionBackend::class));

        $this->assertSame('native', $after->fresh()->wallet->backend);
        $this->assertNotNull($after->fresh()->wallet->skey);
        $this->assertSame('phyrhose', $before->fresh()->wallet->backend);
        $this->assertSame(1, Wallet::where('campaign_id', $after->id)->count());
    }

    /**
     * The proxy relays to the hosted custodian even on a deployment whose default is native. The
     * native backend pays from this deployment's own campaigns by id, and a proxy token holder
     * naming one of them must not be able to pay anybody from it.
     */
    public function test_the_proxy_never_relays_to_the_native_backend(): void
    {
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, 50_000_000);
        $token = \App\Models\User::factory()->create()->createToken('proxy')->plainTextToken;

        Http::fake(['*pooPurchaseOrder*' => Http::response(['status' => 'error'])]);

        $this->withToken($token)->postJson('/api/v1/proxy/payment', [
            'campaignId' => $campaign->id,
            'recipients' => [['pooCode' => 'x', 'address' => $this->claimant(1), 'lovelace' => 2_000_000, 'tokens' => []]],
            'network' => 'preprod',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'pooPurchaseOrder'));
        $this->assertSame([], $this->chain->offered);
        $this->assertSame(0, DB::table('campaign_transactions')->count());
    }
}

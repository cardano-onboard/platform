<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignClaimClient;
use App\Models\ClaimUserAgent;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use App\Support\ClaimClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClaimClientTallyTest extends TestCase
{
    use RefreshDatabase;

    // A valid mainnet Shelley address, from the same fixtures ClaimMultiUseTest uses.
    private const VALID_MAINNET_ADDRESS = 'addr1qxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9pf488uagw68fv50kjxv3wrx38829tay6zszthnccsradgqwt4upy';

    private const VALID_MAINNET_ADDRESS_2 = 'addr1xxgx3far7qygq0k6epa0zcvcvrevmn0ypsnfsue94nsn3tfvjel5h55fgjcxgchp830r7h2l5msrlpt8262r3nvr8eks2utwdd';

    private const LACE = 'okhttp/4.12.0';

    private const VESPR = 'axios/1.19.0';

    private Campaign $campaign;

    private Code $code;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($user)->create([
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'one_per_wallet' => false,
        ]);
        Wallet::factory()->for($this->campaign)->create();
        $this->code = Code::factory()->for($this->campaign)->create([
            'uses' => 10000,
            'perWallet' => 100,
            'lovelace' => 2000000,
        ]);
    }

    private function claim(string $userAgent, string $address = self::VALID_MAINNET_ADDRESS)
    {
        return $this->withHeaders(['User-Agent' => $userAgent])
            ->postJson(route('claim.v1', $this->campaign), [
                'code' => $this->code->code,
                'address' => $address,
            ]);
    }

    public function test_an_accepted_claim_is_tallied_against_its_campaign(): void
    {
        $this->claim(self::LACE)->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('campaign_claim_clients', [
            'campaign_id' => $this->campaign->id,
            'client' => 'okhttp',
            'claims' => 1,
        ]);
    }

    public function test_repeat_claims_from_the_same_client_increment_one_row(): void
    {
        $this->claim(self::LACE)->assertJson(['status' => 'accepted']);
        $this->claim(self::LACE, self::VALID_MAINNET_ADDRESS_2)->assertJson(['status' => 'accepted']);

        $this->assertSame(1, CampaignClaimClient::where('campaign_id', $this->campaign->id)->count());
        $this->assertDatabaseHas('campaign_claim_clients', [
            'campaign_id' => $this->campaign->id,
            'client' => 'okhttp',
            'claims' => 2,
        ]);
    }

    public function test_different_clients_are_tallied_separately(): void
    {
        $this->claim(self::LACE)->assertJson(['status' => 'accepted']);
        $this->claim(self::VESPR, self::VALID_MAINNET_ADDRESS_2)->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('campaign_claim_clients', ['client' => 'okhttp', 'claims' => 1]);
        $this->assertDatabaseHas('campaign_claim_clients', ['client' => 'axios', 'claims' => 1]);
    }

    /**
     * Rejections are dominated by scanners and retries, so counting them would measure
     * traffic pointed at the endpoint rather than wallets that completed a claim.
     */
    public function test_a_rejected_request_is_not_tallied(): void
    {
        $this->withHeaders(['User-Agent' => self::LACE])
            ->postJson(route('claim.v1', $this->campaign), [
                'code' => 'NOT_A_REAL_CODE',
                'address' => self::VALID_MAINNET_ADDRESS,
            ]);

        $this->withHeaders(['User-Agent' => self::LACE])
            ->postJson(route('claim.v1', $this->campaign), [
                'code' => $this->code->code,
                'address' => 'not_a_valid_address',
            ]);

        $this->assertDatabaseCount('campaign_claim_clients', 0);
        $this->assertDatabaseCount('claim_user_agents', 0);
    }

    public function test_the_raw_string_is_catalogued_once_per_distinct_value(): void
    {
        $this->claim(self::LACE)->assertJson(['status' => 'accepted']);
        $this->claim(self::LACE, self::VALID_MAINNET_ADDRESS_2)->assertJson(['status' => 'accepted']);

        $this->assertDatabaseCount('claim_user_agents', 1);
        $this->assertDatabaseHas('claim_user_agents', [
            'fingerprint' => hash('sha256', self::LACE),
            'user_agent' => self::LACE,
            'client' => 'okhttp',
            'wallet' => 'lace',
        ]);
    }

    public function test_a_claim_with_no_user_agent_is_tallied_but_catalogues_nothing(): void
    {
        // Laravel's test client sends a Symfony user agent by default, so it has to be
        // cleared explicitly to reproduce a request that carries none.
        $this->withServerVariables(['HTTP_USER_AGENT' => ''])
            ->postJson(route('claim.v1', $this->campaign), [
                'code' => $this->code->code,
                'address' => self::VALID_MAINNET_ADDRESS,
            ])->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('campaign_claim_clients', [
            'client' => ClaimClient::ABSENT,
            'claims' => 1,
        ]);
        $this->assertDatabaseCount('claim_user_agents', 0);
    }

    /**
     * The point of the whole shape. Neither table may carry anything that identifies which
     * claim a row came from, and the two obvious ways back in are a foreign key and an
     * ordering column: an auto-incrementing id records insertion order, insertion order is
     * claim order, and a created_at says it outright.
     */
    public function test_neither_table_can_be_traced_back_to_a_claim(): void
    {
        foreach (['campaign_claim_clients', 'claim_user_agents'] as $table) {
            $columns = Schema::getColumnListing($table);

            $this->assertNotContains('id', $columns, "$table must not carry a surrogate key.");
            $this->assertNotContains('claim_id', $columns, "$table must not reference a claim.");
            $this->assertNotContains('created_at', $columns, "$table must not carry a timestamp.");
            $this->assertNotContains('updated_at', $columns, "$table must not carry a timestamp.");
        }

        $this->assertNotContains('campaign_id', Schema::getColumnListing('claim_user_agents'));
    }

    public function test_platform_totals_sum_across_campaigns(): void
    {
        $other = Campaign::factory()->for(User::factory())->create(['network' => 'mainnet']);

        CampaignClaimClient::tally($this->campaign->id, 'okhttp');
        CampaignClaimClient::tally($this->campaign->id, 'okhttp');
        CampaignClaimClient::tally($other->id, 'okhttp');
        CampaignClaimClient::tally($other->id, 'axios');

        $this->assertSame(['okhttp' => 3, 'axios' => 1], CampaignClaimClient::platformTotals()->all());
    }

    public function test_a_campaign_below_the_floor_is_not_broken_down(): void
    {
        config(['analytics.client_breakdown_floor' => 5]);

        foreach (range(1, 4) as $ignored) {
            CampaignClaimClient::tally($this->campaign->id, 'okhttp');
        }

        $this->assertNull(CampaignClaimClient::campaignBreakdown($this->campaign));

        CampaignClaimClient::tally($this->campaign->id, 'okhttp');

        $this->assertSame(['okhttp' => 5], CampaignClaimClient::campaignBreakdown($this->campaign)->all());
    }

    /**
     * Analytics must never cost a claimant their claim, so a failure recording the client
     * is swallowed. Dropping the tally table is a stand-in for any storage failure.
     */
    public function test_a_failure_recording_the_client_does_not_break_the_claim(): void
    {
        Schema::drop('campaign_claim_clients');

        $this->claim(self::LACE)->assertJson(['status' => 'accepted']);

        $this->assertSame(1, $this->code->claims()->count());
    }

    public function test_an_unrecognised_client_is_catalogued_so_it_can_be_named_later(): void
    {
        $this->claim('SomeNewWallet/2.0')->assertJson(['status' => 'accepted']);

        $this->assertDatabaseHas('campaign_claim_clients', [
            'client' => ClaimClient::UNKNOWN,
            'claims' => 1,
        ]);
        $this->assertDatabaseHas('claim_user_agents', [
            'user_agent' => 'SomeNewWallet/2.0',
            'client' => ClaimClient::UNKNOWN,
            'wallet' => null,
        ]);
    }

    public function test_the_catalogue_is_refreshed_when_a_rule_changes(): void
    {
        ClaimUserAgent::create([
            'fingerprint' => hash('sha256', self::LACE),
            'user_agent' => self::LACE,
            'client' => ClaimClient::UNKNOWN,
            'wallet' => null,
        ]);

        $this->claim(self::LACE)->assertJson(['status' => 'accepted']);

        $this->assertDatabaseCount('claim_user_agents', 1);
        $this->assertDatabaseHas('claim_user_agents', [
            'fingerprint' => hash('sha256', self::LACE),
            'client' => 'okhttp',
            'wallet' => 'lace',
        ]);
    }

    /**
     * The floor and the end date guard different things, so each is asserted on its own.
     * Here the campaign is large enough but still open.
     */
    public function test_a_running_campaign_withholds_its_breakdown_however_large_it_is(): void
    {
        config(['analytics.client_breakdown_floor' => 2]);

        CampaignClaimClient::tally($this->campaign->id, 'okhttp');
        CampaignClaimClient::tally($this->campaign->id, 'axios');
        CampaignClaimClient::tally($this->campaign->id, 'axios');

        $shares = CampaignClaimClient::campaignShares($this->campaign);

        $this->assertSame('running', $shares['state']);
        $this->assertNull($shares['clients']);
    }

    public function test_a_closed_campaign_above_the_floor_reports_shares(): void
    {
        config(['analytics.client_breakdown_floor' => 2]);

        $this->campaign->update(['end_date' => now()->subDay()->toDateString()]);

        CampaignClaimClient::tally($this->campaign->id, 'okhttp');
        CampaignClaimClient::tally($this->campaign->id, 'axios');
        CampaignClaimClient::tally($this->campaign->id, 'axios');
        CampaignClaimClient::tally($this->campaign->id, 'unknown');

        $shares = CampaignClaimClient::campaignShares($this->campaign->fresh());

        $this->assertSame('ready', $shares['state']);
        $this->assertSame([
            ['client' => 'axios', 'wallet' => 'VESPR', 'share' => 50.0],
            ['client' => 'okhttp', 'wallet' => 'Lace', 'share' => 25.0],
            ['client' => 'unknown', 'wallet' => null, 'share' => 25.0],
        ], $shares['clients']);
    }

    /**
     * A closed campaign that never reached the floor stays withheld. Ending a campaign
     * does not make two claimants un-nameable.
     */
    public function test_a_closed_campaign_below_the_floor_is_still_withheld(): void
    {
        config(['analytics.client_breakdown_floor' => 10]);

        $this->campaign->update(['end_date' => now()->subDay()->toDateString()]);

        CampaignClaimClient::tally($this->campaign->id, 'okhttp');

        $shares = CampaignClaimClient::campaignShares($this->campaign->fresh());

        $this->assertSame('too_few', $shares['state']);
        $this->assertNull($shares['clients']);
    }

    /**
     * The administrator's early view is for integration work, so it lifts the end date
     * and reports itself as early rather than passing for a figure the operator could
     * have seen.
     */
    public function test_an_administrator_sees_a_running_campaign_early_and_it_says_so(): void
    {
        config(['analytics.client_breakdown_floor' => 2]);

        CampaignClaimClient::tally($this->campaign->id, 'okhttp');
        CampaignClaimClient::tally($this->campaign->id, 'okhttp');

        $shares = CampaignClaimClient::campaignShares($this->campaign, live: true);

        $this->assertSame('early', $shares['state']);
        $this->assertSame(
            [['client' => 'okhttp', 'wallet' => 'Lace', 'share' => 100.0]],
            $shares['clients'],
        );
    }

    /** The early view lifts the end date and nothing else. */
    public function test_the_administrator_view_does_not_lift_the_floor(): void
    {
        config(['analytics.client_breakdown_floor' => 10]);

        CampaignClaimClient::tally($this->campaign->id, 'okhttp');

        $shares = CampaignClaimClient::campaignShares($this->campaign, live: true);

        $this->assertSame('too_few', $shares['state']);
        $this->assertNull($shares['clients']);
    }

    public function test_the_campaign_page_carries_the_breakdown(): void
    {
        config(['analytics.client_breakdown_floor' => 2]);

        $this->campaign->update(['end_date' => now()->subDay()->toDateString()]);

        CampaignClaimClient::tally($this->campaign->id, 'okhttp');
        CampaignClaimClient::tally($this->campaign->id, 'okhttp');

        $this->actingAs($this->campaign->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('wallet_clients.state', 'ready')
                ->where('wallet_clients.clients.0.wallet', 'Lace')
                // A whole share serialises as an integer, because JSON has one number
                // type and 100.0 encodes as 100. The page renders it either way.
                ->where('wallet_clients.clients.0.share', 100)
            );
    }

    /**
     * Nothing in the payload may point at a claim. The shares are proportions and the
     * counts stay on the server.
     */
    public function test_the_breakdown_payload_carries_no_claim_counts(): void
    {
        config(['analytics.client_breakdown_floor' => 2]);

        $this->campaign->update(['end_date' => now()->subDay()->toDateString()]);

        CampaignClaimClient::tally($this->campaign->id, 'okhttp');
        CampaignClaimClient::tally($this->campaign->id, 'axios');

        $shares = CampaignClaimClient::campaignShares($this->campaign->fresh());

        foreach ($shares['clients'] as $row) {
            $this->assertSame(['client', 'wallet', 'share'], array_keys($row));
        }
    }

    public function test_an_ordinary_operator_does_not_see_a_running_campaign_early(): void
    {
        config(['analytics.client_breakdown_floor' => 2]);

        CampaignClaimClient::tally($this->campaign->id, 'okhttp');
        CampaignClaimClient::tally($this->campaign->id, 'okhttp');

        $this->actingAs($this->campaign->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('wallet_clients.state', 'running')
                ->where('wallet_clients.clients', null)
            );
    }
}

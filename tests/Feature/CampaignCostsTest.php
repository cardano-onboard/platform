<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\Reward;
use App\Models\User;
use App\Models\Wallet;
use App\Services\CampaignCosts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * What a campaign cost, and what it still needs.
 *
 * The two answer the same question about different money and must behave in opposite
 * ways. What a campaign cost is read from what each claim recorded at the time, so a
 * later change to a rate leaves it alone. What it still needs is an estimate for claims
 * nobody has taken yet, so it follows the rates in force and moves when they do.
 */
class CampaignCostsTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = 'addr1qxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9pf488uagw68fv50kjxv3wrx38829tay6zszthnccsradgqwt4upy';

    private const ADDRESS_2 = 'addr1xxgx3far7qygq0k6epa0zcvcvrevmn0ypsnfsue94nsn3tfvjel5h55fgjcxgchp830r7h2l5msrlpt8262r3nvr8eks2utwdd';

    private User $user;

    private Campaign $campaign;

    private Code $code;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        // Nothing here is about sending a transaction, and a real dispatch leaves an
        // object that runs when it is destroyed, which lands after this class has
        // finished and its database is gone.
        Queue::fake();

        config([
            'pricing.enabled' => true,
            // The shipped ADA path: the backend takes its fee out of the campaign's own
            // bucket as each claim is paid, which is the path these figures describe.
            'pricing.paths.ada.collection' => 'in_band',
            'pricing.paths.ada.in_band_rates' => ['platform_fee_lovelace' => 1000000],
            'cardano.network_fee_lovelace' => 200000,
        ]);

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create([
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'one_per_wallet' => false,
        ]);
        Wallet::factory()->for($this->campaign)->create();
        $this->code = Code::factory()->for($this->campaign)->create([
            'uses' => 10,
            'perWallet' => 10,
            'lovelace' => 2000000,
        ]);
    }

    private function claim(string $address = self::ADDRESS)
    {
        return $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $this->code->code,
            'address' => $address,
        ]);
    }

    private function costs(): array
    {
        return app(CampaignCosts::class)->for($this->campaign);
    }

    private function show()
    {
        return $this->actingAs($this->user)->get(route('campaigns.show', $this->campaign));
    }

    /**
     * The one that matters most, for the network fee: a figure reconstructed from
     * today's rate would be wrong for every claim taken under a previous one while
     * looking authoritative, and nothing on the page would say so. What was charged was
     * written down when it happened. The network fee ships in every edition — the
     * platform fee half of the same principle is below, and is SaaS-only.
     */
    public function test_the_recorded_network_fee_does_not_move_when_the_rate_changes(): void
    {
        $this->claim(self::ADDRESS);

        $this->assertSame(200000, $this->costs()['network_fee_lovelace']);

        // The rate card changes, as it would in config/cardano.php.
        config(['cardano.network_fee_lovelace' => 900000]);

        $after = $this->costs();

        $this->assertSame(200000, $after['network_fee_lovelace']);

        // And a claim taken after the change records the new rate, so the summary is the
        // sum of what each claim was actually charged rather than either rate multiplied
        // by a count. Both of those would have matched above; neither matches here.
        $this->claim(self::ADDRESS_2);

        $mixed = $this->costs();

        $this->assertSame(2, $mixed['claims']);
        $this->assertSame(1100000, $mixed['network_fee_lovelace']);
    }

    /**
     * A name is worth showing where one stands for the whole policy. A policy minting a
     * serial for each recipient has no such name, and picking one of them to stand for
     * the rest would misdescribe what was given away.
     */
    public function test_a_policy_holding_one_asset_is_named_and_one_holding_several_is_not(): void
    {
        $sole = str_repeat('11', 28);
        $serials = str_repeat('22', 28);
        $binary = str_repeat('33', 28);

        Reward::factory()->for($this->code)->create([
            'policy_hex' => $sole,
            'asset_hex' => bin2hex('Mascot'),
            'quantity' => 1,
        ]);

        foreach (['4e4654303031', '4e4654303032'] as $assetHex) {
            Reward::factory()->for($this->code)->create([
                'policy_hex' => $serials,
                'asset_hex' => $assetHex,
                'quantity' => 1,
            ]);
        }

        // An asset name is hex on chain and is readable to a person about half the time.
        // This one is not text at all, and rendering it as though it were would put
        // mojibake on an accounting page.
        Reward::factory()->for($this->code)->create([
            'policy_hex' => $binary,
            'asset_hex' => 'ff00ff',
            'quantity' => 1,
        ]);

        $this->claim();

        $policies = collect($this->costs()['policies'])->keyBy('policy_hex');

        $this->assertSame(1, $policies[$sole]['assets']);
        $this->assertSame('Mascot', $policies[$sole]['asset_name']);
        $this->assertSame(bin2hex('Mascot'), $policies[$sole]['asset_hex']);

        $this->assertSame(2, $policies[$serials]['assets']);
        $this->assertNull($policies[$serials]['asset_name']);
        $this->assertNull($policies[$serials]['asset_hex']);

        // Unreadable, but still one asset, so the page can still look its metadata up.
        $this->assertSame(1, $policies[$binary]['assets']);
        $this->assertNull($policies[$binary]['asset_name']);
        $this->assertSame('ff00ff', $policies[$binary]['asset_hex']);
    }

    /**
     * A token named under CIP-0068 is named on the statement.
     *
     * The name of such a token does not start at its first byte. It starts four bytes in,
     * behind a label, and the first of those four bytes is always NUL. Judging the whole
     * string for printability rejected it there and put raw hex on an accounting page for
     * precisely the tokens this column exists to name.
     */
    public function test_a_cip68_token_is_named_rather_than_shown_as_hex(): void
    {
        $policy = str_repeat('44', 28);

        Reward::factory()->for($this->code)->create([
            // Label 222, the user's own NFT, in front of the name "Mascot".
            'policy_hex' => $policy,
            'asset_hex' => '000de140'.bin2hex('Mascot'),
            'quantity' => 1,
        ]);

        $this->claim();

        $policies = collect($this->costs()['policies'])->keyBy('policy_hex');

        $this->assertSame('(222)Mascot', $policies[$policy]['asset_name']);
    }

    /**
     * A reference NFT and the user token it describes are two rows, not one row twice.
     *
     * CIP-0068 requires the pair to share a name under one policy and differ only in the
     * label, so the label has to survive into the export. Dropping it once parsed would
     * put "Mascot" on both lines and lose which was which.
     */
    public function test_a_reference_nft_and_its_user_token_are_told_apart_in_the_export(): void
    {
        $policy = str_repeat('55', 28);

        foreach (['000643b0', '000de140'] as $label) {
            Reward::factory()->for($this->code)->create([
                'policy_hex' => $policy,
                'asset_hex' => $label.bin2hex('Mascot'),
                'quantity' => 1,
            ]);
        }

        $this->claim();

        $response = $this->actingAs($this->user)
            ->get(route('campaigns.export-costs', $this->campaign))
            ->assertOk();

        $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
        $rows = array_map(static fn ($line) => str_getcsv($line, ',', '"', '\\'), $lines);
        $names = array_column(array_slice($rows, 1), 2);

        sort($names);
        $this->assertSame(['(100)Mascot', '(222)Mascot'], $names);
    }

    /**
     * The case the grouping exists for. A drop that mints a serial per recipient is one
     * row on the page and every one of them in the export, which is the sheet an
     * accountant wants.
     */
    public function test_the_export_carries_every_asset_while_the_page_shows_one_row(): void
    {
        $policy = str_repeat('ab', 28);

        for ($serial = 1; $serial <= 12; $serial++) {
            Reward::factory()->for($this->code)->create([
                'policy_hex' => $policy,
                'asset_hex' => bin2hex(sprintf('SERIAL%03d', $serial)),
                'quantity' => 1,
            ]);
        }

        $this->claim();

        $costs = $this->costs();

        $this->assertCount(1, $costs['policies']);
        $this->assertSame(12, $costs['policies'][0]['assets']);
        $this->assertSame(12, $costs['distinct_assets']);

        $response = $this->actingAs($this->user)
            ->get(route('campaigns.export-costs', $this->campaign))
            ->assertOk();

        $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
        $rows = array_map(static fn ($line) => str_getcsv($line, ',', '"', '\\'), $lines);

        $this->assertSame(['policy_id', 'asset_hex', 'asset_name', 'quantity'], $rows[0]);
        // A header and one row per asset, with nothing collapsed.
        $this->assertCount(13, $rows);
        $this->assertSame([$policy, bin2hex('SERIAL001'), 'SERIAL001', '1'], $rows[1]);
        $this->assertSame([$policy, bin2hex('SERIAL012'), 'SERIAL012', '1'], $rows[12]);
    }

    /**
     * As a self-hoster sees it, with the pricing file deleted. The fee line is absent
     * rather than zero, because a line reading zero invites the question of what it would
     * otherwise have been. What the chain took and what they gave away are real for them
     * and carry their actual figures.
     */
    public function test_a_deployment_that_does_not_charge_withholds_the_fee_and_keeps_the_rest(): void
    {
        config(['pricing.paths' => []]);

        $this->claim();

        $costs = $this->costs();

        $this->assertArrayHasKey('platform_fee_lovelace', $costs);
        $this->assertNull($costs['platform_fee_lovelace']);
        $this->assertSame(200000, $costs['network_fee_lovelace']);
        $this->assertSame(2000000, $costs['reward_lovelace']);

        // Null on the page too, which is what the summary keys off to leave the row out.
        $this->show()->assertInertia(fn ($page) => $page
            ->where('costs.platform_fee_lovelace', null)
            ->where('costs.network_fee_lovelace', 200000)
            ->where('costs.reward_lovelace', 2000000)
        );
    }

    /**
     * The funding card asks for one ADA figure and breaks it into three. Those three have
     * to be the whole of it rather than a sample: the card starts from what the codes
     * still owe and adds the two fee lines, so the rewards part has to be that same
     * figure and the parts have to sum to what it asks for.
     */
    public function test_the_funding_parts_add_up_to_the_ada_the_card_asks_for(): void
    {
        $this->claim();

        $response = $this->show()->assertOk();

        $props = $response->viewData('page')['props'];

        $this->assertSame(9, $props['funding']['remaining_claims']);
        $this->assertSame(18000000, $props['funding']['reward_lovelace']);
        $this->assertSame(1800000, $props['funding']['network_fee_lovelace']);
        $this->assertSame(9000000, $props['funding']['platform_fee_lovelace']);

        // What the card totals is the codes' outstanding rewards plus the two fee lines.
        $this->assertSame(
            $props['funding']['reward_lovelace'],
            (int) $props['campaign']['rewards']['lovelace'],
        );

        $this->assertSame(28800000, (int) $props['campaign']['rewards']['lovelace']
            + $props['funding']['network_fee_lovelace']
            + $props['funding']['platform_fee_lovelace']);
    }

    /**
     * The estimate is built from the rates actually in force rather than from a constant,
     * so changing one moves the line it belongs to and leaves the others alone.
     */
    public function test_the_funding_estimate_follows_the_rates_in_force(): void
    {
        $this->code->update(['uses' => 5, 'lovelace' => 1000000]);

        $this->show()->assertInertia(fn ($page) => $page
            ->where('funding.reward_lovelace', 5000000)
            ->where('funding.network_fee_lovelace', 1000000)
            ->where('funding.platform_fee_lovelace', 5000000)
        );

        config([
            'cardano.network_fee_lovelace' => 450000,
            'pricing.paths.ada.in_band_rates' => ['platform_fee_lovelace' => 2000000],
        ]);

        $this->show()->assertInertia(fn ($page) => $page
            // What the codes owe claimants is not a rate and does not move with one.
            ->where('funding.reward_lovelace', 5000000)
            ->where('funding.network_fee_lovelace', 2250000)
            ->where('funding.platform_fee_lovelace', 10000000)
        );
    }
}

<?php

namespace Tests\Feature;

use App\Cardano\CampaignScript;
use App\Cardano\ChainData;
use App\Jobs\CreateCampaignBucket;
use App\Models\Campaign;
use App\Models\User;
use App\Models\Wallet;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The window a campaign is authorised for, and why it cannot be stretched afterwards.
 *
 * The expiry slot is inside the campaign's script and therefore inside its address. It is settled
 * when the campaign is created and there is no way to revise it: not by an edit, not by a
 * migration, not by anybody. So the end date has to be held inside it, or a campaign would go on
 * accepting claims after the branch of the script that pays them had stopped working.
 */
class CampaignAuthorisationWindowTest extends TestCase
{
    use RefreshDatabase;

    private const TIP_SLOT = 140_000_000;

    /** The CIP-19 test vector reward address (type 14, testnet). */
    private const PREPROD_STAKE = 'stake_test1uqehkck0lajq8gr28t9uxnuvgcqrc6070x3k9r8048z8y5gssrtvn';

    /** The same credential on mainnet (CIP-19 test vector type 14, mainnet). */
    private const MAINNET_STAKE = 'stake1uyehkck0lajq8gr28t9uxnuvgcqrc6070x3k9r8048z8y5gh6ffgw';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cardano.transaction_backend' => 'native',
            'campaign_script.authorisation_days' => 180,
            'campaign_script.owner_key_hashes' => [str_repeat('01', 28), str_repeat('02', 28)],
            'campaign_script.wrapping_key_version' => 1,
            'campaign_script.wrapping_keys' => [1 => base64_encode(random_bytes(32))],
            'campaign_script.stake_addresses' => ['preprod' => self::PREPROD_STAKE, 'mainnet' => self::MAINNET_STAKE, 'preview' => null],
        ]);

        // Frozen, because the expected slot is worked out from now in the test and the real one
        // from now during the request, and a second boundary between the two moves it by one.
        $this->freezeTime();

        // The wallet itself is the factory's business and has its own tests. What is under test
        // here is what creation records before the job runs.
        Queue::fake();

        // A tip anchored on now, so the dates in these tests are the dates a person would pick.
        $this->app->bind(ChainData::class, fn () => new class extends ChainData
        {
            public function __construct() {}

            public function tip(string $network): array
            {
                return ['slot' => CampaignAuthorisationWindowTest::tipSlot(), 'time' => CarbonImmutable::now()->getTimestamp()];
            }
        });
    }

    public static function tipSlot(): int
    {
        return self::TIP_SLOT;
    }

    private function createCampaign(string $endDate): Campaign
    {
        $this->attemptCampaign($endDate)->assertRedirect()->assertSessionHasNoErrors();

        return Campaign::latest('id')->firstOrFail();
    }

    private function attemptCampaign(string $endDate, string $network = 'preprod')
    {
        return $this->actingAs(User::factory()->create())
            ->post(route('campaigns.store'), [
                'name' => 'Authorisation '.uniqid(),
                'description' => 'x',
                'start_date' => CarbonImmutable::now()->toDateString(),
                'end_date' => $endDate,
                'network' => $network,
            ]);
    }

    /**
     * A deployment on the native backend that is missing settings refuses to create a campaign,
     * and the message names every missing variable.
     */
    public function test_a_campaign_is_refused_naming_each_missing_variable(): void
    {
        config([
            'campaign_script.owner_key_hashes' => [str_repeat('01', 28), null],
            'campaign_script.wrapping_keys' => [],
            'campaign_script.stake_addresses' => ['preprod' => null],
        ]);

        $response = $this->attemptCampaign(CarbonImmutable::now()->addDays(10)->toDateString());

        $response->assertSessionHasErrors('network');
        $message = session('errors')->first('network');

        foreach (['CAMPAIGN_OWNER_KEY_HASH_2', 'CAMPAIGN_WRAPPING_KEY_1', 'CAMPAIGN_STAKE_ADDRESS_PREPROD'] as $variable) {
            $this->assertStringContainsString($variable, $message);
        }

        $this->assertStringNotContainsString('CAMPAIGN_OWNER_KEY_HASH_1', $message);
        $this->assertSame(0, Campaign::count());
    }

    /**
     * A recovery key hash that is not 56 lowercase hex characters, or is the same as the other, is
     * refused at creation by name, not later by a job nobody is watching.
     */
    public function test_a_malformed_recovery_hash_is_refused_at_creation_by_name(): void
    {
        config(['campaign_script.owner_key_hashes' => [strtoupper(str_repeat('ab', 28)), str_repeat('02', 28)]]);

        $this->attemptCampaign(CarbonImmutable::now()->addDays(10)->toDateString())->assertSessionHasErrors('network');

        $this->assertStringContainsString('CAMPAIGN_OWNER_KEY_HASH_1', session('errors')->first('network'));
        $this->assertSame(0, Campaign::count());
    }

    /**
     * A date the chain's timeline cannot place, or a provider that cannot be reached, is a
     * message against the end date and not an error page.
     */
    public function test_a_date_that_cannot_be_placed_on_the_chain_is_a_validation_error(): void
    {
        $user = User::factory()->create();

        // An end date a year in the past puts the expiry before the tip.
        $this->actingAs($user)->post(route('campaigns.store'), [
            'name' => 'Past', 'description' => 'x',
            'start_date' => CarbonImmutable::now()->subDays(400)->toDateString(),
            'end_date' => CarbonImmutable::now()->subDays(300)->toDateString(),
            'network' => 'preprod',
        ])->assertSessionHasErrors('end_date');

        $this->app->bind(ChainData::class, fn () => new class extends ChainData
        {
            public function __construct() {}

            public function tip(string $network): array
            {
                throw new \CardanoPhp\DataClient\Exceptions\ProviderException('the provider is down');
            }
        });

        $this->attemptCampaign(CarbonImmutable::now()->addDays(10)->toDateString())->assertSessionHasErrors('end_date');
        $this->assertSame(0, Campaign::count());
    }

    /**
     * An end date written with an offset is the same instant in UTC, and the expiry is derived
     * from the end of that UTC day, the one convention every address is built on.
     */
    public function test_an_end_date_with_an_offset_is_converted_to_utc_before_the_expiry_is_derived(): void
    {
        $local = CarbonImmutable::now()->addDays(10)->setTimezone('America/Los_Angeles')->setTime(23, 0);

        $campaign = $this->createCampaign($local->format(DATE_ATOM));

        $this->assertSame(
            CarbonImmutable::parse($local->format(DATE_ATOM))->utc()->endOfDay()->addDays(90)->getTimestamp(),
            CarbonImmutable::parse($campaign->expiry_at)->getTimestamp(),
        );
    }

    /**
     * The expiry is inside the campaign's address, so once set it cannot be changed.
     */
    public function test_a_campaigns_expiry_slot_cannot_be_changed_once_set(): void
    {
        $campaign = $this->createCampaign(CarbonImmutable::now()->addDays(10)->toDateString());

        $slot = $campaign->expiry_slot;

        try {
            $campaign->update(['expiry_slot' => $slot + 1]);
            $this->fail('The expiry slot was changed.');
        } catch (\LogicException) {
            $this->assertSame($slot, $campaign->fresh()->expiry_slot);
        }

        $campaign = $campaign->fresh();
        $campaign->update(['name' => 'Renamed']);
        $this->assertSame('Renamed', $campaign->fresh()->name);
    }

    /**
     * A campaign created while native wallets are on knows when its authorisation ends.
     *
     * Without this a wallet can never be built for it: the factory refuses a campaign with no
     * expiry slot, because defaulting one would produce an address that could not be reproduced
     * from the campaign afterwards.
     */
    public function test_a_campaign_is_created_with_the_window_its_address_will_carry(): void
    {
        $end = CarbonImmutable::now()->addDays(10);
        $campaign = $this->createCampaign($end->toDateString());

        $this->assertNotNull($campaign->expiry_slot);
        $this->assertNotNull($campaign->expiry_at);
        $this->assertSame(CampaignScript::FUNDING_CREDIT, $campaign->funding_path);

        // The expiry is the campaign's end plus the grace settlement needs.
        $this->assertSame(
            $end->endOfDay()->addDays(90)->toDateString(),
            CarbonImmutable::parse($campaign->expiry_at)->toDateString(),
        );

        // And the slot agrees with the date, measured from the tip the chain reported.
        $this->assertSame(
            self::TIP_SLOT + (CarbonImmutable::parse($campaign->expiry_at)->getTimestamp() - CarbonImmutable::now()->getTimestamp()),
            $campaign->expiry_slot,
        );
    }

    /**
     * Nothing is derived while the feature is off, and a campaign is still created.
     *
     * The window only means anything to a campaign that holds its own funds. A deployment on the
     * hosted backend should not start failing to create campaigns because a chain-data provider is
     * unreachable for a figure it will never use.
     */
    public function test_a_campaign_is_created_without_a_window_while_the_feature_is_off(): void
    {
        config(['cardano.transaction_backend' => 'null']);

        $campaign = $this->createCampaign(CarbonImmutable::now()->addDays(10)->toDateString());

        $this->assertNull($campaign->expiry_slot);
        $this->assertNull($campaign->expiry_at);
    }

    /**
     * An end date inside the authorisation is accepted.
     */
    public function test_an_end_date_inside_the_window_is_accepted(): void
    {
        $campaign = $this->createCampaign(CarbonImmutable::now()->addDays(30)->toDateString());
        $user = $campaign->user;

        $this->actingAs($user)
            ->put(route('campaigns.update', $campaign->id), [
                'name' => $campaign->name,
                'description' => 'x',
                'start_date' => CarbonImmutable::now()->toDateString(),
                'end_date' => CarbonImmutable::now()->addDays(20)->toDateString(),
                'network' => 'preprod',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            CarbonImmutable::now()->addDays(20)->toDateString(),
            CarbonImmutable::parse($campaign->fresh()->end_date)->toDateString(),
        );
    }

    /**
     * An end date past the authorisation is refused, and says why in terms of dates.
     *
     * The alternative is a campaign that accepts a claim it cannot pay: the code is valid, the
     * attendee scans it, and the payout fails because the script branch that signs for it expired.
     */
    public function test_an_end_date_past_the_authorisation_is_refused(): void
    {
        $campaign = $this->createCampaign(CarbonImmutable::now()->addDays(10)->toDateString());
        $user = $campaign->user;
        $originalEnd = $campaign->end_date;

        $this->actingAs($user)
            ->put(route('campaigns.update', $campaign->id), [
                'name' => $campaign->name,
                'description' => 'x',
                'start_date' => CarbonImmutable::now()->toDateString(),
                // Past the expiry, which was the original end plus ninety days of grace.
                'end_date' => CarbonImmutable::now()->addDays(200)->toDateString(),
                'network' => 'preprod',
            ])
            ->assertSessionHasErrors('end_date');

        $this->assertSame(
            CarbonImmutable::parse($originalEnd)->toDateString(),
            CarbonImmutable::parse($campaign->fresh()->end_date)->toDateString(),
            'The end date moved despite the update being refused.'
        );
    }

    /**
     * A campaign created before this existed is still editable.
     *
     * Every campaign on the hosted backend has no expiry, and none of them should become read-only
     * because a feature they do not use was added.
     */
    public function test_a_campaign_with_no_window_is_not_capped(): void
    {
        config(['cardano.transaction_backend' => 'null']);

        $campaign = $this->createCampaign(CarbonImmutable::now()->addDays(10)->toDateString());

        $this->actingAs($campaign->user)
            ->put(route('campaigns.update', $campaign->id), [
                'name' => $campaign->name,
                'description' => 'x',
                'start_date' => CarbonImmutable::now()->toDateString(),
                'end_date' => CarbonImmutable::now()->addYears(2)->toDateString(),
                'network' => 'preprod',
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * A network with no platform stake address configured refuses the campaign, and names the
     * variable to set. The stake credential is inside the address, so the alternative is either
     * an address that earns nothing for its whole life or a failure in a job nobody is watching.
     */
    public function test_a_campaign_is_refused_without_a_stake_address_for_its_network(): void
    {
        config(['campaign_script.stake_addresses.preprod' => null]);

        $response = $this->attemptCampaign(CarbonImmutable::now()->addDays(10)->toDateString());

        $response->assertSessionHasErrors('network');
        $this->assertStringContainsString(
            'CAMPAIGN_STAKE_ADDRESS_PREPROD',
            session('errors')->first('network'),
        );
        $this->assertSame(0, Campaign::count());
        Queue::assertNotPushed(CreateCampaignBucket::class);
    }

    /**
     * A stake address for the wrong network is refused the same way. Its credential would be a
     * real one, but not one anybody stakes with on this chain.
     */
    public function test_a_stake_address_from_another_network_is_refused(): void
    {
        config(['campaign_script.stake_addresses.preprod' => self::MAINNET_STAKE]);

        $this->attemptCampaign(CarbonImmutable::now()->addDays(10)->toDateString())
            ->assertSessionHasErrors('network');

        $this->assertSame(0, Campaign::count());
    }

    /**
     * The authorisation is bounded at creation, not only on edit. An end date whose expiry would
     * fall past the authorisation window is refused, with the latest end date that fits.
     */
    public function test_an_end_date_past_the_authorisation_window_is_refused_at_creation(): void
    {
        // 180 days of authorisation less 90 of grace leaves 90 days of campaign.
        $response = $this->attemptCampaign(CarbonImmutable::now()->addDays(91)->toDateString());

        $response->assertSessionHasErrors('end_date');
        $this->assertStringContainsString(
            CarbonImmutable::now()->addDays(90)->toFormattedDateString(),
            session('errors')->first('end_date'),
        );
        $this->assertSame(0, Campaign::count());

        $this->createCampaign(CarbonImmutable::now()->addDays(90)->toDateString());
        $this->assertSame(1, Campaign::count());
    }

    /**
     * A deployment whose configured backend is a hosted one creates campaigns exactly as before,
     * with no expiry and no chain read, even with native wallets enabled. Such a campaign's wallet
     * is stamped with the hosted backend for life, so a window would describe nothing.
     */
    public function test_a_campaign_on_a_hosted_backend_gets_no_window(): void
    {
        config(['cardano.transaction_backend' => 'phyrhose']);

        $this->app->bind(ChainData::class, fn () => new class extends ChainData
        {
            public function __construct() {}

            public function tip(string $network): array
            {
                throw new \RuntimeException('A hosted campaign read the chain tip.');
            }
        });

        $campaign = $this->createCampaign(CarbonImmutable::now()->addDays(10)->toDateString());

        $this->assertNull($campaign->expiry_slot);
        $this->assertNull($campaign->funding_path);
    }

    /**
     * A deployment limited to test networks cannot create a mainnet campaign, native or not. The
     * network is refused by validation before anything is derived or dispatched.
     */
    public function test_a_mainnet_campaign_is_refused_on_a_test_network_deployment(): void
    {
        config(['cardano.allowed_networks' => ['preprod', 'preview']]);

        $this->attemptCampaign(CarbonImmutable::now()->addDays(10)->toDateString(), 'mainnet')
            ->assertSessionHasErrors('network');

        $this->assertSame(0, Campaign::count());
        $this->assertSame(0, Wallet::count());
        Queue::assertNotPushed(CreateCampaignBucket::class);
    }
}

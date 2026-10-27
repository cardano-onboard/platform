<?php

namespace Tests\Feature;

use App\Cardano\CampaignKeyVault;
use App\Cardano\CampaignScript;
use App\Cardano\NativeWalletFactory;
use App\Models\Campaign;
use App\Models\Wallet;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\BaseAddress;
use Cardano\Transaction\Address\Credential;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Address\RewardAddress;
use Cardano\Transaction\Signing\SigningKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Making a campaign's wallet, and the things that must not happen while doing so.
 */
class NativeWalletFactoryTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_ONE = '00000000000000000000000000000000000000000000000000000001';

    private const OWNER_TWO = '00000000000000000000000000000000000000000000000000000002';

    /** The CIP-19 test vector reward address (type 14, testnet), standing in for the platform's. */
    private const PLATFORM_STAKE = 'stake_test1uqehkck0lajq8gr28t9uxnuvgcqrc6070x3k9r8048z8y5gssrtvn';

    /** A different stake key, standing in for the one a customer's wallet would give. */
    private const CUSTOMER_STAKE_HASH = '5555555555555555555555555555555555555555555555555555555a';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'campaign_script.owner_key_hashes' => [self::OWNER_ONE, self::OWNER_TWO],
            'campaign_script.wrapping_key_version' => 1,
            'campaign_script.wrapping_keys' => [1 => base64_encode(random_bytes(32))],
            'campaign_script.stake_addresses' => ['preprod' => self::PLATFORM_STAKE, 'mainnet' => null, 'preview' => null],
        ]);
    }

    private function factory(): NativeWalletFactory
    {
        return new NativeWalletFactory(new CampaignKeyVault);
    }

    private function campaign(array $attributes = []): Campaign
    {
        return Campaign::factory()->create($attributes + [
            'network' => 'preprod',
            'expiry_slot' => 140000000,
            'funding_path' => CampaignScript::FUNDING_CREDIT,
        ]);
    }

    public function test_a_wallet_carries_everything_a_recovery_needs(): void
    {
        $wallet = $this->factory()->forCampaign($this->campaign());

        $this->assertSame('native', $wallet->backend);
        $this->assertStringStartsWith('addr_test1', $wallet->address);
        $this->assertNotNull($wallet->skey);
        $this->assertSame(1, $wallet->wrapping_key_version);

        // The address the wallet stores is the one the record rebuilds to, with nothing else to
        // hand. If these ever disagree, the campaign's funds are at an address the recovery record
        // does not describe.
        $rebuilt = CampaignScript::fromRecord($wallet->recovery_record);
        $stake = CampaignScript::stakeCredential($wallet->recovery_record['stake_address'], Network::Testnet);
        $this->assertSame($wallet->address, $rebuilt->address(Network::Testnet, $stake));
        $this->assertSame($wallet->recovery_record['script_hash'], $rebuilt->scriptHashHex());
    }

    /**
     * The recovery record names the network the campaign is on, not just mainnet or test, so a
     * recovery knows which chain to submit to.
     */
    public function test_the_recovery_record_names_the_actual_network(): void
    {
        $wallet = $this->factory()->forCampaign($this->campaign(['network' => 'preprod']));

        $this->assertSame('preprod', $wallet->recovery_record['network']);
    }

    /**
     * A credit-funded campaign's address carries the platform's configured stake credential, and
     * nothing else: read back from the stored address, it is a base address whose delegation part
     * is exactly that credential.
     */
    public function test_a_credit_funded_address_carries_the_platform_stake_credential(): void
    {
        $wallet = $this->factory()->forCampaign($this->campaign());

        $decoded = Address::fromBech32($wallet->address);

        $this->assertInstanceOf(BaseAddress::class, $decoded);
        $this->assertSame($wallet->recovery_record['script_hash'], $decoded->payment->hex());
        $this->assertSame(self::PLATFORM_STAKE, $decoded->rewardAddress()->toBech32());
        $this->assertSame(self::PLATFORM_STAKE, $wallet->recovery_record['stake_address']);
    }

    /**
     * No stake address configured for the campaign's network means no wallet, and the refusal
     * names the variable. Nothing falls back to an address without a delegation part.
     */
    public function test_no_wallet_is_made_without_a_stake_address_for_the_network(): void
    {
        config(['campaign_script.stake_addresses.preprod' => null]);

        try {
            $this->factory()->forCampaign($this->campaign());
            $this->fail('A wallet was made with no stake credential configured.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CAMPAIGN_STAKE_ADDRESS_PREPROD', $e->getMessage());
        }

        $this->assertSame(0, Wallet::count());
    }

    /**
     * A customer-funded campaign carries the stake credential of the wallet the customer
     * connected, not the platform's. This is the seam the wallet-connect flow fills.
     */
    public function test_a_customer_funded_address_carries_the_customers_stake_credential(): void
    {
        $customerStake = RewardAddress::of(Network::Testnet, Credential::keyHash(self::CUSTOMER_STAKE_HASH))->toBech32();

        $wallet = $this->factory()->forCampaign($this->campaign([
            'funding_path' => CampaignScript::FUNDING_CUSTOMER,
            'customer_key_hash' => str_repeat('44', 28),
            'customer_reward_address' => $customerStake,
        ]));

        $decoded = Address::fromBech32($wallet->address);

        $this->assertInstanceOf(BaseAddress::class, $decoded);
        $this->assertSame(self::CUSTOMER_STAKE_HASH, $decoded->delegation->hex());
        $this->assertSame($customerStake, $wallet->recovery_record['stake_address']);
    }

    /**
     * A customer-funded campaign with no connected wallet's reward address gets no wallet.
     */
    public function test_a_customer_funded_campaign_needs_its_customers_reward_address(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no reward address');

        $this->factory()->forCampaign($this->campaign([
            'funding_path' => CampaignScript::FUNDING_CUSTOMER,
            'customer_key_hash' => str_repeat('44', 28),
            'customer_reward_address' => null,
        ]));
    }

    /**
     * The stored secret decrypts to the key whose hash is in the script.
     *
     * Everything else could be right and this wrong: the address would be real, the record would
     * rebuild, and the campaign would be unable to sign for a single thing.
     */
    public function test_the_stored_secret_is_the_key_the_script_names(): void
    {
        $wallet = $this->factory()->forCampaign($this->campaign());

        $seed = (new CampaignKeyVault)->unwrap($wallet->skey, $wallet->wrapping_key_version);
        $key = SigningKey::fromSeed($seed);

        $this->assertSame($wallet->vkey, $key->publicKeyHex());
        $this->assertStringContainsString(
            $key->credential()->hex(),
            json_encode($wallet->recovery_record['script']),
            'The stored key is not the one the script names, so this campaign cannot sign for its own funds.'
        );
    }

    /**
     * A campaign gets one wallet however many times it is asked for.
     */
    public function test_asking_twice_gives_the_same_wallet(): void
    {
        $campaign = $this->campaign();
        $factory = $this->factory();

        $first = $factory->forCampaign($campaign);
        $second = $factory->forCampaign($campaign->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->address, $second->address);
        $this->assertSame(1, Wallet::where('campaign_id', $campaign->id)->count());
    }

    /**
     * And the database says so too, not only the code.
     *
     * The check before the write is two statements with a gap between them that two workers can
     * both pass. This is what closes it, and it is what makes a second address impossible rather
     * than unlikely.
     */
    public function test_a_second_wallet_for_one_campaign_is_refused_by_the_database(): void
    {
        $campaign = $this->campaign();
        $this->factory()->forCampaign($campaign);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Wallet::create([
            'campaign_id' => $campaign->id,
            'address' => 'addr_test1somethingelse',
            'key' => $campaign->id,
            'backend' => 'native',
        ]);
    }

    /**
     * A failure during creation leaves no wallet and no key behind.
     *
     * This is the window the whole class exists for. If a row survived a failed attempt, the
     * retry would find it and use it; if a key survived without a row, the retry would make a
     * different one and the first address would be unreachable.
     */
    public function test_a_failure_part_way_through_leaves_nothing_behind(): void
    {
        $campaign = $this->campaign();

        DB::listen(function ($query) {
            // Either quoting, so the kill happens on MySQL as well as SQLite. Matching one
            // spelling only would let the insert through on the other and prove nothing.
            if (preg_match('/insert into [`"]wallets[`"]/', $query->sql) === 1) {
                throw new RuntimeException('killed between generating and committing');
            }
        });

        try {
            $this->factory()->forCampaign($campaign);
            $this->fail('The insert was supposed to fail.');
        } catch (RuntimeException $e) {
            $this->assertSame('killed between generating and committing', $e->getMessage());
        }

        $this->assertSame(0, Wallet::where('campaign_id', $campaign->id)->count());
    }

    /**
     * A deployment missing its owner hashes creates nothing, rather than creating something
     * nobody can recover.
     */
    public function test_a_campaign_is_not_created_without_both_owner_hashes(): void
    {
        config(['campaign_script.owner_key_hashes' => [self::OWNER_ONE]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CAMPAIGN_OWNER_KEY_HASH_2');

        $this->factory()->forCampaign($this->campaign());
    }

    /**
     * A deployment missing several settings is told about every one of them in one message, by the
     * name of the variable, and nothing is generated.
     */
    public function test_every_missing_setting_is_named(): void
    {
        config([
            'campaign_script.owner_key_hashes' => [null, null],
            'campaign_script.wrapping_keys' => [],
            'campaign_script.stake_addresses' => ['preprod' => null],
        ]);

        try {
            $this->factory()->forCampaign($this->campaign());
            $this->fail('A campaign was created with nothing configured.');
        } catch (RuntimeException $e) {
            foreach (['CAMPAIGN_OWNER_KEY_HASH_1', 'CAMPAIGN_OWNER_KEY_HASH_2', 'CAMPAIGN_WRAPPING_KEY_1', 'CAMPAIGN_STAKE_ADDRESS_PREPROD'] as $variable) {
                $this->assertStringContainsString($variable, $e->getMessage());
            }
        }

        $this->assertSame(0, Wallet::count());
    }

    /**
     * A deployment with no wrapping key generates nothing.
     *
     * The order matters: refused before a key exists, not after one has been made and cannot be
     * stored readably.
     */
    public function test_nothing_is_generated_without_a_wrapping_key(): void
    {
        config(['campaign_script.wrapping_keys' => []]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CAMPAIGN_WRAPPING_KEY_1');

        $this->factory()->forCampaign($this->campaign());
    }

    /**
     * A campaign with no expiry slot gets no address.
     *
     * The slot is inside the script and therefore inside the address. Defaulting it would produce
     * an address that could not be reproduced from the campaign afterwards.
     */
    public function test_a_campaign_without_an_expiry_slot_gets_no_address(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expiry slot');

        $this->factory()->forCampaign($this->campaign(['expiry_slot' => null]));
    }

    /**
     * A customer-funded campaign with no customer hash is refused.
     *
     * The alternative is a script missing the branch that lets the customer take their own money
     * back, which is the one thing that shape exists to guarantee.
     */
    public function test_a_customer_funded_campaign_needs_its_customer_hash(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('take their own money back');

        $this->factory()->forCampaign($this->campaign([
            'funding_path' => CampaignScript::FUNDING_CUSTOMER,
            'customer_key_hash' => null,
            'customer_reward_address' => RewardAddress::of(Network::Testnet, Credential::keyHash(self::CUSTOMER_STAKE_HASH))->toBech32(),
        ]));
    }

    /**
     * The secret does not reach anything that serialises a wallet.
     */
    public function test_a_serialised_wallet_carries_no_key_material(): void
    {
        $wallet = $this->factory()->forCampaign($this->campaign());
        $serialised = json_encode($wallet->toArray());

        foreach (['skey', 'vkey', 'wrapping_key_version', 'recovery_record'] as $field) {
            $this->assertStringNotContainsString(
                $field,
                $serialised,
                "A serialised wallet carries {$field}, so it reaches anything that renders one."
            );
        }

        $this->assertStringNotContainsString($wallet->skey, $serialised);
    }
}

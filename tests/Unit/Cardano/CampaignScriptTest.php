<?php

namespace Tests\Unit\Cardano;

use App\Cardano\CampaignScript;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\BaseAddress;
use Cardano\Transaction\Address\Credential;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Signing\SigningKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The shape of a campaign's script, frozen.
 *
 * A campaign's address is the hash of its script, so any change to the shape moves the address
 * of every campaign created afterwards, and there is no migration from one address to another
 * once funds have arrived. The addresses asserted below are therefore not illustrative. They
 * are the thing being held still, and a change to any of them is a change nobody can undo for
 * campaigns already created.
 *
 * The keys are derived from fixed seeds so the fixtures mean something. Real campaign keys come
 * from the system CSPRNG and are never reproducible.
 */
class CampaignScriptTest extends TestCase
{
    private const CAMPAIGN_SEED = '11111111111111111111111111111111';

    private const OWNER_ONE_SEED = '22222222222222222222222222222222';

    private const OWNER_TWO_SEED = '33333333333333333333333333333333';

    private const CUSTOMER_SEED = '44444444444444444444444444444444';

    private const EXPIRY_SLOT = 140000000;

    /**
     * The stake credential in the CIP-19 test vectors (CIP-19, "Test Vectors", type-14), as the
     * reward address a deployment would configure on each network.
     */
    private const STAKE_TESTNET = 'stake_test1uqehkck0lajq8gr28t9uxnuvgcqrc6070x3k9r8048z8y5gssrtvn';

    private const STAKE_MAINNET = 'stake1uyehkck0lajq8gr28t9uxnuvgcqrc6070x3k9r8048z8y5gh6ffgw';

    /** The stake key hash those two reward addresses carry. */
    private const STAKE_KEY_HASH = '337b62cfff6403a06a3acbc34f8c46003c69fe79a3628cefa9c47251';

    private static function stake(Network $network = Network::Testnet): Credential
    {
        return CampaignScript::stakeCredential(
            $network->isMainnet() ? self::STAKE_MAINNET : self::STAKE_TESTNET,
            $network,
        );
    }

    private static function hash(string $seed): string
    {
        return SigningKey::fromSeed($seed)->credential()->hex();
    }

    private static function creditFunded(): CampaignScript
    {
        return CampaignScript::creditFunded(
            self::hash(self::CAMPAIGN_SEED),
            [self::hash(self::OWNER_ONE_SEED), self::hash(self::OWNER_TWO_SEED)],
            self::EXPIRY_SLOT,
        );
    }

    private static function customerFunded(): CampaignScript
    {
        return CampaignScript::customerFunded(
            self::hash(self::CAMPAIGN_SEED),
            [self::hash(self::OWNER_ONE_SEED), self::hash(self::OWNER_TWO_SEED)],
            self::EXPIRY_SLOT,
            self::hash(self::CUSTOMER_SEED),
        );
    }

    /**
     * The credit-funded shape: the owners hold the unbounded right, because the money was ours.
     */
    public function test_the_credit_funded_script_has_the_shape_it_is_supposed_to(): void
    {
        $script = self::creditFunded()->script()->toArray();

        $this->assertSame('any', $script['type']);
        $this->assertCount(3, $script['scripts']);

        // Two unbounded owner signatures, either of which can recover the campaign with no
        // deadline and without the platform existing.
        $this->assertSame('sig', $script['scripts'][0]['type']);
        $this->assertSame(self::hash(self::OWNER_ONE_SEED), $script['scripts'][0]['keyHash']);
        $this->assertSame('sig', $script['scripts'][1]['type']);
        $this->assertSame(self::hash(self::OWNER_TWO_SEED), $script['scripts'][1]['keyHash']);

        // And the platform's own branch, which lapses at the expiry slot.
        $timed = $script['scripts'][2];
        $this->assertSame('all', $timed['type']);
        $this->assertSame('sig', $timed['scripts'][0]['type']);
        $this->assertSame(self::hash(self::CAMPAIGN_SEED), $timed['scripts'][0]['keyHash']);
        $this->assertSame('before', $timed['scripts'][1]['type']);
        $this->assertSame(self::EXPIRY_SLOT, $timed['scripts'][1]['slot']);
    }

    /**
     * The customer-funded shape: the customer holds it, because the money is theirs.
     */
    public function test_the_customer_funded_script_has_the_shape_it_is_supposed_to(): void
    {
        $script = self::customerFunded()->script()->toArray();

        $this->assertSame('any', $script['type']);
        $this->assertCount(2, $script['scripts']);

        // The customer, with no deadline.
        $this->assertSame('sig', $script['scripts'][0]['type']);
        $this->assertSame(self::hash(self::CUSTOMER_SEED), $script['scripts'][0]['keyHash']);

        // And anyone who runs the campaign, until expiry. The platform key and both owners sit
        // inside the timed branch here, which is what stops any of them spending a customer's
        // money after the authorisation has lapsed.
        $timed = $script['scripts'][1];
        $this->assertSame('all', $timed['type']);
        $this->assertSame('any', $timed['scripts'][0]['type']);

        $inner = array_column($timed['scripts'][0]['scripts'], 'keyHash');
        $this->assertSame([
            self::hash(self::CAMPAIGN_SEED),
            self::hash(self::OWNER_ONE_SEED),
            self::hash(self::OWNER_TWO_SEED),
        ], $inner);

        $this->assertSame('before', $timed['scripts'][1]['type']);
        $this->assertSame(self::EXPIRY_SLOT, $timed['scripts'][1]['slot']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bothShapes(): array
    {
        return [
            'credit-funded' => [CampaignScript::FUNDING_CREDIT],
            'customer-funded' => [CampaignScript::FUNDING_CUSTOMER],
        ];
    }

    /**
     * The addresses themselves, frozen.
     *
     * If one of these changes, a campaign created before the change and one created after it
     * hold different addresses for the same inputs, and the earlier one is unreachable by the
     * later code. Nothing about that is recoverable, so this test failing is a reason to stop
     * rather than a reason to update the expected value.
     */
    public function test_the_addresses_are_frozen(): void
    {
        $this->assertSame(
            'addr_test1zqw9hzeultyhuwccyvgy0munx077ujvn8c0qw3k7cnsgzgpn0d3vllmyqwsx5wktcd8cc3sq835lu7drv2xwl2wywfgswkllps',
            self::creditFunded()->address(Network::Testnet, self::stake()),
        );

        $this->assertSame(
            'addr1zyw9hzeultyhuwccyvgy0munx077ujvn8c0qw3k7cnsgzgpn0d3vllmyqwsx5wktcd8cc3sq835lu7drv2xwl2wywfgsdqzld0',
            self::creditFunded()->address(Network::Mainnet, self::stake(Network::Mainnet)),
        );

        $this->assertSame(
            'addr_test1zr45ds8m3y6mgk5e5nfzmhcz90q3f8ys2p3xmtl4legf25en0d3vllmyqwsx5wktcd8cc3sq835lu7drv2xwl2wywfgsrlckkm',
            self::customerFunded()->address(Network::Testnet, self::stake()),
        );

        // The script hash is what the address is made of, so it is frozen beside them: an
        // address that changed because the hash changed and one that changed because the
        // encoding changed are different problems.
        $this->assertSame(
            '1c5b8b3cfac97e3b18231047ef9333fdee49933e1e0746dec4e08120',
            self::creditFunded()->scriptHashHex(),
        );
    }

    /**
     * A derived address decodes back to a base address carrying the script as its payment part
     * and exactly the configured stake credential as its delegation part.
     *
     * Read back from the bech32 string, through the header byte, the way a node or a wallet reads
     * it: CIP-19, "Binary format" and "Shelley Addresses", header type 1 is a script hash payment
     * part with a key hash delegation part, and the low four bits are the network tag.
     */
    #[DataProvider('bothNetworks')]
    public function test_a_derived_address_is_a_base_address_carrying_the_configured_stake_credential(Network $network): void
    {
        $script = self::creditFunded();
        $bech32 = $script->address($network, self::stake($network));

        $this->assertStringStartsWith($network->isMainnet() ? 'addr1' : 'addr_test1', $bech32);

        $decoded = Address::fromBech32($bech32);

        $this->assertInstanceOf(BaseAddress::class, $decoded);
        $this->assertSame(Address::TYPE_BASE_SCRIPT_KEY, $decoded->type());
        $this->assertSame((Address::TYPE_BASE_SCRIPT_KEY << 4) | $network->value, ord($decoded->toBytes()[0]));
        $this->assertSame($network, $decoded->network);

        $this->assertTrue($decoded->payment->isScript());
        $this->assertSame($script->scriptHashHex(), $decoded->payment->hex());

        $this->assertFalse($decoded->delegation->isScript());
        $this->assertSame(self::STAKE_KEY_HASH, $decoded->delegation->hex());
        $this->assertSame(
            $network->isMainnet() ? self::STAKE_MAINNET : self::STAKE_TESTNET,
            $decoded->rewardAddress()->toBech32(),
        );
    }

    /**
     * @return array<string, array{Network}>
     */
    public static function bothNetworks(): array
    {
        return ['testnet' => [Network::Testnet], 'mainnet' => [Network::Mainnet]];
    }

    /**
     * Only a stake address on the campaign's own network is accepted as its stake credential.
     */
    public function test_anything_but_a_stake_address_on_the_same_network_is_refused(): void
    {
        foreach ([
            'a payment address' => [self::creditFunded()->address(Network::Testnet, self::stake()), Network::Testnet],
            'another network' => [self::STAKE_MAINNET, Network::Testnet],
            'not bech32' => ['stake_test1notanaddress', Network::Testnet],
        ] as $case => [$value, $network]) {
            try {
                CampaignScript::stakeCredential($value, $network);
                $this->fail("A stake credential was read out of {$case}.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * A recovery record rebuilds the address with nothing else to hand.
     *
     * The record is what a recovery has when the application is gone, so it has to be enough on
     * its own. Rebuilding through CampaignScript::fromRecord uses no configuration and no stored
     * model, which is the whole property.
     */
    #[DataProvider('bothShapes')]
    public function test_a_recovery_record_rebuilds_its_own_address(string $funding): void
    {
        $original = $funding === CampaignScript::FUNDING_CREDIT ? self::creditFunded() : self::customerFunded();
        $record = $original->recoveryRecord(Network::Testnet, self::stake(), 1);

        // Through a round trip of JSON, because that is how the record reaches a recovery: as a
        // file somebody downloaded, not as an object still in memory.
        $rebuilt = CampaignScript::fromRecord(
            json_decode(json_encode($record, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)
        );

        $stake = CampaignScript::stakeCredential($record['stake_address'], Network::Testnet);

        $this->assertSame($original->address(Network::Testnet, self::stake()), $rebuilt->address(Network::Testnet, $stake));
        $this->assertSame($original->scriptHashHex(), $rebuilt->scriptHashHex());
        $this->assertSame($record['address'], $rebuilt->address(Network::Testnet, $stake));
    }

    /**
     * A record that cannot rebuild its script is refused rather than half read.
     */
    public function test_a_customer_funded_record_without_its_customer_hash_is_refused(): void
    {
        $record = self::customerFunded()->recoveryRecord(Network::Testnet, self::stake(), 1);
        unset($record['customer_key_hash']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be rebuilt');

        CampaignScript::fromRecord($record);
    }

    /**
     * Things that would build a real address that is nonetheless the wrong one.
     *
     * Each of these produces a script that hashes to something, and an address nobody holds the
     * keys for. They are refused at construction because there is no later point at which the
     * mistake becomes visible: the address looks exactly like a correct one.
     */
    public function test_a_script_that_would_be_unrecoverable_is_refused(): void
    {
        $campaign = self::hash(self::CAMPAIGN_SEED);
        $one = self::hash(self::OWNER_ONE_SEED);
        $two = self::hash(self::OWNER_TWO_SEED);

        // One owner is not two holders.
        try {
            CampaignScript::creditFunded($campaign, [$one], self::EXPIRY_SLOT);
            $this->fail('A script with one owner hash was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exactly two owner key hashes', $e->getMessage());
        }

        // The same holder twice is also not two holders.
        try {
            CampaignScript::creditFunded($campaign, [$one, $one], self::EXPIRY_SLOT);
            $this->fail('A script naming one holder twice was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('one recovery holder rather than two', $e->getMessage());
        }

        // The campaign key as an owner puts the per-campaign key on the unbounded branch, so the
        // campaign stays spendable by the platform after its authorisation has expired.
        try {
            CampaignScript::creditFunded($campaign, [$campaign, $two], self::EXPIRY_SLOT);
            $this->fail('A script with the campaign key as an owner was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('spendable after its expiry', $e->getMessage());
        }

        // A verification key is 32 bytes and a key hash is 28. Passing the wrong one builds a
        // perfectly valid script for an address nobody can spend.
        try {
            CampaignScript::creditFunded(SigningKey::fromSeed(self::CAMPAIGN_SEED)->publicKeyHex(), [$one, $two], self::EXPIRY_SLOT);
            $this->fail('A verification key was accepted where a key hash belongs.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('56 lowercase hex characters', $e->getMessage());
        }
    }
}

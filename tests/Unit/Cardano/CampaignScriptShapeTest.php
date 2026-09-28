<?php

namespace Tests\Unit\Cardano;

use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\Credential;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Script\NativeScript;
use Cardano\Transaction\Time\EraSummaries;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * The two frozen campaign script shapes, rebuilt with the code in src/ rather than the encoder the freeze was
 * written against.
 *
 * The shapes, their CBOR, their script hashes and their four addresses each were settled before any of this code
 * existed. Every expected value below was derived without running the code that computes it: the CBOR was written out
 * by hand from the Shelley-MA ledger CDDL, the hashes come from a reference BLAKE2b rather than from libsodium, and
 * the addresses come from the BIP-173 reference bech32 implementation.
 *
 * An address has no migration. If an assertion in this file has to be changed, every address already derived under
 * the old value is a different address, and the funds behind it stay where they are. So this file is not a record of
 * what the code does; it is the thing the code has to keep doing.
 */
class CampaignScriptShapeTest extends TestCase
{
    // ---------------------------------------------------------------- inputs

    private const CUSTOMER = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b';

    private const CAMPAIGN_KEY = '202122232425262728292a2b2c2d2e2f303132333435363738393a3b';

    private const OWNER_1 = '404142434445464748494a4b4c4d4e4f505152535455565758595a5b';

    private const OWNER_2 = '606162636465666768696a6b6c6d6e6f707172737475767778797a7b';

    private const STAKE = '808182838485868788898a8b8c8d8e8f909192939495969798999a9b';

    /** Campaign end date 2026-12-31, read at 23:59:59 UTC, plus ninety days, on mainnet. */
    private const EXPIRY_SLOT = 214971308;

    /** The same instant before it is converted: 2027-03-31T23:59:59Z. */
    private const EXPIRY_INSTANT = 1806537599;

    // ---------------------------------------------------------------- frozen

    private const CUSTOMER_FUNDED_CBOR =
        '820282'
        .'8200581c000102030405060708090a0b0c0d0e0f101112131415161718191a1b'
        .'820182'
        .'820283'
        .'8200581c202122232425262728292a2b2c2d2e2f303132333435363738393a3b'
        .'8200581c404142434445464748494a4b4c4d4e4f505152535455565758595a5b'
        .'8200581c606162636465666768696a6b6c6d6e6f707172737475767778797a7b'
        .'82051a0cd033ac';

    private const CUSTOMER_FUNDED_HASH = '6b24d2536e5a865eaaa9ec2537ecb68f5bf5b06356ddbf81c8f9ab03';

    private const CUSTOMER_FUNDED_ENTERPRISE_MAINNET =
        'addr1w94jf5jndedgvh4248kz2dlvk684hadsvdtdm0uperu6kqclfg9y7';

    private const CUSTOMER_FUNDED_ENTERPRISE_TESTNET =
        'addr_test1wp4jf5jndedgvh4248kz2dlvk684hadsvdtdm0uperu6kqcypuetm';

    private const CUSTOMER_FUNDED_BASE_MAINNET =
        'addr1z94jf5jndedgvh4248kz2dlvk684hadsvdtdm0uperu6kquqsxpg8py9s6rc3zv23wxgmr50jzge9yu5jktf0xyen2dsa2j2rz';

    private const CUSTOMER_FUNDED_BASE_TESTNET =
        'addr_test1zp4jf5jndedgvh4248kz2dlvk684hadsvdtdm0uperu6kquqsxpg8py9s6rc3zv23wxgmr50jzge9yu5jktf0xyen2ds7u020a';

    private const CREDIT_FUNDED_CBOR =
        '820283'
        .'8200581c404142434445464748494a4b4c4d4e4f505152535455565758595a5b'
        .'8200581c606162636465666768696a6b6c6d6e6f707172737475767778797a7b'
        .'820182'
        .'8200581c202122232425262728292a2b2c2d2e2f303132333435363738393a3b'
        .'82051a0cd033ac';

    private const CREDIT_FUNDED_HASH = '442105599319b180e41a91afcc973734a60c8fd062eb63ec4c64cfe5';

    private const CREDIT_FUNDED_ENTERPRISE_MAINNET =
        'addr1w9zzzp2ejvvmrq8yr2g6lnyhxu62vry06p3wkclvf3jvleg5r7hhc';

    private const CREDIT_FUNDED_ENTERPRISE_TESTNET =
        'addr_test1wpzzzp2ejvvmrq8yr2g6lnyhxu62vry06p3wkclvf3jvleg0t2tca';

    private const CREDIT_FUNDED_BASE_MAINNET =
        'addr1z9zzzp2ejvvmrq8yr2g6lnyhxu62vry06p3wkclvf3jvlevqsxpg8py9s6rc3zv23wxgmr50jzge9yu5jktf0xyen2dsj0c25v';

    private const CREDIT_FUNDED_BASE_TESTNET =
        'addr_test1zpzzzp2ejvvmrq8yr2g6lnyhxu62vry06p3wkclvf3jvlevqsxpg8py9s6rc3zv23wxgmr50jzge9yu5jktf0xyen2ds3e92cn';

    // ------------------------------------------------------------ the shapes

    /**
     * any [ sig customer,
     *       all [ any [ sig campaignKey, sig owner1, sig owner2 ], before: expiry ] ]
     */
    private static function customerFunded(int $expirySlot = self::EXPIRY_SLOT): NativeScript
    {
        return NativeScript::any(
            NativeScript::sig(self::CUSTOMER),
            NativeScript::all(
                NativeScript::any(
                    NativeScript::sig(self::CAMPAIGN_KEY),
                    NativeScript::sig(self::OWNER_1),
                    NativeScript::sig(self::OWNER_2),
                ),
                NativeScript::before($expirySlot),
            ),
        );
    }

    /**
     * any [ sig owner1, sig owner2,
     *       all [ sig campaignKey, before: expiry ] ]
     */
    private static function creditFunded(int $expirySlot = self::EXPIRY_SLOT): NativeScript
    {
        return NativeScript::any(
            NativeScript::sig(self::OWNER_1),
            NativeScript::sig(self::OWNER_2),
            NativeScript::all(
                NativeScript::sig(self::CAMPAIGN_KEY),
                NativeScript::before($expirySlot),
            ),
        );
    }

    // --------------------------------------------------- the frozen variants

    public function test_customer_funded_serializes_to_the_frozen_cbor(): void
    {
        $this->assertSame(self::CUSTOMER_FUNDED_CBOR, self::customerFunded()->cborHex());
        $this->assertSame(144, strlen(self::customerFunded()->cbor()));
    }

    public function test_credit_funded_serializes_to_the_frozen_cbor(): void
    {
        $this->assertSame(self::CREDIT_FUNDED_CBOR, self::creditFunded()->cborHex());
        $this->assertSame(109, strlen(self::creditFunded()->cbor()));
    }

    public function test_customer_funded_hashes_to_the_frozen_script_hash(): void
    {
        $this->assertSame(self::CUSTOMER_FUNDED_HASH, self::customerFunded()->hashHex());
    }

    public function test_credit_funded_hashes_to_the_frozen_script_hash(): void
    {
        $this->assertSame(self::CREDIT_FUNDED_HASH, self::creditFunded()->hashHex());
    }

    public static function frozenAddresses(): array
    {
        return [
            'customer-funded enterprise mainnet' => ['customer', 'mainnet', null, self::CUSTOMER_FUNDED_ENTERPRISE_MAINNET],
            'customer-funded enterprise preprod' => ['customer', 'preprod', null, self::CUSTOMER_FUNDED_ENTERPRISE_TESTNET],
            'customer-funded base mainnet' => ['customer', 'mainnet', self::STAKE, self::CUSTOMER_FUNDED_BASE_MAINNET],
            'customer-funded base preprod' => ['customer', 'preprod', self::STAKE, self::CUSTOMER_FUNDED_BASE_TESTNET],
            'credit-funded enterprise mainnet' => ['credit', 'mainnet', null, self::CREDIT_FUNDED_ENTERPRISE_MAINNET],
            'credit-funded enterprise preprod' => ['credit', 'preprod', null, self::CREDIT_FUNDED_ENTERPRISE_TESTNET],
            'credit-funded base mainnet' => ['credit', 'mainnet', self::STAKE, self::CREDIT_FUNDED_BASE_MAINNET],
            'credit-funded base preprod' => ['credit', 'preprod', self::STAKE, self::CREDIT_FUNDED_BASE_TESTNET],
        ];
    }

    #[DataProvider('frozenAddresses')]
    public function test_the_frozen_addresses_are_reproduced(string $variant, string $network, ?string $stake, string $expected): void
    {
        $script = $variant === 'customer' ? self::customerFunded() : self::creditFunded();
        $delegation = $stake === null ? null : Credential::keyHash($stake);

        $this->assertSame($expected, $script->address(Network::named($network), $delegation)->toBech32());
    }

    #[DataProvider('frozenAddresses')]
    public function test_every_frozen_address_passes_the_application_address_rule(string $variant, string $network, ?string $stake, string $expected): void
    {
        $failure = null;

        (new \App\Rules\CardanoAddress($network))->validate('address', $expected, function ($message) use (&$failure) {
            $failure = $message;
        });

        $this->assertNull($failure, 'The application rejects an address it would have to hand a customer: '.$expected);
    }

    #[DataProvider('frozenAddresses')]
    public function test_every_frozen_address_reads_back_to_the_script_hash(string $variant, string $network, ?string $stake, string $expected): void
    {
        $address = Address::fromBech32($expected);
        $hash = $variant === 'customer' ? self::CUSTOMER_FUNDED_HASH : self::CREDIT_FUNDED_HASH;

        $this->assertSame($hash, $address->credential()->hex());
        $this->assertTrue($address->credential()->isScript());
        $this->assertSame($network === 'mainnet' ? 1 : 0, $address->network->value);
        $this->assertSame($stake === null ? 7 : 1, $address->type());
    }

    public function test_the_two_variants_do_not_collide(): void
    {
        $this->assertNotSame(self::CUSTOMER_FUNDED_HASH, self::CREDIT_FUNDED_HASH);
        $this->assertNotSame(
            self::customerFunded()->enterpriseAddress(Network::Mainnet)->toBech32(),
            self::creditFunded()->enterpriseAddress(Network::Mainnet)->toBech32(),
        );
    }

    public function test_the_frozen_json_is_what_cardano_cli_would_be_handed(): void
    {
        $this->assertSame([
            'type' => 'any',
            'scripts' => [
                ['type' => 'sig', 'keyHash' => self::CUSTOMER],
                [
                    'type' => 'all',
                    'scripts' => [
                        [
                            'type' => 'any',
                            'scripts' => [
                                ['type' => 'sig', 'keyHash' => self::CAMPAIGN_KEY],
                                ['type' => 'sig', 'keyHash' => self::OWNER_1],
                                ['type' => 'sig', 'keyHash' => self::OWNER_2],
                            ],
                        ],
                        ['type' => 'before', 'slot' => self::EXPIRY_SLOT],
                    ],
                ],
            ],
        ], self::customerFunded()->toArray());

        $this->assertSame([
            'type' => 'any',
            'scripts' => [
                ['type' => 'sig', 'keyHash' => self::OWNER_1],
                ['type' => 'sig', 'keyHash' => self::OWNER_2],
                [
                    'type' => 'all',
                    'scripts' => [
                        ['type' => 'sig', 'keyHash' => self::CAMPAIGN_KEY],
                        ['type' => 'before', 'slot' => self::EXPIRY_SLOT],
                    ],
                ],
            ],
        ], self::creditFunded()->toArray());
    }

    // -------------------------------------------- who can spend, and until when

    /**
     * The whole reason the two variants differ. In the customer-funded script the customer's own wallet is the only
     * signer with no time bound, and all three of our keys sit inside the expiry envelope. In the credit-funded
     * script the money is ours, so the two owner hashes are unbounded and the campaign key is the one that is locked.
     */
    public function test_the_customer_is_the_only_unbounded_signer_when_the_customer_funds_it(): void
    {
        $this->assertSame([self::CUSTOMER], self::customerFunded()->unboundedSigners());
        $this->assertSame(
            [self::CAMPAIGN_KEY, self::OWNER_1, self::OWNER_2],
            self::customerFunded()->timeBoundSigners(),
        );
    }

    public function test_the_owners_are_the_unbounded_signers_when_we_fund_it(): void
    {
        $this->assertSame([self::OWNER_1, self::OWNER_2], self::creditFunded()->unboundedSigners());
        $this->assertSame([self::CAMPAIGN_KEY], self::creditFunded()->timeBoundSigners());
    }

    /**
     * The same statement as a rule about transactions rather than a reading of the tree. The campaign key can spend
     * only while the transaction it signs ends at or before the expiry slot; the customer can spend at any point,
     * including after it.
     */
    public function test_the_campaign_key_can_spend_only_until_the_expiry_and_the_customer_afterwards(): void
    {
        $script = self::customerFunded();

        $this->assertTrue($script->isSatisfiedBy([self::CAMPAIGN_KEY], null, self::EXPIRY_SLOT));
        $this->assertFalse($script->isSatisfiedBy([self::CAMPAIGN_KEY], null, self::EXPIRY_SLOT + 1));
        $this->assertFalse(
            $script->isSatisfiedBy([self::CAMPAIGN_KEY], null, null),
            'The campaign key spends with no upper bound set at all.'
        );
        $this->assertTrue($script->isSatisfiedBy([self::CUSTOMER], null, null));
        $this->assertTrue($script->isSatisfiedBy([self::CUSTOMER], self::EXPIRY_SLOT + 86400, null));
    }

    public function test_the_owners_can_spend_the_credit_funded_script_after_the_expiry(): void
    {
        $script = self::creditFunded();

        $this->assertTrue($script->isSatisfiedBy([self::OWNER_1], null, null));
        $this->assertTrue($script->isSatisfiedBy([self::OWNER_2], self::EXPIRY_SLOT + 86400, null));
        $this->assertTrue($script->isSatisfiedBy([self::CAMPAIGN_KEY], null, self::EXPIRY_SLOT));
        $this->assertFalse($script->isSatisfiedBy([self::CAMPAIGN_KEY], null, self::EXPIRY_SLOT + 1));
        $this->assertFalse($script->isSatisfiedBy([self::CUSTOMER], null, self::EXPIRY_SLOT));
    }

    // ----------------------------------------------- every input is load-bearing

    public static function inputSubstitutions(): array
    {
        return [
            'customer' => [self::CUSTOMER],
            'campaignKey' => [self::CAMPAIGN_KEY],
            'owner1' => [self::OWNER_1],
            'owner2' => [self::OWNER_2],
        ];
    }

    /**
     * If any single input could be changed without moving the address, that input is not really in the script and the
     * freeze means nothing.
     */
    #[DataProvider('inputSubstitutions')]
    public function test_flipping_one_bit_of_any_hash_moves_the_customer_funded_address(string $original): void
    {
        $flipped = substr($original, 0, 55).dechex(hexdec(substr($original, 55, 1)) ^ 1);

        $script = NativeScript::any(
            NativeScript::sig($original === self::CUSTOMER ? $flipped : self::CUSTOMER),
            NativeScript::all(
                NativeScript::any(
                    NativeScript::sig($original === self::CAMPAIGN_KEY ? $flipped : self::CAMPAIGN_KEY),
                    NativeScript::sig($original === self::OWNER_1 ? $flipped : self::OWNER_1),
                    NativeScript::sig($original === self::OWNER_2 ? $flipped : self::OWNER_2),
                ),
                NativeScript::before(self::EXPIRY_SLOT),
            ),
        );

        $this->assertNotSame(self::CUSTOMER_FUNDED_HASH, $script->hashHex());
        $this->assertNotSame(
            self::CUSTOMER_FUNDED_ENTERPRISE_MAINNET,
            $script->enterpriseAddress(Network::Mainnet)->toBech32(),
        );
    }

    public function test_one_slot_of_difference_moves_the_address(): void
    {
        $this->assertNotSame(
            self::CUSTOMER_FUNDED_ENTERPRISE_MAINNET,
            self::customerFunded(self::EXPIRY_SLOT + 1)->enterpriseAddress(Network::Mainnet)->toBech32(),
        );
        $this->assertNotSame(
            self::CREDIT_FUNDED_ENTERPRISE_MAINNET,
            self::creditFunded(self::EXPIRY_SLOT - 1)->enterpriseAddress(Network::Mainnet)->toBech32(),
        );
    }

    public function test_swapping_the_two_owner_hashes_moves_the_address(): void
    {
        $swapped = NativeScript::any(
            NativeScript::sig(self::OWNER_2),
            NativeScript::sig(self::OWNER_1),
            NativeScript::all(
                NativeScript::sig(self::CAMPAIGN_KEY),
                NativeScript::before(self::EXPIRY_SLOT),
            ),
        );

        $this->assertNotSame(self::CREDIT_FUNDED_HASH, $swapped->hashHex());
    }

    public function test_the_network_changes_the_address_but_not_the_script_hash(): void
    {
        $mainnet = Address::fromBech32(self::CUSTOMER_FUNDED_ENTERPRISE_MAINNET);
        $testnet = Address::fromBech32(self::CUSTOMER_FUNDED_ENTERPRISE_TESTNET);

        $this->assertSame(self::CUSTOMER_FUNDED_HASH, $mainnet->credential()->hex());
        $this->assertSame($mainnet->credential()->hex(), $testnet->credential()->hex());
        $this->assertNotSame(
            self::CUSTOMER_FUNDED_ENTERPRISE_MAINNET,
            self::CUSTOMER_FUNDED_ENTERPRISE_TESTNET,
        );
    }

    // ------------------------------------------------------ the expiry slot

    /**
     * The slot the frozen addresses were built around, derived from the recorded mainnet era summaries rather than
     * written down. Freezing the shape without the slot would not freeze the address.
     */
    public function test_the_frozen_expiry_slot_comes_out_of_the_recorded_era_summaries(): void
    {
        $this->assertSame(self::EXPIRY_SLOT, self::mainnetEras()->slotAt(self::EXPIRY_INSTANT));
    }

    /**
     * The convention the expiry is read under, which is the one the claim path already uses: the campaign's end date
     * at the last second of the day, in UTC, plus ninety days.
     */
    public function test_the_expiry_instant_is_the_end_date_at_the_last_second_of_the_day_plus_ninety_days(): void
    {
        $endInstant = strtotime('2026-12-31 23:59:59 UTC');

        $this->assertSame(1798761599, $endInstant);
        $this->assertSame('2026-12-31T23:59:59+00:00', gmdate('c', $endInstant));
        $this->assertSame(self::EXPIRY_INSTANT, $endInstant + 90 * 86400);
        $this->assertSame('2027-03-31T23:59:59+00:00', gmdate('c', self::EXPIRY_INSTANT));
    }

    /**
     * The claim path is where that convention came from, so a change to it is a change to every address derived
     * after it.
     */
    public function test_the_claim_path_still_reads_end_date_at_the_last_second_of_the_day(): void
    {
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/CodeController.php'));
        $lines = preg_grep('/\$t_end\s*=/', explode("\n", $controller));

        $this->assertCount(1, $lines, 'CodeController no longer has exactly one end-of-campaign instant.');
        $this->assertStringContainsString("end_date.' 23:59:59 UTC'", (string) reset($lines));
    }

    public function test_the_expiry_instant_does_not_move_with_the_server_timezone(): void
    {
        $original = date_default_timezone_get();

        try {
            foreach (['UTC', 'America/New_York', 'Pacific/Kiritimati', 'Asia/Kathmandu'] as $zone) {
                date_default_timezone_set($zone);

                $this->assertSame(
                    self::EXPIRY_SLOT,
                    self::mainnetEras()->slotAt(strtotime('2026-12-31 23:59:59 UTC') + 90 * 86400),
                    'The expiry slot moves under '.$zone
                );
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    /**
     * The mainnet era summaries, read from the committed fixture rather than written out here.
     *
     * A slot number is the chain's answer, not this repository's. Typing the era boundaries in would make the
     * expected slot below a restatement of whatever was believed when it was typed, and an address derived from a
     * wrong slot is an address the funds behind it never move out of.
     */
    private static function mainnetEras(): EraSummaries
    {
        $path = dirname(__DIR__, 2).'/fixtures/cardano-time/mainnet-era-summaries.json';
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read '.$path);
        }

        $fixture = json_decode($contents, true, 512, JSON_THROW_ON_ERROR)['networks']['mainnet'];

        return EraSummaries::fromOgmios($fixture['era_summaries'], $fixture['system_start']);
    }
}

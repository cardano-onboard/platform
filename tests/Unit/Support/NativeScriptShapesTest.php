<?php

namespace Tests\Unit\Support;

use App\Rules\CardanoAddress;
use CardanoPhp\Bech32\Bech32;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CampaignExpiry;
use Tests\Support\NativeScript;
use Tests\TestCase;

/**
 * The freeze test for the two campaign native scripts described in the FROZEN
 * block below.
 *
 * Every expected value in the FROZEN block below was derived without running the
 * code under test:
 *
 *  - the CBOR was written out byte by byte from the Shelley-MA ledger CDDL, one
 *    line per byte;
 *  - the script hashes come from the reference BLAKE2b in Python's hashlib, not
 *    from the libsodium binding this test calls;
 *  - the addresses come from the BIP-173 reference bech32 implementation;
 *  - the expiry slot was worked out from the calendar by hand and checked
 *    against an independent date library.
 *
 * Two published CIP-19 vectors are asserted alongside them, so that the hashing
 * and address paths are pinned to something outside this repository rather than
 * only to arithmetic done here.
 *
 * An address has no migration. If any assertion in this file has to be changed,
 * every address already derived under the old value is a different address, and
 * the funds behind it stay where they are.
 */
class NativeScriptShapesTest extends TestCase
{
    // ---------------------------------------------------------------- inputs

    /**
     * Placeholder hashes. Each is 28 bytes and none is a palindrome or a repeat
     * of one byte, so a reversed or truncated hash cannot pass unnoticed.
     */
    private const CUSTOMER = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b';

    private const CAMPAIGN_KEY = '202122232425262728292a2b2c2d2e2f303132333435363738393a3b';

    private const OWNER_1 = '404142434445464748494a4b4c4d4e4f505152535455565758595a5b';

    private const OWNER_2 = '606162636465666768696a6b6c6d6e6f707172737475767778797a7b';

    private const STAKE = '808182838485868788898a8b8c8d8e8f909192939495969798999a9b';

    /** Campaign end date 2026-12-31, read at 23:59:59 UTC, plus ninety days, on mainnet. */
    private const EXPIRY_SLOT = 214971308;

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

    /** Mainnet Shelley began at slot 4492800, which started at 2020-07-29T21:44:51Z. */
    private const MAINNET_SHELLEY_SLOT = 4492800;

    private const MAINNET_SHELLEY_TIME = 1596059091;

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

        $this->assertSame($expected, $script->address($network, $stake));
    }

    #[DataProvider('frozenAddresses')]
    public function test_every_frozen_address_passes_the_application_address_rule(string $variant, string $network, ?string $stake, string $expected): void
    {
        $failure = null;

        (new CardanoAddress($network))->validate('address', $expected, function ($message) use (&$failure) {
            $failure = $message;
        });

        $this->assertNull($failure, 'The application rejects an address it would have to hand a customer: '.$expected);
    }

    #[DataProvider('frozenAddresses')]
    public function test_every_frozen_address_decodes_back_to_the_script_hash(string $variant, string $network, ?string $stake, string $expected): void
    {
        $decoded = Bech32::decodeCardanoAddress($expected);
        $hash = $variant === 'customer' ? self::CUSTOMER_FUNDED_HASH : self::CREDIT_FUNDED_HASH;

        $this->assertSame($hash, $decoded['paymentHash']);
        $this->assertSame($stake ?? '', $decoded['stakingHash']);
        $this->assertSame($network === 'mainnet' ? 1 : 0, (int) $decoded['networkId']);
        $this->assertSame($stake === null ? 7 : 1, (int) $decoded['addressType']);
    }

    public function test_the_two_variants_do_not_collide(): void
    {
        $this->assertNotSame(self::CUSTOMER_FUNDED_HASH, self::CREDIT_FUNDED_HASH);
        $this->assertNotSame(
            self::customerFunded()->address('mainnet'),
            self::creditFunded()->address('mainnet'),
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
     * The whole reason the two variants differ. In the customer-funded script the
     * customer's own wallet is the only signer with no time bound, and all three
     * of our keys sit inside the expiry envelope. In the credit-funded script the
     * money is ours, so the two owner hashes are unbounded and the campaign key
     * is the one that is locked.
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
     * A time lock nested one level further down still binds everything in the
     * branch that has to be satisfied alongside it. Reading this off the JSON is
     * where a mistake would not be noticed.
     */
    public function test_a_time_lock_nested_inside_an_all_still_binds_its_siblings(): void
    {
        $script = NativeScript::all(
            NativeScript::sig(self::OWNER_1),
            NativeScript::all(
                NativeScript::before(self::EXPIRY_SLOT),
                NativeScript::sig(self::CAMPAIGN_KEY),
            ),
        );

        $this->assertSame([], $script->unboundedSigners());
        $this->assertSame([self::OWNER_1, self::CAMPAIGN_KEY], $script->timeBoundSigners());
    }

    public function test_a_time_lock_offered_as_one_alternative_binds_nobody(): void
    {
        $script = NativeScript::all(
            NativeScript::sig(self::OWNER_1),
            NativeScript::any(
                NativeScript::before(self::EXPIRY_SLOT),
                NativeScript::sig(self::CAMPAIGN_KEY),
            ),
        );

        $this->assertSame([self::OWNER_1, self::CAMPAIGN_KEY], $script->unboundedSigners());
        $this->assertSame([], $script->timeBoundSigners());
    }

    public function test_a_threshold_binds_only_when_the_unbounded_branches_cannot_meet_it(): void
    {
        $reachable = NativeScript::atLeast(
            1,
            NativeScript::before(self::EXPIRY_SLOT),
            NativeScript::sig(self::OWNER_1),
        );
        $forced = NativeScript::atLeast(
            2,
            NativeScript::before(self::EXPIRY_SLOT),
            NativeScript::sig(self::OWNER_1),
        );

        $this->assertSame([self::OWNER_1], $reachable->unboundedSigners());
        $this->assertSame([self::OWNER_1], $forced->timeBoundSigners());
    }

    // ----------------------------------------------- every input is load-bearing

    /**
     * If any single input could be changed without moving the address, that
     * input is not really in the script and the freeze means nothing.
     */
    public static function inputSubstitutions(): array
    {
        return [
            'customer' => [self::CUSTOMER],
            'campaignKey' => [self::CAMPAIGN_KEY],
            'owner1' => [self::OWNER_1],
            'owner2' => [self::OWNER_2],
        ];
    }

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
        $this->assertNotSame(self::CUSTOMER_FUNDED_ENTERPRISE_MAINNET, $script->address('mainnet'));
    }

    public function test_one_slot_of_difference_moves_the_address(): void
    {
        $this->assertNotSame(
            self::CUSTOMER_FUNDED_ENTERPRISE_MAINNET,
            self::customerFunded(self::EXPIRY_SLOT + 1)->address('mainnet'),
        );
        $this->assertNotSame(
            self::CREDIT_FUNDED_ENTERPRISE_MAINNET,
            self::creditFunded(self::EXPIRY_SLOT - 1)->address('mainnet'),
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
        $mainnet = Bech32::decodeCardanoAddress(self::CUSTOMER_FUNDED_ENTERPRISE_MAINNET);
        $testnet = Bech32::decodeCardanoAddress(self::CUSTOMER_FUNDED_ENTERPRISE_TESTNET);

        $this->assertSame(self::CUSTOMER_FUNDED_HASH, $mainnet['paymentHash']);
        $this->assertSame($mainnet['paymentHash'], $testnet['paymentHash']);
        $this->assertNotSame(
            self::CUSTOMER_FUNDED_ENTERPRISE_MAINNET,
            self::CUSTOMER_FUNDED_ENTERPRISE_TESTNET,
        );
    }

    public function test_adding_a_stake_credential_moves_the_address(): void
    {
        $this->assertNotSame(
            self::customerFunded()->address('mainnet'),
            self::customerFunded()->address('mainnet', self::STAKE),
        );
    }

    // ------------------------------------------------------- encoding details

    /**
     * The tag byte 0x00 is what separates a native script from PlutusV1. Without
     * it the hash is still 28 bytes and still looks like a script hash.
     */
    public function test_the_language_tag_byte_is_part_of_the_hash(): void
    {
        $untagged = bin2hex(sodium_crypto_generichash(hex2bin(self::CREDIT_FUNDED_CBOR), '', 28));

        $this->assertNotSame(self::CREDIT_FUNDED_HASH, $untagged);
        $this->assertSame(
            self::CREDIT_FUNDED_HASH,
            bin2hex(sodium_crypto_generichash("\x00".hex2bin(self::CREDIT_FUNDED_CBOR), '', 28)),
        );
    }

    /**
     * "before" is invalid_hereafter, tag 5. "after" is invalid_before, tag 4.
     * The names are crossed over in the ledger CDDL, and inverting them gives a
     * script that is valid for exactly the period it was meant to exclude.
     */
    public function test_before_is_tag_five_and_after_is_tag_four(): void
    {
        $this->assertSame('82051864', NativeScript::before(100)->cborHex());
        $this->assertSame('82041864', NativeScript::after(100)->cborHex());
    }

    public static function slotEncodings(): array
    {
        return [
            'inline, largest' => [23, '820517'],
            'one byte, smallest' => [24, '82051818'],
            'one byte, largest' => [255, '820518ff'],
            'two bytes, smallest' => [256, '8205190100'],
            'two bytes, largest' => [65535, '820519ffff'],
            'four bytes, smallest' => [65536, '82051a00010000'],
            'four bytes, largest' => [4294967295, '82051affffffff'],
            'eight bytes, smallest' => [4294967296, '82051b0000000100000000'],
        ];
    }

    #[DataProvider('slotEncodings')]
    public function test_a_slot_is_written_in_the_shortest_form_that_holds_it(int $slot, string $expected): void
    {
        $this->assertSame($expected, NativeScript::before($slot)->cborHex());
    }

    public function test_at_least_encodes_as_tag_three_with_the_threshold_first(): void
    {
        $script = NativeScript::atLeast(
            2,
            NativeScript::sig(self::OWNER_1),
            NativeScript::sig(self::OWNER_2),
        );

        $this->assertSame(
            '83030282'.'8200581c'.self::OWNER_1.'8200581c'.self::OWNER_2,
            $script->cborHex(),
        );
    }

    // ------------------------------------------------------------- bad inputs

    public static function unusableKeyHashes(): array
    {
        return [
            'empty' => [''],
            'one nibble short' => ['000102030405060708090a0b0c0d0e0f101112131415161718191a1'],
            'one nibble long' => ['000102030405060708090a0b0c0d0e0f101112131415161718191a1bc'],
            'a 32 byte hash pasted in by mistake' => [str_repeat('ab', 32)],
            'uppercase' => ['000102030405060708090A0B0C0D0E0F101112131415161718191A1B'],
            'not hex' => ['zz0102030405060708090a0b0c0d0e0f101112131415161718191a1b'],
            'bech32 key hash' => ['addr_vk1w0l2sr2zgfm26ztc6nl9xy8ghsk5sh6ldwemlpmp9xylzy4dtf7st80zhd'],
            'with 0x prefix' => ['0x0102030405060708090a0b0c0d0e0f101112131415161718191a1b'],
            'whitespace around a good hash' => [' 000102030405060708090a0b0c0d0e0f101112131415161718191a1b '],
        ];
    }

    #[DataProvider('unusableKeyHashes')]
    public function test_an_unusable_key_hash_is_refused_rather_than_padded(string $keyHash): void
    {
        $this->expectException(InvalidArgumentException::class);

        NativeScript::sig($keyHash);
    }

    #[DataProvider('unusableKeyHashes')]
    public function test_an_unusable_stake_key_hash_is_refused(string $stakeKeyHash): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::creditFunded()->address('mainnet', $stakeKeyHash);
    }

    public function test_a_negative_slot_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NativeScript::before(-1);
    }

    public function test_an_empty_branch_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NativeScript::any();
    }

    public function test_an_unreachable_threshold_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NativeScript::atLeast(3, NativeScript::sig(self::OWNER_1), NativeScript::sig(self::OWNER_2));
    }

    public function test_an_unknown_network_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::creditFunded()->address('sanchonet');
    }

    // --------------------------------------------- published external vectors

    /**
     * CIP-19's own type-07 test vector. This pins the header byte, the byte
     * order and the bech32 encoding to a published value rather than to
     * arithmetic done in this repository.
     */
    public function test_the_cip_19_enterprise_script_address_vector(): void
    {
        $scriptHash = 'c37b1b5dc0669f1d3c61a6fddb2e8fde96be87b881c60bce8e8d542f';

        $this->assertSame(
            'addr1w8phkx6acpnf78fuvxn0mkew3l0fd058hzquvz7w36x4gtcyjy7wx',
            Bech32::encode('addr', Bech32::hexToByteArray('71'.$scriptHash)),
        );
    }

    /**
     * CIP-19's payment verification key and the key hash it publishes for it.
     * This pins blake2b-224 as Cardano uses it, so a libsodium that hashed the
     * wrong length or the wrong bytes would be caught here and not only in the
     * frozen values above.
     */
    public function test_the_cip_19_key_hash_vector(): void
    {
        [, $data] = Bech32::decode('addr_vk1w0l2sr2zgfm26ztc6nl9xy8ghsk5sh6ldwemlpmp9xylzy4dtf7st80zhd');
        $key = hex2bin(Bech32::byteArrayToHex($data));

        $this->assertSame(32, strlen($key));
        $this->assertSame(
            '9493315cd92eb5d8c4304e67b7e16ae36d61d34502694657811a2c8e',
            bin2hex(sodium_crypto_generichash($key, '', 28)),
        );
    }

    // ------------------------------------------------------ slot derivation

    public function test_the_claim_path_still_reads_end_date_at_the_last_second_of_the_day(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/CodeController.php'));
        $lines = preg_grep('/\$t_end\s*=/', explode("\n", $controller));

        $this->assertCount(1, $lines, 'CodeController no longer has exactly one end-of-campaign instant.');
        $this->assertStringContainsString("end_date.' 23:59:59 UTC'", reset($lines));
    }

    public function test_the_end_instant_is_the_last_second_of_the_end_date_in_utc(): void
    {
        $this->assertSame(1798761599, CampaignExpiry::endInstant('2026-12-31'));
        $this->assertSame('2026-12-31T23:59:59+00:00', gmdate('c', CampaignExpiry::endInstant('2026-12-31')));
    }

    public function test_the_end_instant_does_not_move_with_the_server_timezone(): void
    {
        $original = date_default_timezone_get();

        try {
            foreach (['UTC', 'America/New_York', 'Pacific/Kiritimati', 'Asia/Kathmandu'] as $zone) {
                date_default_timezone_set($zone);
                $this->assertSame(1798761599, CampaignExpiry::endInstant('2026-12-31'), 'Wrong under '.$zone);
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public static function expiryDates(): array
    {
        return [
            // end date, expiry instant, the calendar date ninety days later
            'ordinary year end' => ['2026-12-31', 1806537599, '2027-03-31'],
            'crossing a leap day' => ['2027-12-31', 1838073599, '2028-03-30'],
            'the leap day itself' => ['2028-02-29', 1843257599, '2028-05-29'],
        ];
    }

    #[DataProvider('expiryDates')]
    public function test_the_expiry_is_ninety_days_after_the_end_instant(string $endDate, int $expected, string $calendarDate): void
    {
        $this->assertSame($expected, CampaignExpiry::expiryInstant($endDate));
        $this->assertSame($calendarDate.'T23:59:59+00:00', gmdate('c', $expected));
    }

    public function test_the_frozen_expiry_slot_is_reproduced_from_the_mainnet_era_anchor(): void
    {
        $this->assertSame(self::EXPIRY_SLOT, CampaignExpiry::expirySlot(
            '2026-12-31',
            self::MAINNET_SHELLEY_SLOT,
            self::MAINNET_SHELLEY_TIME,
        ));
    }

    public function test_the_slot_comes_from_the_era_anchor_and_is_not_hardcoded(): void
    {
        $shifted = CampaignExpiry::expirySlot(
            '2026-12-31',
            self::MAINNET_SHELLEY_SLOT + 1000,
            self::MAINNET_SHELLEY_TIME,
        );

        $this->assertSame(self::EXPIRY_SLOT + 1000, $shifted);
    }

    public function test_an_expiry_that_does_not_land_on_a_slot_boundary_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // A twenty second era whose anchor is one second off the expiry.
        CampaignExpiry::expirySlot('2026-12-31', 0, 1806537599 - 1, 20);
    }

    public function test_an_era_anchor_after_the_expiry_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CampaignExpiry::expirySlot('2026-12-31', 0, 1806537599 + 1);
    }

    public static function unusableEndDates(): array
    {
        return [
            'empty' => [''],
            'a timestamp' => ['2026-12-31 23:59:59'],
            'american order' => ['12/31/2026'],
            'no day' => ['2026-12'],
            'a month that does not exist' => ['2026-13-01'],
            'a day that does not exist' => ['2027-02-29'],
            'words' => ['tomorrow'],
        ];
    }

    #[DataProvider('unusableEndDates')]
    public function test_an_unusable_end_date_is_refused(string $endDate): void
    {
        $this->expectException(InvalidArgumentException::class);

        CampaignExpiry::endInstant($endDate);
    }
}

<?php

namespace Tests\Unit\Cardano;

use App\Cardano\InvariantGate;
use App\Cardano\PaymentBuilder;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Builder\TransactionBodyBuilder;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Ledger\FeeCalculator;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\MinimumUtxo;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;
use Cardano\Transaction\Primitives\WitnessSet;
use Cardano\Transaction\Script\NativeScript;
use Cardano\Transaction\Selection\AssetId;
use Cardano\Transaction\Selection\Utxo;
use Cardano\Transaction\Selection\ValueBag;
use Cardano\Transaction\Signing\SigningKey;
use RuntimeException;
use Tests\TestCase;

/**
 * Building a payment from a campaign's own funds.
 *
 * The test that matters most here is that what the builder produces passes the gate. They were
 * written separately and from the same requirements, so agreement between them is evidence; a
 * builder checked only by a test written alongside it proves that two things one person believed
 * agree with each other.
 */
class PaymentBuilderTest extends TestCase
{
    private const EXPIRY = 200_000_000;

    private const TTL = 199_000_000;

    private function parameters(): LedgerParameters
    {
        return new LedgerParameters(155381, 44, 4310, 5000, 16384);
    }

    private function key(string $byte): SigningKey
    {
        return SigningKey::fromSeed(str_repeat($byte, 32));
    }

    private function script(): NativeScript
    {
        return NativeScript::any(
            NativeScript::sig($this->key("\x22")->credential()->hex()),
            NativeScript::sig($this->key("\x33")->credential()->hex()),
            NativeScript::all(
                NativeScript::sig($this->key("\x11")->credential()->hex()),
                NativeScript::before(self::EXPIRY),
            ),
        );
    }

    private function campaignAddress(): string
    {
        return (string) $this->script()->enterpriseAddress(Network::Testnet);
    }

    private function funds(int $lovelace = 100_000_000): array
    {
        return [Utxo::of(str_repeat('ab', 32), 0, $this->campaignAddress(), ValueBag::ofCoin($lovelace))];
    }

    private function builder(): PaymentBuilder
    {
        return new PaymentBuilder($this->parameters(), Network::Testnet);
    }

    /**
     * What the builder produces satisfies every invariant the gate checks.
     *
     * This is the one that would catch a builder and a gate that had drifted apart, which is the
     * failure neither of their own tests can see.
     */
    public function test_a_built_payment_passes_the_gate(): void
    {
        $built = $this->builder()->build(
            $this->funds(),
            [
                ['address' => $this->campaignAddress(), 'lovelace' => 2_000_000],
                ['address' => $this->campaignAddress(), 'lovelace' => 3_000_000],
            ],
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        (new InvariantGate($this->parameters(), Network::Testnet))->assert(
            $built['bytes'],
            [['address' => $this->campaignAddress(), 'coin' => 100_000_000]],
            $this->campaignAddress(),
            self::EXPIRY,
            $built['transaction']->hashHex(),
            1,
            scripts: [$this->script()->hashHex()],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Value balances exactly: what went in equals what comes out plus the fee.
     */
    public function test_value_balances_to_the_lovelace(): void
    {
        $built = $this->builder()->build(
            $this->funds(),
            [['address' => $this->campaignAddress(), 'lovelace' => 2_000_000]],
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        $out = $built['transaction']->body->fee()->toInt();

        foreach ($built['transaction']->body->outputs() as $output) {
            $out += $output->value->coin->toInt();
        }

        $this->assertSame(100_000_000, $out);
    }

    /**
     * The fee covers the transaction at the size it actually is, witnesses included.
     *
     * A fee measured on an unwitnessed body is short by exactly the witnesses it did not count, and
     * the shortfall is only discovered by the node that refuses it.
     */
    public function test_the_fee_covers_the_fully_witnessed_size(): void
    {
        $built = $this->builder()->build(
            $this->funds(),
            [['address' => $this->campaignAddress(), 'lovelace' => 2_000_000]],
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        $required = \Cardano\Transaction\Ledger\FeeCalculator::under($this->parameters())
            ->forBytes($built['bytes'])
            ->toInt();

        $this->assertGreaterThanOrEqual(
            $required,
            $built['transaction']->body->fee()->toInt(),
            'The fee does not cover the transaction that was actually produced.'
        );
    }

    /**
     * Change comes back to the campaign and nowhere else.
     */
    public function test_change_returns_to_the_campaign(): void
    {
        $built = $this->builder()->build(
            $this->funds(),
            [['address' => $this->campaignAddress(), 'lovelace' => 2_000_000]],
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        $outputs = $built['transaction']->body->outputs();
        $change = end($outputs);

        $this->assertSame(
            $this->campaignAddress(),
            \Cardano\Transaction\Address\Address::fromBytes($change->address)->toBech32(),
        );
    }

    /**
     * The transaction is signed by the campaign key and carries the script that guards its address.
     *
     * A node asked to spend from a script address needs the script. Without it the transaction is
     * correctly signed and still refused.
     */
    public function test_it_is_signed_and_carries_its_script(): void
    {
        $built = $this->builder()->build(
            $this->funds(),
            [['address' => $this->campaignAddress(), 'lovelace' => 2_000_000]],
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        $witnesses = $built['transaction']->witnessSet->vkeyWitnesses();

        $this->assertCount(1, $witnesses);
        $this->assertSame($this->key("\x11")->publicKeyHex(), $witnesses[0]->vkeyHex());
        $this->assertTrue(\Cardano\Transaction\Signing\TransactionSigner::witnessesVerify($built['transaction']));
        $this->assertStringContainsString(
            bin2hex($this->script()->cbor()),
            bin2hex($built['bytes']),
            'The script that guards the campaign address is not in the witness set, so a node would refuse this.'
        );
    }

    /**
     * A reward below the live ledger minimum is paid at exactly that minimum, not refused and not
     * rounded up past it, and a reward above it is left alone.
     *
     * Refusing would fail the whole batch for one claim, and the claim is collected again on every
     * run, so one such code would block everyone behind it.
     */
    public function test_a_payment_under_the_minimum_is_raised_to_exactly_the_minimum(): void
    {
        $recipient = $this->claimantAddress();
        $minimum = MinimumUtxo::under($this->parameters())
            ->forValue(Address::fromBech32($recipient)->toBytes(), Value::of(0))
            ->toInt();

        $built = $this->builder()->build(
            $this->funds(),
            [
                ['address' => $recipient, 'lovelace' => 100],
                ['address' => $recipient, 'lovelace' => 7_000_000],
            ],
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        $outputs = $built['transaction']->body->outputs();

        $this->assertGreaterThan(100, $minimum);
        $this->assertSame($minimum, $outputs[0]->value->coin->toInt());
        $this->assertSame(7_000_000, $outputs[1]->value->coin->toInt());
        $this->assertSame(2, $built['paid']);
    }

    /**
     * The fee is selected against as it is measured on the transaction being built, so a campaign
     * holding the batch and its real fee can pay, and change too small to be an output goes to the
     * fee rather than being refused.
     */
    public function test_change_under_the_minimum_is_folded_into_the_fee(): void
    {
        $recipient = [['address' => $this->claimantAddress(), 'lovelace' => 2_000_000]];
        $fee = $this->builder()->build($this->funds(), $recipient, $this->campaignAddress(), $this->script(), $this->key("\x11"), self::TTL)['transaction']->body->fee()->toInt();

        // The batch, its real fee and 50,000 lovelace, which no output can hold.
        $built = $this->builder()->build(
            $this->funds(2_000_000 + $fee + 50_000),
            $recipient,
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        $this->assertCount(1, $built['transaction']->body->outputs());
        $this->assertGreaterThanOrEqual(
            FeeCalculator::under($this->parameters())->forBytes($built['bytes'])->toInt(),
            $built['transaction']->body->fee()->toInt(),
        );
        $this->assertSame(2_000_000 + $fee + 50_000, 2_000_000 + $built['transaction']->body->fee()->toInt());
    }

    /**
     * A transaction with no change output passes the gate when it balances: the last output is a
     * payment, and nothing was returned to anywhere.
     */
    public function test_a_payment_with_no_change_passes_the_gate(): void
    {
        $recipient = [['address' => $this->claimantAddress(), 'lovelace' => 2_000_000]];
        $fee = $this->builder()->build($this->funds(), $recipient, $this->campaignAddress(), $this->script(), $this->key("\x11"), self::TTL)['transaction']->body->fee()->toInt();
        $funds = $this->funds(2_000_000 + $fee + 50_000);

        $built = $this->builder()->build($funds, $recipient, $this->campaignAddress(), $this->script(), $this->key("\x11"), self::TTL);

        (new InvariantGate($this->parameters(), Network::Testnet))->assert(
            $built['bytes'],
            $this->gateInputs($funds),
            $this->campaignAddress(),
            self::EXPIRY,
            $built['transaction']->hashHex(),
            1,
            scripts: [$this->script()->hashHex()],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * A batch over maxTxSize pays the recipients that fit, measured on the fully witnessed
     * transaction, and says how many. The rest are left for the next run.
     */
    public function test_a_batch_over_the_size_limit_pays_only_what_fits(): void
    {
        $recipients = array_map(
            fn (int $n): array => ['address' => (string) NativeScript::sig($this->key(chr($n % 200 + 1))->credential()->hex())->enterpriseAddress(Network::Testnet), 'lovelace' => 2_000_000],
            range(1, 600),
        );

        $built = $this->builder()->build($this->funds(2_000_000_000), $recipients, $this->campaignAddress(), $this->script(), $this->key("\x11"), self::TTL);

        $this->assertLessThan(600, $built['paid']);
        $this->assertGreaterThan(100, $built['paid']);
        $this->assertLessThanOrEqual(16384, strlen($built['bytes']));
        $this->assertSame($built['paid'] + 1, count($built['transaction']->body->outputs()));

        (new InvariantGate($this->parameters(), Network::Testnet))->assert(
            $built['bytes'],
            $this->gateInputs($this->funds(2_000_000_000)),
            $this->campaignAddress(),
            self::EXPIRY,
            $built['transaction']->hashHex(),
            $built['paid'],
            scripts: [$this->script()->hashHex()],
        );
    }

    /**
     * CIP-20 (section Specification): label 674, key "msg", a list of strings of at most 64 bytes
     * of UTF-8. The auxiliary data is in the transaction, its hash is in the body, and the fee
     * covers the bytes it adds.
     */
    public function test_the_campaigns_message_is_attached_as_cip_20_metadata(): void
    {
        $recipient = [['address' => $this->claimantAddress(), 'lovelace' => 2_000_000]];
        $plain = $this->builder()->build($this->funds(), $recipient, $this->campaignAddress(), $this->script(), $this->key("\x11"), self::TTL);
        $built = $this->builder()->build($this->funds(), $recipient, $this->campaignAddress(), $this->script(), $this->key("\x11"), self::TTL, 'Hello');

        $decoded = TransactionDecoder::decode($built['bytes']);

        // {674: {"msg": ["Hello"]}}
        $this->assertSame('a11902a2a1636d7367816548656c6c6f', bin2hex($decoded->auxiliaryData->encode()));
        $this->assertSame(
            sodium_crypto_generichash($decoded->auxiliaryData->encode(), '', 32),
            $decoded->body->auxiliaryDataHash(),
        );
        $this->assertGreaterThan($plain['transaction']->body->fee()->toInt(), $built['transaction']->body->fee()->toInt());
        $this->assertNull(TransactionDecoder::decode($plain['bytes'])->auxiliaryData);

        (new InvariantGate($this->parameters(), Network::Testnet))->assert(
            $built['bytes'],
            $this->gateInputs($this->funds()),
            $this->campaignAddress(),
            self::EXPIRY,
            $built['transaction']->hashHex(),
            1,
            scripts: [$this->script()->hashHex()],
        );
    }

    /**
     * CIP-20 (Specification) allows several lines of at most 64 bytes of UTF-8 each. A longer
     * message is split across the list at character boundaries and none of it is lost.
     */
    public function test_a_long_message_is_split_into_lines_of_at_most_64_bytes(): void
    {
        $recipient = [['address' => $this->claimantAddress(), 'lovelace' => 2_000_000]];
        $built = $this->builder()->build($this->funds(), $recipient, $this->campaignAddress(), $this->script(), $this->key("\x11"), self::TTL, str_repeat('é', 40));

        // The 674 label, "msg", then a list of two lines: 32 characters (64 bytes) and 8 (16 bytes).
        $this->assertSame(
            'a11902a2a1636d736782'.'7840'.str_repeat(bin2hex('é'), 32).'70'.str_repeat(bin2hex('é'), 8),
            bin2hex($built['transaction']->auxiliaryData->encode()),
        );
    }

    /**
     * A campaign that cannot cover the batch and its fee is told so.
     */
    public function test_a_campaign_that_cannot_cover_the_batch_is_told_what_it_holds(): void
    {
        $this->expectException(RuntimeException::class);

        $this->builder()->build(
            $this->funds(3_000_000),
            [['address' => $this->campaignAddress(), 'lovelace' => 2_900_000]],
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );
    }

    public function test_a_payment_with_no_recipients_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pays nobody');

        $this->builder()->build(
            $this->funds(),
            [],
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );
    }

    /**
     * A sweep moves everything and leaves no change.
     *
     * Change left behind would be a balance nobody meant the campaign to keep, at an address whose
     * key is about to stop signing.
     */
    public function test_a_sweep_sends_everything_less_its_fee_to_one_address(): void
    {
        $destination = $this->campaignAddress();

        $built = $this->builder()->sweep(
            $this->funds(50_000_000),
            $destination,
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        $outputs = $built['transaction']->body->outputs();

        $this->assertCount(1, $outputs, 'A sweep with more than one output has left change behind.');
        $this->assertSame(
            $destination,
            \Cardano\Transaction\Address\Address::fromBytes($outputs[0]->address)->toBech32(),
        );

        $this->assertSame(
            50_000_000,
            $outputs[0]->value->coin->toInt() + $built['transaction']->body->fee()->toInt(),
            'What was swept plus the fee is not what the campaign held.'
        );
    }

    /**
     * And it passes the gate, with the balance as its only input.
     */
    public function test_a_sweep_passes_the_gate(): void
    {
        $destination = $this->campaignAddress();

        $built = $this->builder()->sweep(
            $this->funds(50_000_000),
            $destination,
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        (new InvariantGate($this->parameters(), Network::Testnet))->assert(
            $built['bytes'],
            [['address' => $this->campaignAddress(), 'coin' => 50_000_000]],
            $destination,
            self::EXPIRY,
            $built['transaction']->hashHex(),
            scripts: [$this->script()->hashHex()],
        );

        $this->addToAssertionCount(1);
    }

    /**
     * A balance that cannot cover the fee to move it is said so, not sent.
     *
     * Splitting cannot help, because the shortfall is the whole balance. The honest answer is that
     * there is nothing here that can go anywhere.
     */
    public function test_a_balance_too_small_to_move_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nothing here that can be sent');

        $this->builder()->sweep(
            $this->funds(200_000),
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );
    }

    public function test_sweeping_an_empty_campaign_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nothing at this campaign address');

        $this->builder()->sweep([], $this->campaignAddress(), $this->script(), $this->key("\x11"), self::TTL);
    }

    private const POLICY = '0123456789abcdef0123456789abcdef0123456789abcdef01234567';

    /** @return array{Utxo, Utxo} an ADA-only output too small to pay alone, and one carrying a token */
    private function fundsWithAToken(): array
    {
        return [
            Utxo::of(str_repeat('ab', 32), 0, $this->campaignAddress(), ValueBag::ofCoin(3_000_000)),
            Utxo::of(str_repeat('cd', 32), 1, $this->campaignAddress(), ValueBag::of(40_000_000, [
                [AssetId::fromHex(self::POLICY, '5350414d'), '1000000'],
            ])),
        ];
    }

    private function gateInputs(array $utxos): array
    {
        return array_map(static function (Utxo $utxo): array {
            $assets = [];

            foreach ($utxo->value->assets() as [$id, $quantity]) {
                $assets[$id->key()] = $quantity->value;
            }

            return ['address' => $utxo->address, 'coin' => $utxo->coin()->value, 'assets' => $assets];
        }, $utxos);
    }

    /**
     * A token on an input the payment has to spend goes back to the campaign in the change, whole.
     *
     * The ADA-only output cannot cover the payment, so the output carrying the token is selected.
     * Leaving the token out of the outputs is a transaction the ledger refuses for not conserving
     * value; sending it anywhere but the campaign loses it.
     */
    public function test_a_token_on_a_selected_input_goes_back_in_the_change(): void
    {
        $funds = $this->fundsWithAToken();

        $built = $this->builder()->build(
            $funds,
            [['address' => $this->claimantAddress(), 'lovelace' => 5_000_000]],
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        $outputs = $built['transaction']->body->outputs();

        // The token returns in an output of its own, before the ADA change.
        $change = $outputs[count($outputs) - 2];

        $this->assertSame($this->campaignAddress(), Address::fromBytes($change->address)->toBech32());
        $this->assertSame(
            '1000000',
            ValueBag::fromValue($change->value)->quantityOf(AssetId::fromHex(self::POLICY, '5350414d'))->value,
        );
        $this->assertFalse(end($outputs)->value->hasAssets());

        (new InvariantGate($this->parameters(), Network::Testnet))->assert(
            $built['bytes'],
            $this->gateInputs(array_values(array_filter(
                $funds,
                static fn (Utxo $utxo): bool => in_array(['hash' => $utxo->transactionId, 'index' => $utxo->index], $built['inputs'], true),
            ))),
            $this->campaignAddress(),
            self::EXPIRY,
            $built['transaction']->hashHex(),
            1,
            scripts: [$this->script()->hashHex()],
        );
    }

    /**
     * The gate balances each token as well as the lovelace. A transaction that spends a token and
     * does not account for it is refused, whatever the lovelace says.
     */
    public function test_the_gate_refuses_a_token_that_is_not_accounted_for(): void
    {
        $funds = $this->fundsWithAToken();

        $built = $this->builder()->build(
            $funds,
            [['address' => $this->claimantAddress(), 'lovelace' => 5_000_000]],
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        $inputs = $this->gateInputs($funds);
        $inputs[1]['assets'][self::POLICY.'5350414d'] = '1000001';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('for the token '.self::POLICY.'5350414d');

        (new InvariantGate($this->parameters(), Network::Testnet))->assert(
            $built['bytes'],
            $inputs,
            $this->campaignAddress(),
            self::EXPIRY,
            $built['transaction']->hashHex(),
            1,
            scripts: [$this->script()->hashHex()],
        );
    }

    /**
     * Every output after the payments goes to the campaign, the token remainders as well as the
     * ADA change. One redirected to another wallet is refused, though it is not the last output.
     */
    public function test_the_gate_refuses_a_token_remainder_paid_to_another_wallet(): void
    {
        $funds = [Utxo::of(str_repeat('cd', 32), 1, $this->campaignAddress(), ValueBag::of(40_000_000, [
            [AssetId::fromHex(self::POLICY, '5350414d'), '1000000'],
            [AssetId::fromHex(self::POLICY, '4f544845'), '5'],
        ]))];

        $built = $this->builder()->build(
            $funds,
            [['address' => $this->claimantAddress(), 'lovelace' => 5_000_000]],
            $this->campaignAddress(),
            $this->script(),
            $this->key("\x11"),
            self::TTL,
        );

        $outputs = $built['transaction']->body->outputs();
        $this->assertCount(4, $outputs, 'a recipient, two token remainders and the ADA change');

        // The first remainder, sent to the recipient's wallet instead.
        $outputs[1] = TransactionOutput::create(Address::fromBech32($this->claimantAddress())->toBytes(), $outputs[1]->value);
        $body = $built['transaction']->body;
        $bytes = Transaction::assemble(
            TransactionBodyBuilder::create()->inputs($body->inputs())->outputs($outputs)->fee($body->fee()->toInt())
                ->ttl($body->ttl()->toInt())->networkId(0)->build(),
            WitnessSet::of([], []),
        )->encode();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('after the payments does not pay the campaign address');

        (new InvariantGate($this->parameters(), Network::Testnet))->assert(
            $bytes,
            $this->gateInputs($funds),
            $this->campaignAddress(),
            self::EXPIRY,
            TransactionDecoder::decode($bytes)->hashHex(),
            1,
            scripts: [$this->script()->hashHex()],
        );
    }

    /**
     * A sweep of an address holding a token sends the token with the ADA, and passes the gate.
     */
    public function test_a_sweep_takes_a_token_with_it(): void
    {
        $funds = $this->fundsWithAToken();
        $destination = $this->claimantAddress();

        $built = $this->builder()->sweep($funds, $destination, $this->script(), $this->key("\x11"), self::TTL);

        $sent = ValueBag::empty();

        foreach ($built['transaction']->body->outputs() as $output) {
            $this->assertSame($destination, Address::fromBytes($output->address)->toBech32());
            $sent = $sent->plus(ValueBag::fromValue($output->value));
        }

        $this->assertSame('1000000', $sent->quantityOf(AssetId::fromHex(self::POLICY, '5350414d'))->value);
        $this->assertSame(43_000_000 - $built['transaction']->body->fee()->toInt(), $sent->coin->toInt());

        (new InvariantGate($this->parameters(), Network::Testnet))->assert(
            $built['bytes'],
            $this->gateInputs($funds),
            $destination,
            self::EXPIRY,
            $built['transaction']->hashHex(),
            scripts: [$this->script()->hashHex()],
        );
    }

    private function claimantAddress(): string
    {
        return (string) NativeScript::sig($this->key("\x77")->credential()->hex())->enterpriseAddress(Network::Testnet);
    }
}

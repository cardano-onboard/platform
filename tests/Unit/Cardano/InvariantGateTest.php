<?php

namespace Tests\Unit\Cardano;

use App\Cardano\InvariantGate;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Builder\TransactionBodyBuilder;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Ledger\FeeCalculator;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionInput;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;
use Cardano\Transaction\Primitives\WitnessSet;
use RuntimeException;
use Tests\TestCase;

/**
 * Every refusal the gate makes, made to happen.
 *
 * A gate is worth exactly what can be shown to trip it. Each test here builds a transaction that
 * violates one invariant and nothing else, so a refusal proves that invariant rather than proving
 * the transaction was broken in some way the gate happened to notice.
 *
 * The fixture is a real mainnet transaction, read back from its own bytes, so what is being
 * checked is the shape the chain actually carries rather than one assembled to suit the test.
 */
class InvariantGateTest extends TestCase
{
    private function parameters(): LedgerParameters
    {
        // The values in force on mainnet when the fixture was fetched.
        return new LedgerParameters(
            txFeeFixed: 155381,
            txFeePerByte: 44,
            utxoCostPerByte: 4310,
            maxValueSize: 5000,
            maxTxSize: 16384,
        );
    }

    private function gate(Network $network = Network::Mainnet): InvariantGate
    {
        return new InvariantGate($this->parameters(), $network);
    }

    private function fixture(): array
    {
        $hex = trim(file_get_contents(
            base_path('tests/fixtures/cardano-tx/both-validity-bounds.hex')
        ));

        $bytes = hex2bin($hex);
        $transaction = TransactionDecoder::decode($bytes);

        return [$transaction, $bytes];
    }

    /**
     * The inputs that balance this fixture, which is the sum it actually spends.
     */
    private function balancedInputs(Transaction $transaction): array
    {
        $total = $transaction->body->fee()->toInt();

        foreach ($transaction->body->outputs() as $output) {
            $total += $output->value->coin->toInt();
        }

        return [['address' => 'irrelevant', 'coin' => $total]];
    }

    private function changeAddress(Transaction $transaction): string
    {
        $outputs = $transaction->body->outputs();

        return \Cardano\Transaction\Address\Address::fromBytes(end($outputs)->address)->toBech32();
    }

    private function expiry(Transaction $transaction): int
    {
        $ttl = $transaction->body->ttl();
        $this->assertNotNull($ttl, 'This case needs a fixture that carries an upper bound.');

        // Comfortably past the transaction's own bound, which is what a campaign's expiry is.
        return $ttl === null ? 200_000_000 : ($ttl->toInt() + 1_000_000);
    }

    /**
     * A transaction that violates nothing passes.
     *
     * Without this every other test here could pass against a gate that refused everything.
     */
    public function test_a_sound_transaction_is_allowed_through(): void
    {
        [$transaction, $bytes] = $this->fixture();

        $this->gate()->assert(
            $bytes,
            $this->balancedInputs($transaction),
            $this->changeAddress($transaction),
            $this->expiry($transaction),
            $transaction->hashHex(),
        );

        $this->addToAssertionCount(1);
    }

    /** 1. Value that does not balance. */
    public function test_value_that_does_not_balance_is_refused(): void
    {
        [$transaction, $bytes] = $this->fixture();

        $inputs = $this->balancedInputs($transaction);
        $inputs[0]['coin'] += 1_000_000;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not balance');

        $this->gate()->assert(
            $bytes,
            $inputs,
            $this->changeAddress($transaction),
            $this->expiry($transaction),
            $transaction->hashHex(),
        );
    }

    /** 3. A fee under the minimum for the fully witnessed size. */
    public function test_a_fee_under_the_minimum_for_the_size_is_refused(): void
    {
        [$transaction, $bytes] = $this->fixture();

        // Parameters that make this transaction's own fee too small for its own size, which is
        // what happens when the chain raises the fee and a build is measured against a stale set.
        $gate = new InvariantGate(
            new LedgerParameters(
                txFeeFixed: 155381,
                txFeePerByte: 4400,
                utxoCostPerByte: 4310,
                maxValueSize: 5000,
                maxTxSize: 16384,
            ),
            Network::Mainnet,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The fee is');

        $gate->assert(
            $bytes,
            $this->balancedInputs($transaction),
            $this->changeAddress($transaction),
            $this->expiry($transaction),
            $transaction->hashHex(),
        );
    }

    /** 4. An output on the wrong network. */
    public function test_an_address_on_another_network_is_refused(): void
    {
        [$transaction, $bytes] = $this->fixture();

        // The same transaction, read by a gate configured for the other network. Real funds to an
        // address nobody on the configured network holds is the failure this prevents.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wrong network');

        $this->gate(Network::Testnet)->assert(
            $bytes,
            $this->balancedInputs($transaction),
            $this->changeAddress($transaction),
            $this->expiry($transaction),
            $transaction->hashHex(),
        );
    }

    /** 5. Change that does not go home. */
    public function test_change_paid_anywhere_but_the_campaign_address_is_refused(): void
    {
        [$transaction, $bytes] = $this->fixture();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not pay the campaign address');

        $this->gate()->assert(
            $bytes,
            $this->balancedInputs($transaction),
            'addr1qxsomebodyelsesaddressentirely',
            $this->expiry($transaction),
            $transaction->hashHex(),
        );
    }

    /** 6. A validity interval that runs past the script's expiry. */
    public function test_a_validity_interval_past_the_scripts_expiry_is_refused(): void
    {
        [$transaction, $bytes] = $this->fixture();

        $ttl = $transaction->body->ttl();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot sign past its own expiry');

        $this->gate()->assert(
            $bytes,
            $this->balancedInputs($transaction),
            $this->changeAddress($transaction),
            // An expiry the transaction's own bound already reaches.
            $ttl->toInt(),
            $transaction->hashHex(),
        );
    }

    /** 7. Past the size limit. */
    public function test_a_transaction_over_the_size_limit_is_refused(): void
    {
        [$transaction, $bytes] = $this->fixture();

        $gate = new InvariantGate(
            new LedgerParameters(
                txFeeFixed: 155381,
                txFeePerByte: 44,
                utxoCostPerByte: 4310,
                maxValueSize: 5000,
                maxTxSize: 10,
            ),
            Network::Mainnet,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no node would accept it');

        $gate->assert(
            $bytes,
            $this->balancedInputs($transaction),
            $this->changeAddress($transaction),
            $this->expiry($transaction),
            $transaction->hashHex(),
        );
    }

    /**
     * 8. A hash recorded for bytes other than the ones being submitted.
     *
     * The one that causes a double payment rather than a rejection: the transaction is fine, the
     * chain accepts it, and nothing can find it again under the hash that was written down.
     */
    public function test_a_hash_that_is_not_of_these_bytes_is_refused(): void
    {
        [$transaction, $bytes] = $this->fixture();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be found again');

        $this->gate()->assert(
            $bytes,
            $this->balancedInputs($transaction),
            $this->changeAddress($transaction),
            $this->expiry($transaction),
            str_repeat('0', 64),
        );
    }

    private function gateWith(int $fixed = 155381, int $perByte = 44, int $cost = 4310, int $maxValue = 5000): InvariantGate
    {
        return new InvariantGate(new LedgerParameters($fixed, $perByte, $cost, $maxValue, 16384), Network::Mainnet);
    }

    /** 2. An output under the minimum. */
    public function test_an_output_under_the_minimum_is_refused(): void
    {
        [$transaction, $bytes] = $this->fixture();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('less than the minimum');

        // Parameters under which every output of the fixture is too small.
        $this->gateWith(cost: 4_310_000)->assert(
            $bytes,
            $this->balancedInputs($transaction),
            $this->changeAddress($transaction),
            $this->expiry($transaction),
            $transaction->hashHex(),
        );
    }

    /** 3. More fee than the size needs, beyond change too small to be an output. */
    public function test_a_fee_far_above_what_the_size_needs_is_refused(): void
    {
        [$transaction, $bytes] = $this->fixture();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('float handed to the network');

        // Under these parameters the fixture needs a few hundred lovelace and an output minimum
        // is a few hundred more, so its real fee is most of a campaign's change handed away.
        $this->gateWith(fixed: 0, perByte: 1, cost: 1)->assert(
            $bytes,
            $this->balancedInputs($transaction),
            $this->changeAddress($transaction),
            $this->expiry($transaction),
            $transaction->hashHex(),
        );
    }

    /** 7. A value larger than the ledger allows in one output. */
    public function test_an_output_over_the_value_size_limit_is_refused(): void
    {
        [$transaction, $bytes] = $this->fixture();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('larger than the ledger allows');

        $this->gateWith(maxValue: 3)->assert(
            $bytes,
            $this->balancedInputs($transaction),
            $this->changeAddress($transaction),
            $this->expiry($transaction),
            $transaction->hashHex(),
        );
    }

    /** 6. No upper validity bound at all. */
    public function test_a_transaction_with_no_upper_bound_is_refused(): void
    {
        [$fixture] = $this->fixture();
        $address = $this->changeAddress($fixture);

        $build = fn (int $fee): string => Transaction::assemble(
            TransactionBodyBuilder::create()
                ->inputs([TransactionInput::of(str_repeat("\x01", 32), 0)])
                ->outputs([TransactionOutput::create(Address::fromBech32($address)->toBytes(), Value::of(50_000_000))])
                ->fee($fee)
                ->networkId(1)
                ->build(),
            WitnessSet::of([], []),
        )->encode();

        $fee = FeeCalculator::under($this->parameters())->forBytes($build(200_000))->toInt();
        $bytes = $build($fee);
        $hash = TransactionDecoder::decode($bytes)->hashHex();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no upper validity bound');

        $this->gate()->assert($bytes, [['coin' => 50_000_000 + $fee]], $address, 200_000_000, $hash);
    }

    /**
     * The gate decodes the bytes it is given. A hash recorded for a different, perfectly sound
     * transaction does not match them.
     */
    public function test_bytes_that_are_not_the_transaction_whose_hash_was_recorded_are_refused(): void
    {
        [$transaction, $bytes] = $this->fixture();
        $other = 'a0'.substr(hash('sha256', 'another transaction'), 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be found again');

        $this->gate()->assert(
            $bytes,
            $this->balancedInputs($transaction),
            $this->changeAddress($transaction),
            $this->expiry($transaction),
            $other,
        );
    }
}

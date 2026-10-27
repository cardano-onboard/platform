<?php

namespace App\Cardano;

use App\Support\Pricing;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Ledger\FeeCalculator;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\MinimumUtxo;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;
use Cardano\Transaction\Script\NativeScript;
use RuntimeException;

/**
 * The last thing between a bug and somebody's money.
 *
 * Everything that builds a transaction is checked by tests, and tests check the cases somebody
 * thought of. This runs in production, on every transaction, immediately before it is submitted.
 * It is given the bytes that will be sent and nothing the builder made: it decodes them and
 * re-derives everything from there. A builder that was wrong and a gate that asks the builder
 * would agree.
 *
 * None of these are style. Each one is a way to lose money that has a name:
 *
 *  1. Value that does not balance is either a transaction the ledger rejects or, where the excess
 *     is unaccounted, a fee paid to the network out of somebody's float.
 *  2. An output under the minimum is a transaction the ledger rejects, after the claim has been
 *     marked as paid.
 *  3. A fee under the minimum for the fully witnessed size is the same, discovered only once the
 *     witnesses are attached and the size is final. A fee more than one output's worth above the
 *     minimum is float handed to the network: the most that folding change into the fee can add is
 *     change too small to be an output.
 *  4. An address on the wrong network sends real funds to an address nobody on that network holds.
 *  5. Change to anywhere but the campaign's own address is the whole remaining float, gone.
 *  6. A validity interval with no upper bound is invalid, and one past the script's expiry is a
 *     transaction the branch that signs it cannot sign for.
 *  7. Past the size limit is a transaction no node accepts, and so is an output whose value is past
 *     the value size limit.
 *  8. A hash recorded for bytes other than the ones submitted is a payment nothing can find again,
 *     which is how a claim gets paid twice.
 *  9. Where a platform fee is collected: a fees output that is not at the fee address, or not
 *     exactly the value it held plus this transaction's fees, either gives the platform less than
 *     the claims owed or hands a campaign's float to the fee address. A fee address paid by any
 *     other output is the same loss, and a fees input that is not the one the campaign recorded is
 *     a spend of somebody else's fees.
 */
final class InvariantGate
{
    public function __construct(
        private readonly LedgerParameters $parameters,
        private readonly Network $network,
    ) {}

    /**
     * @param  string  $bytes  the signed transaction exactly as it will be sent
     * @param  array<int, array{coin: string|int, assets?: array<string, string>}>  $inputs  the outputs being spent
     * @param  string  $changeAddress  the campaign's own address
     * @param  int  $expirySlot  the campaign script's expiry
     * @param  int  $payments  how many outputs, from the first, pay recipients; every output after
     *                         them has to pay $changeAddress, and a sweep has none
     * @param  ?array{address: string, input: ?string, previous: int|string, paid_before: int, path: string}  $fees
     *                                                                                                               where a platform fee is collected, from the campaign's own record and not from
     *                                                                                                               the builder: the fee address, the fees output the campaign recorded as its
     *                                                                                                               current one as hash#index (null when it has none) and the value it holds, how
     *                                                                                                               many claims the campaign had paid before these, and the pricing path. What
     *                                                                                                               these claims owe is worked out here from the tier schedule, and the fees
     *                                                                                                               output is the one right after the payments.
     * @param  list<string>  $scripts  the hashes of the scripts the inputs need, which the witness set
     *                                 must hold exactly: a script missing refuses the spend, and one
     *                                 nothing spends from is refused as extraneous
     *
     * @throws RuntimeException naming the invariant that failed and what it means
     */
    public function assert(
        string $bytes,
        array $inputs,
        string $changeAddress,
        int $expirySlot,
        string $recordedHash,
        int $payments = 0,
        ?array $fees = null,
        array $scripts = [],
    ): void {
        $transaction = TransactionDecoder::decode($bytes);

        $this->balances($transaction, $inputs);
        $this->outputsMeetMinimum($transaction);
        $this->addressesAreOnThisNetwork($transaction);

        $added = $fees === null ? 0 : (int) Pricing::marginalTotal($fees['path'], $fees['paid_before'], $payments);

        if ($fees !== null) {
            $this->feesAreCollectedExactly($transaction, $payments, $fees, $added);
        }

        $this->changeGoesHome($transaction, $changeAddress, $payments + ($added > 0 ? 1 : 0));
        $this->feeIsTheSizesFee($transaction, $bytes, $changeAddress);
        $this->validityIsBoundedAndInsideExpiry($transaction, $expirySlot);
        $this->sizeIsUnderTheLimit($bytes);
        $this->hashIsOfTheseBytes($transaction, $recordedHash);

        $this->scriptsAreExactlyThoseNeeded($transaction, $scripts);
    }

    /**
     * 1. Inputs equal outputs plus fee, per lovelace and per asset.
     *
     * Each input names its lovelace as `coin` and its tokens as `assets`, keyed by policy id and
     * asset name in hex. Outputs are read from the decoded bytes.
     * Sums are decimal strings, because a total of token quantities across several outputs is not
     * bounded by what a PHP integer holds.
     */
    private function balances(Transaction $transaction, array $inputs): void
    {
        $in = ['lovelace' => '0'];
        $out = ['lovelace' => (string) $transaction->body->fee()->value];

        foreach ($inputs as $utxo) {
            $in['lovelace'] = bcadd($in['lovelace'], (string) $utxo['coin']);

            foreach ($utxo['assets'] ?? [] as $unit => $quantity) {
                $in[$unit] = bcadd($in[$unit] ?? '0', (string) $quantity);
            }
        }

        foreach ($transaction->body->outputs() as $output) {
            $out['lovelace'] = bcadd($out['lovelace'], (string) $output->value->coin->value);

            foreach ($output->value->assets?->bundles() ?? [] as $bundle) {
                foreach ($bundle->assets() as [$name, $quantity]) {
                    $unit = $bundle->policyIdHex().bin2hex($name);
                    $out[$unit] = bcadd($out[$unit] ?? '0', $quantity->value);
                }
            }
        }

        foreach (array_unique([...array_keys($in), ...array_keys($out)]) as $unit) {
            $entering = $in[$unit] ?? '0';
            $leaving = $out[$unit] ?? '0';

            if (bccomp($entering, $leaving) === 0) {
                continue;
            }

            throw new RuntimeException($unit === 'lovelace'
                ? sprintf(
                    'Value does not balance: %s lovelace in, %s out. The difference is either a transaction the '
                    .'ledger refuses or, unaccounted, a fee paid to the network out of the campaign float.',
                    $entering,
                    $leaving,
                )
                : sprintf(
                    'Value does not balance for the token %s: %s in, %s out. The ledger refuses a transaction that '
                    .'does not account for every token it spends.',
                    $unit,
                    $entering,
                    $leaving,
                ));
        }
    }

    /** 2. Every output's coin is at or above the computed minimum. */
    private function outputsMeetMinimum(Transaction $transaction): void
    {
        $minimum = MinimumUtxo::under($this->parameters);

        foreach ($transaction->body->outputs() as $index => $output) {
            if (strlen($output->value->encode()) > $this->parameters->maxValueSize->toInt()) {
                throw new RuntimeException(sprintf(
                    'Output %d holds a value larger than the ledger allows in one output, so this transaction '
                    .'is refused after the claim it pays has been marked as sent.',
                    $index,
                ));
            }

            if (! $minimum->isSatisfiedBy($output)) {
                throw new RuntimeException(sprintf(
                    'Output %d holds less than the minimum the ledger requires for what it carries, so this '
                    .'transaction is refused after the claim it pays has been marked as sent.',
                    $index,
                ));
            }
        }
    }

    /**
     * 3. The fee is at or above the minimum for the fully witnessed size, and not more than one
     * output's minimum above it. Change too small to be an output is the most the builder folds in.
     */
    private function feeIsTheSizesFee(Transaction $transaction, string $bytes, string $changeAddress): void
    {
        $required = FeeCalculator::under($this->parameters)->forBytes($bytes)->toInt();
        $paid = $transaction->body->fee()->toInt();

        if ($paid < $required) {
            throw new RuntimeException(sprintf(
                'The fee is %d and these %d bytes need %d. Measured on the fully witnessed transaction, which '
                .'is the only size that matters and the last one anything finds out.',
                $paid,
                strlen($bytes),
                $required,
            ));
        }

        // What folding can add: the change that was too small to be an output, which is under the
        // minimum for one, plus the fee for the bytes of the output that was left out.
        $address = Address::fromBech32($changeAddress)->toBytes();
        $coin = MinimumUtxo::under($this->parameters)->forValue($address, Value::lovelace(0));
        $output = TransactionOutput::create($address, Value::lovelace($coin->value));
        $ceiling = FeeCalculator::under($this->parameters)->forSize(strlen($bytes) + strlen($output->encode()))->toInt()
            + $coin->toInt();

        if ($paid > $ceiling) {
            throw new RuntimeException(sprintf(
                'The fee is %d and these %d bytes need %d. Anything beyond change too small to be an output '
                .'is float handed to the network.',
                $paid,
                strlen($bytes),
                $required,
            ));
        }
    }

    /** 4. Every output address parses and carries this network's tag. */
    private function addressesAreOnThisNetwork(Transaction $transaction): void
    {
        foreach ($transaction->body->outputs() as $index => $output) {
            $address = Address::fromBytes($output->address);

            // The low nibble of the header byte is the network tag. There is no accessor for it,
            // and reading the bech32 prefix instead would trust a label rather than the bytes the
            // ledger reads.
            if (($address->header() & 0x0F) !== $this->network->value) {
                throw new RuntimeException(sprintf(
                    'Output %d pays an address on the wrong network, so real funds would go to an address '
                    .'nobody on this one holds.',
                    $index,
                ));
            }
        }
    }

    /**
     * 5. Everything after the payments goes to the campaign's address: the token remainders and
     * the ADA change. Nothing between the last recipient and the end may pay anywhere else.
     */
    private function changeGoesHome(Transaction $transaction, string $changeAddress, int $payments): void
    {
        $outputs = $transaction->body->outputs();

        if ($outputs === []) {
            throw new RuntimeException('A transaction with no outputs pays nobody and returns nothing.');
        }

        // An output holds its address as raw bytes and the campaign's is stored as bech32, so one
        // is converted rather than compared to the other by length or prefix. Comparing the wrong
        // forms would always differ and refuse every transaction, which is a failure that looks
        // like caution.
        foreach (array_slice($outputs, $payments) as $output) {
            if (Address::fromBytes($output->address)->toBech32() !== $changeAddress) {
                throw new RuntimeException(
                    'An output after the payments does not pay the campaign address. Everything the campaign has '
                    .'left over goes wherever this points, and it is not somewhere it can be recovered from.'
                );
            }
        }
    }

    /**
     * 9. The fees output is at the fee address and holds exactly what the campaign's record says
     * it held plus what these claims owe by the tier schedule, nothing else in the transaction pays
     * the fee address, and the fees input is the one the campaign recorded.
     *
     * Where the claims owe nothing there is no fees output, the recorded one is not spent, and
     * nothing pays the fee address at all.
     *
     * @param  array{address: string, input: ?string, previous: int|string, paid_before: int, path: string}  $fees
     */
    private function feesAreCollectedExactly(Transaction $transaction, int $payments, array $fees, int $added): void
    {
        $outputs = $transaction->body->outputs();
        $spent = array_map(
            static fn ($input): string => $input->transactionIdHex().'#'.$input->index->toInt(),
            $transaction->body->inputs(),
        );

        if ($added === 0) {
            foreach ($outputs as $index => $other) {
                if (Address::fromBytes($other->address)->toBech32() === $fees['address']) {
                    throw new FeeInvariantFailed(sprintf('Output %d pays the fee address, and these claims owe no fee.', $index));
                }
            }

            if ($fees['input'] !== null && in_array($fees['input'], $spent, true)) {
                throw new FeeInvariantFailed('The fees output is spent by a payout whose claims owe no fee.');
            }

            return;
        }

        $output = $outputs[$payments] ?? null;

        if ($output === null || Address::fromBytes($output->address)->toBech32() !== $fees['address']) {
            throw new FeeInvariantFailed(
                'The output after the payments does not pay the fee address. This payout collects a platform fee, '
                .'and a fees output anywhere else gives the platform nothing for the claims it pays.'
            );
        }

        foreach ($output->value->assets?->bundles() ?? [] as $bundle) {
            foreach ($bundle->assets() as [$name]) {
                throw new FeeInvariantFailed(sprintf(
                    'The fees output carries the token %s.%s. It holds ADA and nothing else, or the fee address '
                    .'would be given the token.',
                    $bundle->policyIdHex(),
                    bin2hex($name),
                ));
            }
        }

        $expected = bcadd((string) $fees['previous'], (string) $added);

        if (bccomp((string) $output->value->coin->value, $expected) !== 0) {
            throw new FeeInvariantFailed(sprintf(
                'The fees output holds %s lovelace and should hold exactly %s: the %s it held plus the %s the '
                .'tier schedule makes these claims owe. Any other figure is a fee kept short or float given away.',
                $output->value->coin->value,
                $expected,
                $fees['previous'],
                $added,
            ));
        }

        foreach ($outputs as $index => $other) {
            if ($index !== $payments && Address::fromBytes($other->address)->toBech32() === $fees['address']) {
                throw new FeeInvariantFailed(sprintf(
                    'Output %d also pays the fee address. Only the fees output may, or a second one would be '
                    .'a fees output nothing tracks.',
                    $index,
                ));
            }
        }

        if ($fees['input'] !== null && ! in_array($fees['input'], $spent, true)) {
            throw new FeeInvariantFailed(sprintf(
                'The fees output this campaign recorded, %s, is not spent. Leaving it behind and opening a new '
                .'one would leave two fees outputs for one campaign.',
                $fees['input'],
            ));
        }

        if ($fees['input'] === null && bccomp((string) $fees['previous'], '0') !== 0) {
            throw new FeeInvariantFailed('A payout that spends no fees output cannot add to a value one held.');
        }
    }

    /**
     * 10. The scripts in the witness set are exactly the scripts the inputs need.
     *
     * The ledger refuses a spend whose script is missing and, separately, a transaction that
     * carries a script nothing it spends needs. Both look like a sound transaction to every other
     * check here, and neither is found until a node says so.
     *
     * @param  list<string>  $needed
     */
    private function scriptsAreExactlyThoseNeeded(Transaction $transaction, array $needed): void
    {
        $provided = array_map(
            static fn ($cbor): string => NativeScript::fromCbor((string) $cbor)->hashHex(),
            $transaction->witnessSet->nativeScripts(),
        );

        sort($provided);
        $needed = array_values(array_unique($needed));
        sort($needed);

        if ($provided !== $needed) {
            throw new RuntimeException(sprintf(
                'The witness set holds the scripts [%s] and the inputs need [%s]. A missing script refuses the spend '
                .'and an extra one refuses the transaction as extraneous.',
                implode(', ', $provided),
                implode(', ', $needed),
            ));
        }
    }

    /** 6. A finite upper bound, in the future, strictly inside the script's expiry. */
    private function validityIsBoundedAndInsideExpiry(Transaction $transaction, int $expirySlot): void
    {
        $upper = $transaction->body->ttl();

        if ($upper === null) {
            throw new RuntimeException(
                'This transaction has no upper validity bound. A before clause with no finite end is invalid, '
                .'and nothing would ever be able to say the transaction had expired rather than been lost.'
            );
        }

        if ($upper->toInt() >= $expirySlot) {
            throw new RuntimeException(sprintf(
                'The validity interval runs to slot %d and the campaign script expires at %d. The branch that '
                .'signs this cannot sign past its own expiry, so the transaction could never be accepted.',
                $upper->toInt(),
                $expirySlot,
            ));
        }
    }

    /** 7. At or under maxTxSize. */
    private function sizeIsUnderTheLimit(string $bytes): void
    {
        $limit = $this->parameters->maxTxSize->toInt();

        if (strlen($bytes) > $limit) {
            throw new RuntimeException(sprintf(
                'This transaction is %d bytes and the limit is %d, so no node would accept it.',
                strlen($bytes),
                $limit,
            ));
        }
    }

    /** 8. The recorded hash is of exactly the bytes that were decoded and checked. */
    private function hashIsOfTheseBytes(Transaction $transaction, string $recordedHash): void
    {
        $actual = $transaction->hashHex();

        if (! hash_equals($actual, $recordedHash)) {
            throw new RuntimeException(sprintf(
                'The hash about to be recorded is %s and these bytes hash to %s. A payment recorded under a hash '
                .'the chain does not carry cannot be found again, which is how a claim gets paid a second time.',
                $recordedHash,
                $actual,
            ));
        }
    }
}

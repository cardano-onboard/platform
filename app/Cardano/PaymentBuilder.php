<?php

namespace App\Cardano;

use App\Support\PlatformFee;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Builder\TransactionBodyBuilder;
use Cardano\Transaction\Exception\SelectionException;
use Cardano\Transaction\Ledger\FeeCalculator;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\MinimumUtxo;
use Cardano\Transaction\Ledger\ValueSize;
use Cardano\Transaction\Ledger\WitnessPlan;
use Cardano\Transaction\Primitives\AuxiliaryData;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionInput;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;
use Cardano\Transaction\Primitives\WitnessSet;
use Cardano\Transaction\Script\NativeScript;
use Cardano\Transaction\Selection\AssetId;
use Cardano\Transaction\Selection\CoinSelector;
use Cardano\Transaction\Selection\SelectionResult;
use Cardano\Transaction\Selection\Utxo;
use Cardano\Transaction\Selection\ValueBag;
use Cardano\Transaction\Selection\ValuePacker;
use Cardano\Transaction\Signing\SigningKey;
use Cardano\Transaction\Signing\TransactionSigner;
use CBOR\ListObject;
use CBOR\MapItem;
use CBOR\MapObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use RuntimeException;

/**
 * Turning a list of people to pay into a signed transaction.
 *
 * The fee is the awkward part and the reason this is not a single pass. A transaction's fee depends
 * on its size, its size depends on its witnesses, and its witnesses cannot be made until the body
 * they sign is final, which needs the fee. So the body is built twice: once with measuring
 * witnesses of the right size holding nothing, which gives the true size and therefore the true
 * fee, and once with that fee in it. The second body is what gets signed.
 *
 * Change goes back to the campaign's own address and nowhere else. That is checked again by the
 * gate before anything is submitted, because it is the difference between a campaign keeping its
 * float and losing all of it.
 *
 * A payout that collects the platform fee also spends the campaign's current fees output, if it has
 * one, and creates exactly one new one: the old value plus the fees of the claims this transaction
 * pays. The fees come out of the campaign's float with the rewards, so a claim is paid only
 * together with its fee, and a batch the float cannot cover in full pays the prefix it covers. Both
 * keys sign, and both scripts are in the witness set.
 */
final class PaymentBuilder
{
    public function __construct(
        private readonly LedgerParameters $parameters,
        private readonly Network $network,
    ) {}

    /**
     * Pay as many of the recipients as fit in one transaction, in the order given.
     *
     * An output below the live ledger minimum is raised to exactly that minimum and no further, so
     * a reward that has fallen behind the parameters still pays instead of refusing the batch.
     * When the whole batch is over maxTxSize, measured on the fully witnessed transaction with its
     * message, or the balance does not cover it, the count is cut to what fits or what is covered
     * and the rest are left for the next run, which the claims job schedules for whatever it left
     * unsent.
     *
     * @param  array<int, Utxo>  $available  every unspent output the campaign holds
     * @param  array<int, array{address: string, lovelace: int, tokens?: list<array{policy: string, name: string, amount: string}>}>  $recipients
     *                                                                                                                                             a recipient's tokens travel with its lovelace in one output
     * @param  int  $ttl  the last slot this may be accepted in, inside the script's expiry
     * @param  ?string  $message  the campaign's transaction message, attached as CIP-20 metadata
     * @param  ?array{address: string, script: NativeScript, key: SigningKey, previous: ?Utxo}  $fees
     *                                                                                                 where the platform fee is collected: the fee address, its script and key, and the
     *                                                                                                 campaign's current fees output. Each recipient then names its `fee` in lovelace.
     * @return array{transaction: Transaction, bytes: string, inputs: array<int, array{hash: string, index: int}>, paid: int, fees: ?array{input: ?string, index: int, previous: int, added: int}}
     *                                                                                                                                                                                             `paid` is how many of the recipients, from the first, this transaction pays
     */
    public function build(
        array $available,
        array $recipients,
        string $changeAddress,
        NativeScript $script,
        SigningKey $key,
        int $ttl,
        ?string $message = null,
        ?array $fees = null,
    ): array {
        if ($recipients === []) {
            throw new RuntimeException('A payment with no recipients pays nobody and still costs a fee.');
        }

        $minimum = MinimumUtxo::under($this->parameters);
        $sizes = ValueSize::under($this->parameters);
        $outputs = array_map(function (array $recipient) use ($minimum, $sizes): TransactionOutput {
            $value = ValueBag::of($recipient['lovelace'], array_map(
                static fn (array $token): array => [AssetId::fromHex($token['policy'], $token['name']), $token['amount']],
                $recipient['tokens'] ?? [],
            ))->toValue();

            if (! $sizes->fits($value)) {
                throw new RuntimeException('One reward holds more than the ledger allows in a single output.');
            }

            return $minimum->fund(self::addressBytes($recipient['address']), $value);
        }, $recipients);
        $auxiliary = self::message($message);
        $limit = $this->parameters->maxTxSize->toInt();

        // Size grows with the count, so one proportional cut nearly always lands under the limit.
        for ($count = count($outputs); $count > 0;) {
            try {
                $built = $this->assemble($available, array_slice($outputs, 0, $count), $changeAddress, $script, $key, $ttl, $auxiliary, $this->feesFor($recipients, $count, $fees));
            } catch (SelectionException $short) {
                // The balance does not cover this many. It pays as many as it does, the largest
                // first part of the batch that builds, and the rest wait for a top-up.
                $low = 0;
                $high = $count - 1;

                while ($low < $high) {
                    $mid = intdiv($low + $high + 1, 2);

                    try {
                        $this->assemble($available, array_slice($outputs, 0, $mid), $changeAddress, $script, $key, $ttl, $auxiliary, $this->feesFor($recipients, $mid, $fees));
                        $low = $mid;
                    } catch (SelectionException) {
                        $high = $mid - 1;
                    }
                }

                if ($low === 0) {
                    throw $short;
                }

                $count = $low;

                continue;
            }

            $size = strlen($built['bytes']);

            if ($size <= $limit) {
                return $built + ['paid' => $count];
            }

            $count = min($count - 1, intdiv($count * $limit, $size));
        }

        throw new RuntimeException('Not even one payment fits under the maximum transaction size.');
    }

    /**
     * What the fees output and its input are for the first $count recipients, or null where no fee
     * is collected.
     *
     * @param  array<int, array<string, mixed>>  $recipients
     * @param  ?array{address: string, script: NativeScript, key: SigningKey, previous: ?Utxo}  $fees
     * @return ?array{output: TransactionOutput, input: ?Utxo, added: int, script: NativeScript, key: SigningKey}
     */
    private function feesFor(array $recipients, int $count, ?array $fees): ?array
    {
        if ($fees === null) {
            return null;
        }

        $added = array_sum(array_map(self::feeOf(...), array_slice(array_values($recipients), 0, $count)));

        // A batch whose claims pay nothing leaves the fees output alone: spending it and making
        // it again would only cost a witness and a fee for the same value.
        if ($added === 0) {
            return null;
        }

        $previous = $fees['previous']?->coin()->toInt() ?? 0;
        $output = TransactionOutput::create(self::addressBytes($fees['address']), Value::of($previous + $added));

        // With no fees output to spend, this payout's fees are all the new one holds, and the
        // ledger refuses an output under its minimum. The caller opens one only when the first
        // claim's fee covers it on its own, so every prefix does; this is the check that says so.
        if ($fees['previous'] === null && $added < PlatformFee::outputMinimum($this->parameters->utxoCostPerByte->toInt())) {
            throw new RuntimeException(sprintf(
                'The platform fees of these claims come to %d lovelace, which is under the ledger minimum for the '
                .'fees output they would open.',
                $added,
            ));
        }

        return [
            'output' => $output,
            'input' => $fees['previous'],
            'added' => $added,
            'script' => $fees['script'],
            'key' => $fees['key'],
        ];
    }

    /**
     * @param  array<string, mixed>  $recipient
     */
    private static function feeOf(array $recipient): int
    {
        $fee = $recipient['fee'] ?? throw new RuntimeException('A claim paid in a payout that collects the platform fee names its fee.');

        return max(0, (int) $fee);
    }

    /**
     * @param  array<int, Utxo>  $available
     * @param  array<int, TransactionOutput>  $outputs
     * @param  ?array{output: TransactionOutput, input: ?Utxo, added: int, script: NativeScript, key: SigningKey}  $fees
     * @return array{transaction: Transaction, bytes: string, inputs: array<int, array{hash: string, index: int}>, fees: ?array{input: ?string, index: int, previous: int, added: int}}
     */
    private function assemble(
        array $available,
        array $outputs,
        string $changeAddress,
        NativeScript $script,
        SigningKey $key,
        int $ttl,
        ?AuxiliaryData $auxiliary,
        ?array $fees = null,
    ): array {
        // The fees come out of the campaign's float with the rewards. The fees output's own input
        // brings the value it already held, so only what is added is owed.
        $owed = array_sum(array_map(static fn (TransactionOutput $output): int => $output->value->coin->toInt(), $outputs))
            + ($fees['added'] ?? 0);

        // What the recipients take besides lovelace. Selection pulls the inputs holding it.
        $sent = ValueBag::empty();

        foreach ($outputs as $output) {
            $sent = $sent->plus(ValueBag::fromValue($output->value)->withCoin('0'));
        }

        // One witness: the campaign key, and the fee key beside it where a fee is collected. The
        // scripts' other branches are not being used, and a plan that counted them would pay a fee
        // for witnesses that will not be there.
        $plan = WitnessPlan::forSignatures($fees === null ? 1 : 2);
        // The fee script is in the witness set only when a fees output is spent. A script nothing
        // spends from is extraneous, and the ledger refuses a transaction that carries one.
        $scripts = ($fees['input'] ?? null) === null ? [$script] : [$script, $fees['script']];

        // The fee depends on the size, the size on the inputs selected and on the bytes that write
        // the fee and the change, and the inputs selected on the fee. So it is measured, applied,
        // selected against, and measured again until it covers what it is measured on. It only
        // ever rises, which is why it settles, and the bound says so if a case does not.
        $fee = 0;

        for ($pass = 0; ; $pass++) {
            $selection = $this->select($available, $sent, $owed + $fee, $changeAddress);
            $selected = $selection->selected->coin->toInt();

            // Whatever the selected inputs hold beyond what is sent goes back to the campaign, each
            // asset class in an output of its own, funded by its own minimum.
            $tokens = self::assetsOf($selection->surplus);
            $carry = $this->minimumToCarryEach($changeAddress, $tokens);
            $measured = $this->feeFor($selection, $outputs, $changeAddress, $selected - $owed - $fee - $carry, $tokens, $fee, $scripts, $plan, $ttl, $auxiliary, $fees);

            if ($measured <= $fee) {
                break;
            }

            if ($pass === 7) {
                throw new RuntimeException(
                    'The fee for this transaction did not settle. Rather than submit one whose fee is short by the '
                    .'bytes the fee itself needed, nothing is sent.'
                );
            }

            $fee = $measured;
        }

        $change = $selected - $owed - $fee - $carry;

        // ADA change under the minimum cannot be an output of its own, and the ledger would
        // refuse it. It goes to the fee instead, which the smaller transaction still covers.
        if ($change > 0 && ! MinimumUtxo::under($this->parameters)->isSatisfiedBy(
            TransactionOutput::create(self::addressBytes($changeAddress), Value::of($change)),
        )) {
            $fee += $change;
            $change = 0;
        }

        $body = $this->body($selection, $outputs, $changeAddress, $change, $tokens, $fee, $ttl, $auxiliary, $fees);

        // Signed with the real keys, and the witness set carries the scripts as well, because a node
        // asked to spend from a script address needs the script that guards it.
        $signed = TransactionSigner::sign(
            Transaction::assemble($body, WitnessSet::of([], $scripts), $auxiliary),
            $key,
            ...($fees === null ? [] : [$fees['key']]),
        );

        return [
            'transaction' => $signed,
            'bytes' => $signed->encode(),
            'inputs' => self::references(self::spent($selection, $fees)),
            'fees' => $fees === null ? null : [
                'input' => $fees['input']?->reference(),
                'index' => count($outputs),
                'previous' => $fees['input']?->coin()->toInt() ?? 0,
                'added' => $fees['added'],
            ],
        ];
    }

    /**
     * Everything the transaction spends: the campaign's selected outputs and the fees output.
     *
     * @param  ?array{input: ?Utxo}  $fees
     * @return array<int, Utxo>
     */
    private static function spent($selection, ?array $fees): array
    {
        return [...array_values($selection->inputs), ...(($fees['input'] ?? null) === null ? [] : [$fees['input']])];
    }

    /**
     * Everything the campaign holds, to one address.
     *
     * Settlement's last act, and it differs from a payment in one way that matters: there is no
     * change. A sweep that left change behind would leave the campaign holding a balance nobody
     * intended it to keep, and one that got the arithmetic wrong would leave dust at an address
     * whose key is about to stop signing.
     *
     * So the amount is everything the selected outputs hold less the fee, and the fee settles the
     * same way it does for a payment: it is part of the size it determines.
     *
     * Only as many of the outputs as fit under maxTxSize, measured on the signed transaction, are
     * taken, from the first. The caller sweeps what is left in a transaction of its own, which
     * `inputs` lets it tell.
     *
     * @param  array<int, Utxo>  $available
     * @return array{transaction: Transaction, bytes: string, inputs: array<int, array{hash: string, index: int}>}
     */
    public function sweep(
        array $available,
        string $destination,
        NativeScript $script,
        SigningKey $key,
        int $ttl,
    ): array {
        if ($available === []) {
            throw new RuntimeException('There is nothing at this campaign address to return.');
        }

        $limit = $this->parameters->maxTxSize->toInt();

        for ($count = count($available); $count > 0;) {
            $built = $this->sweepInputs(array_slice($available, 0, $count), $destination, $script, $key, $ttl);
            $size = strlen($built['bytes']);

            if ($size <= $limit) {
                return $built;
            }

            $count = min($count - 1, intdiv($count * $limit, $size));
        }

        throw new RuntimeException('Not even one output fits in a sweep under the maximum transaction size.');
    }

    /**
     * @param  array<int, Utxo>  $available
     * @return array{transaction: Transaction, bytes: string, inputs: array<int, array{hash: string, index: int}>}
     */
    private function sweepInputs(
        array $available,
        string $destination,
        NativeScript $script,
        SigningKey $key,
        int $ttl,
    ): array {
        $everything = ValueBag::empty();

        foreach ($available as $utxo) {
            $everything = $everything->plus($utxo->value);
        }

        $held = $everything->coin->toInt();

        // Tokens go with the ADA. Anybody can send a token to an address, and spam tokens
        // routinely are, so a campaign address holding one is ordinary. Leaving it behind would
        // mean the one input carrying it could never be spent, and sending the ADA without it is
        // a transaction the ledger refuses.
        $tokens = self::assetsOf($everything);

        $plan = WitnessPlan::forSignatures(1);
        $selection = CoinSelector::unlimited()->select($available, $everything);

        $fee = 0;

        for ($pass = 0; $pass < 8; $pass++) {
            $measured = $this->sweepFee($selection, $destination, $held - $fee, $tokens, $fee, $script, $plan, $ttl);

            if ($measured === $fee) {
                break;
            }

            $fee = $measured;
        }

        if ($this->sweepFee($selection, $destination, $held - $fee, $tokens, $fee, $script, $plan, $ttl) > $fee) {
            throw new RuntimeException(
                'The fee for this sweep did not settle, so nothing is sent rather than sending one whose fee '
                .'is short by the bytes the fee itself needed.'
            );
        }

        $returned = $held - $fee;

        // Under the minimum there is nothing to be done. Sending it would be refused by the ledger,
        // and splitting it cannot help because the shortfall is the whole balance.
        try {
            $sent = $returned > 0 ? $this->outputsTo($destination, $returned, $tokens) : [];
        } catch (SelectionException) {
            $sent = [];
        }

        $minimum = MinimumUtxo::under($this->parameters);

        if ($sent === [] || array_filter($sent, static fn (TransactionOutput $output): bool => ! $minimum->isSatisfiedBy($output)) !== []) {
            throw new RuntimeException(sprintf(
                'What this campaign holds, %d lovelace%s, does not cover the fee to return it and leave outputs '
                .'the ledger would accept. There is nothing here that can be sent anywhere.',
                $held,
                $tokens->hasAssets() ? sprintf(' and %d kind(s) of token', $tokens->assetCount()) : '',
            ));
        }

        $body = $this->sweepBody($selection, $sent, $fee, $ttl);

        $signed = TransactionSigner::sign(
            Transaction::assemble($body, WitnessSet::of([], [$script])),
            $key,
        );

        return [
            'transaction' => $signed,
            'bytes' => $signed->encode(),
            'inputs' => self::references($selection->inputs),
        ];
    }

    /**
     * Measured against the fee it is being asked about, not against zero.
     *
     * The same fixed point a payment settles to, for the same reason: the bytes needed to write the
     * fee are part of the size the fee is computed from. Measuring with a zero fee gives a number
     * short by exactly those bytes, which a node refuses.
     */
    private function sweepFee($selection, string $destination, int $returned, ValueBag $tokens, int $fee, NativeScript $script, WitnessPlan $plan, int $ttl): int
    {
        $body = $this->sweepBody($selection, $this->measuringOutputsTo($destination, $returned, $tokens), $fee, $ttl);

        return FeeCalculator::under($this->parameters)->forBytes(
            Transaction::assemble($body, WitnessSet::of($plan->dummyWitnesses(), [$script]))->encode(),
        )->toInt();
    }

    private function sweepBody($selection, array $outputs, int $fee, int $ttl)
    {
        return TransactionBodyBuilder::create()
            ->inputs(self::transactionInputs($selection->inputs))
            ->outputs($outputs)
            ->fee($fee)
            ->ttl($ttl)
            ->networkId($this->network->value)
            ->build();
    }

    /**
     * The fee this transaction costs, measured on a body of the size it will actually be.
     */
    private function feeFor(
        $selection,
        array $outputs,
        string $changeAddress,
        int $change,
        ValueBag $tokens,
        int $fee,
        array $scripts,
        WitnessPlan $plan,
        int $ttl,
        ?AuxiliaryData $auxiliary,
        ?array $fees = null,
    ): int {
        // Measured against the fee and change it is being asked about, not against placeholders.
        // The whole reason this is called more than once is that those two values are part of the
        // size they determine.
        $measured = $this->body($selection, $outputs, $changeAddress, max(0, $change), $tokens, $fee, $ttl, $auxiliary, $fees);

        $withWitnesses = Transaction::assemble(
            $measured,
            WitnessSet::of($plan->dummyWitnesses(), $scripts),
            $auxiliary,
        );

        return FeeCalculator::under($this->parameters)->forBytes($withWitnesses->encode())->toInt();
    }

    private function body(
        $selection,
        array $outputs,
        string $changeAddress,
        int $change,
        ValueBag $tokens,
        int $fee,
        int $ttl,
        ?AuxiliaryData $auxiliary,
        ?array $fees = null,
    ) {
        // The fees output follows the payments, then the change last, which is where the gate looks
        // for it, where a reader expects it, and what the next payment builds on: one output per
        // asset class returned, then the ADA.
        $outputs = [
            ...$outputs,
            ...($fees === null ? [] : [$fees['output']]),
            ...$this->changeOutputs($changeAddress, $tokens, $change),
        ];

        $builder = TransactionBodyBuilder::create()
            ->inputs(self::transactionInputs(self::spent($selection, $fees)))
            ->outputs($outputs)
            ->fee($fee)
            // Every transaction carries a bounded upper validity. Without one nothing could ever say
            // a payment had expired rather than gone missing, and expiry is the only condition
            // under which rebuilding a payment is safe.
            ->ttl($ttl)
            ->networkId($this->network->value);

        return ($auxiliary === null ? $builder : $builder->auxiliaryData($auxiliary))->build();
    }

    /**
     * The campaign's transaction message as CIP-20 auxiliary data: label 674, a map holding "msg",
     * a list of strings of at most 64 bytes of UTF-8 each. A longer message goes in as several
     * lines, split at character boundaries, which the specification allows.
     */
    private static function message(?string $message): ?AuxiliaryData
    {
        if ($message === null || $message === '') {
            return null;
        }

        $lines = [];

        for ($rest = $message; $rest !== '';) {
            $line = mb_strcut($rest, 0, 64, 'UTF-8');
            $lines[] = TextStringObject::create($line);
            $rest = substr($rest, strlen($line));
        }

        return AuxiliaryData::fromCbor(MapObject::create([
            MapItem::create(UnsignedIntegerObject::create(674), MapObject::create([
                MapItem::create(TextStringObject::create('msg'), ListObject::create($lines)),
            ])),
        ]));
    }

    /**
     * Select the inputs that hold what is sent, and enough ADA on top for the payments, the fee and
     * the minimum each returned asset class needs in its own change output.
     *
     * The selector takes the inputs holding the sent assets first, then ADA-only inputs ahead of
     * ones carrying tokens. An input that brings an asset that is not being sent brings its
     * minimum with it, so the target is raised by that and the selection made again until what is
     * selected covers it.
     *
     * @param  array<int, Utxo>  $available
     */
    private function select(array $available, ValueBag $sent, int $coin, string $changeAddress): SelectionResult
    {
        $extra = 0;

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $selection = CoinSelector::unlimited()->select($available, $sent->withCoin($coin + $extra));
            $needed = $this->minimumToCarryEach($changeAddress, self::assetsOf($selection->surplus));

            if ($selection->selected->coin->toInt() - $coin >= $needed) {
                return $selection;
            }

            $extra = $needed;
        }

        return $selection;
    }

    /**
     * The lovelace that returning these assets takes: each asset class is an output of its own, and
     * each output holds exactly its ledger minimum.
     */
    private function minimumToCarryEach(string $address, ValueBag $tokens): int
    {
        return array_sum(array_map(
            static fn (TransactionOutput $output): int => $output->value->coin->toInt(),
            $this->assetOutputs($address, $tokens),
        ));
    }

    /**
     * @return list<TransactionOutput>
     */
    private function assetOutputs(string $address, ValueBag $tokens): array
    {
        $minimum = MinimumUtxo::under($this->parameters);

        return array_map(
            static fn (array $asset): TransactionOutput => $minimum->fund(
                self::addressBytes($address),
                ValueBag::of(0, [$asset])->toValue(),
            ),
            $tokens->assets(),
        );
    }

    /**
     * The campaign's change: an output per asset class, then the ADA.
     *
     * @return list<TransactionOutput>
     */
    private function changeOutputs(string $address, ValueBag $tokens, int $ada): array
    {
        $outputs = $this->assetOutputs($address, $tokens);

        if ($ada > 0) {
            $outputs[] = TransactionOutput::create(self::addressBytes($address), Value::of($ada));
        }

        return $outputs;
    }

    /**
     * The least lovelace that holds these tokens at this address, across as many outputs as
     * maxValueSize makes them need. Measured with the widest coin an output can carry, so the
     * figure is never short.
     */
    private function minimumToCarry(string $address, ValueBag $tokens): int
    {
        $minimum = MinimumUtxo::under($this->parameters);
        $total = 0;

        foreach (ValuePacker::under($this->parameters)->pack($tokens->withCoin('18446744073709551615'), self::addressBytes($address)) as $output) {
            $total += $minimum->forOutput($output)->toInt();
        }

        return $total;
    }

    /**
     * Lovelace and tokens to one address, as the outputs the ledger will accept for them.
     *
     * @return list<TransactionOutput>
     */
    private function outputsTo(string $address, int $lovelace, ValueBag $tokens): array
    {
        if (! $tokens->hasAssets()) {
            return [TransactionOutput::create(self::addressBytes($address), Value::of($lovelace))];
        }

        return ValuePacker::under($this->parameters)->pack($tokens->withCoin((string) $lovelace), self::addressBytes($address));
    }

    /**
     * The same outputs for measuring, where the lovelace may not yet cover every minimum. Only the
     * size matters here, so a short amount is measured at the minimum instead of refused.
     *
     * @return list<TransactionOutput>
     */
    private function measuringOutputsTo(string $address, int $lovelace, ValueBag $tokens): array
    {
        if (! $tokens->hasAssets()) {
            return [TransactionOutput::create(self::addressBytes($address), Value::of(max(1, $lovelace)))];
        }

        try {
            return $this->outputsTo($address, $lovelace, $tokens);
        } catch (SelectionException) {
            return $this->outputsTo($address, $this->minimumToCarry($address, $tokens), $tokens);
        }
    }

    /**
     * @param  array<int, Utxo>  $utxos
     * @return list<TransactionInput>
     */
    private static function transactionInputs(array $utxos): array
    {
        return array_map(
            static fn (Utxo $utxo): TransactionInput => TransactionInput::of(hex2bin($utxo->transactionId), $utxo->index),
            array_values($utxos),
        );
    }

    /**
     * @param  array<int, Utxo>  $utxos
     * @return list<array{hash: string, index: int}>
     */
    private static function references(array $utxos): array
    {
        return array_map(
            static fn (Utxo $utxo): array => ['hash' => $utxo->transactionId, 'index' => $utxo->index],
            array_values($utxos),
        );
    }

    /**
     * The tokens in a bag, without its lovelace.
     */
    private static function assetsOf(ValueBag $bag): ValueBag
    {
        return $bag->withCoin('0');
    }

    /**
     * An address as the ledger holds it.
     *
     * The application stores and shows bech32, because that is what a person reads and what a
     * wallet is given. A transaction carries the bytes that string encodes. Converting here rather
     * than at every call site means there is one place where the two forms meet, and a bech32
     * string that reached a transaction body unconverted would be a valid-looking output paying
     * nobody.
     */
    private static function addressBytes(string $address): string
    {
        return Address::fromBech32($address)->toBytes();
    }
}

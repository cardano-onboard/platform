<?php

namespace Tests\Support;

use App\Cardano\ChainData;
use App\Cardano\ChainStatusReader;
use App\Cardano\ChainSubmitter;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Script\NativeScript;
use CardanoPhp\DataClient\DTOs\Address\Asset;
use CardanoPhp\DataClient\DTOs\Address\Utxo;
use CardanoPhp\DataClient\DTOs\Address\Value;
use CardanoPhp\DataClient\DTOs\Epoch\ProtocolParams\ProtocolParams;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Contracts\Foundation\Application;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * A chain for tests of the native backend, answering in the provider package's own types.
 *
 * Every unspent output it hands out is the package's Utxo DTO, made with the package's own
 * constructors, because a fake that returns a plain object with whatever field names the code under
 * test happens to read is a fake that agrees with a bug. That is how a balance came to be read as a
 * count of outputs and survived every test.
 *
 * It behaves like a chain in the ways the backend depends on: a submitted transaction sits in a
 * mempool until it is landed, landing spends its inputs and creates its outputs from its own bytes,
 * a landed transaction has a depth, and resubmitting one whose inputs are spent is refused the way a
 * node refuses it.
 */
final class FakeCardanoChain
{
    public int $slot = 100_000_000;

    public int $time = 1_790_000_000;

    public int $coinsPerUtxoByte = 4310;

    /** How many times the provider was asked about one output by reference. */
    public int $unspentCalls = 0;

    /** @var array<string, true> outputs spent, by a landed transaction or by somebody else */
    public array $spent = [];

    /** @var null|callable(string): void run just after the provider has answered whether an output is spent */
    public $afterSpentRead = null;

    /** @var null|callable(string, int): ?int a way to make the provider answer an output's value differently */
    public $unspentAnswer = null;

    /** @var array<string, array<string, Utxo>> address, then reference, to the output */
    public array $utxos = [];

    /** @var array<string, int> transaction hash to how many blocks are on top of it */
    public array $depths = [];

    /** @var array<string, string> transaction hash to the bytes submitted, in submission order */
    public array $mempool = [];

    /** @var list<string> every hash offered, including repeats */
    public array $offered = [];

    public bool $statusDown = false;

    public bool $utxosDown = false;

    /**
     * When set, every status read first puts whatever is in the mempool into a block this many
     * blocks deep, oldest first, as though that much time had passed since the last read.
     */
    public ?int $landOnStatusRead = null;

    /** @var null|callable(string, string): ?ResponseInterface a way to refuse a submission */
    public $refuse = null;

    private int $funded = 0;

    public function parameters(): ProtocolParams
    {
        return new ProtocolParams(
            txFeePerByte: 44,
            txFeeFixed: 155381,
            utxoCostPerByte: $this->coinsPerUtxoByte,
            maxTxSize: 16384,
            maxValueSize: 5000,
        );
    }

    /**
     * Put an output at an address, as though somebody had paid it there.
     *
     * @param  list<array{string, string, string}>  $assets  policy hex, name hex, quantity
     */
    public function fund(string $address, int $lovelace, array $assets = [], int $index = 0): string
    {
        $hash = hash('sha256', 'funding-'.(++$this->funded));

        $this->utxos[$address][$hash.'#'.$index] = new Utxo(
            $hash,
            $index,
            $address,
            Value::of($lovelace, array_map(
                static fn (array $asset): Asset => new Asset($asset[0], $asset[1], $asset[2]),
                $assets,
            )),
        );

        return $hash;
    }

    /**
     * Put a transaction in a block: spend its inputs, create its outputs, give it a depth.
     */
    public function land(string $hash, int $depth = 0): void
    {
        $bytes = $this->mempool[$hash] ?? throw new RuntimeException("{$hash} was never submitted.");
        $transaction = TransactionDecoder::decode($bytes);

        foreach ($transaction->body->inputs() as $input) {
            $reference = $input->transactionIdHex().'#'.$input->index->toInt();
            $this->spent[$reference] = true;

            foreach ($this->utxos as $address => $outputs) {
                unset($this->utxos[$address][$reference]);
            }
        }

        foreach ($transaction->body->outputs() as $index => $output) {
            $address = Address::fromBytes($output->address)->toBech32();
            $assets = [];

            foreach ($output->value->assets?->bundles() ?? [] as $bundle) {
                foreach ($bundle->assets() as [$name, $quantity]) {
                    $assets[] = new Asset($bundle->policyIdHex(), bin2hex($name), $quantity->value);
                }
            }

            $this->utxos[$address][$hash.'#'.$index] = new Utxo(
                $hash,
                $index,
                $address,
                Value::of($output->value->coin->toInt(), $assets),
            );
        }

        unset($this->mempool[$hash]);
        $this->depths[$hash] = $depth;
    }

    /** Spend an output as somebody else would, outside every transaction here. */
    public function spendForeign(string $reference): void
    {
        foreach ($this->utxos as $address => $outputs) {
            unset($this->utxos[$address][$reference]);
        }

        $this->spent[$reference] = true;
    }

    /** The hashes submitted and not yet landed, oldest first. */
    public function pending(): array
    {
        return array_keys($this->mempool);
    }

    public function balance(string $address): int
    {
        return array_sum(array_map(
            static fn (Utxo $utxo): int => $utxo->value->lovelace,
            $this->utxos[$address] ?? [],
        ));
    }

    /**
     * Bind this chain into the container, for everything the native backend resolves.
     */
    public function bind(Application $app): void
    {
        $app->instance(ChainData::class, $this->chainData());
        $app->instance(ChainStatusReader::class, $this->statusReader());
        $app->bind(ChainSubmitter::class, fn () => new ChainSubmitter($this->http(), new HttpFactory, new HttpFactory));
    }

    public function chainData(): ChainData
    {
        $chain = $this;

        return new class($chain) extends ChainData
        {
            public function __construct(private readonly FakeCardanoChain $chain) {}

            public function tip(string $network): array
            {
                return ['slot' => $this->chain->slot, 'time' => $this->chain->time];
            }

            public function parameters(string $network): ProtocolParams
            {
                return $this->chain->parameters();
            }

            public function outputIsSpent(string $network, string $txHash, int $index): bool
            {
                $answer = isset($this->chain->spent[$txHash.'#'.$index]);

                if ($this->chain->afterSpentRead !== null) {
                    ($this->chain->afterSpentRead)($txHash.'#'.$index);
                }

                return $answer;
            }

            public function unspentOutput(string $network, string $txHash, int $index): ?int
            {
                $this->chain->unspentCalls++;

                if ($this->chain->utxosDown) {
                    throw new RuntimeException('the provider is down');
                }

                foreach ($this->chain->utxos as $outputs) {
                    if (isset($outputs[$txHash.'#'.$index])) {
                        $lovelace = $outputs[$txHash.'#'.$index]->value->lovelace;

                        return $this->chain->unspentAnswer === null ? $lovelace : ($this->chain->unspentAnswer)($txHash.'#'.$index, $lovelace);
                    }
                }

                return null;
            }

            public function utxos(string $network, string $address): array
            {
                if ($this->chain->utxosDown) {
                    throw new RuntimeException('the provider is down');
                }

                return array_values($this->chain->utxos[$address] ?? []);
            }
        };
    }

    public function statusReader(): ChainStatusReader
    {
        $chain = $this;

        return new class($chain) extends ChainStatusReader
        {
            public function __construct(private readonly FakeCardanoChain $chain) {}

            public function depths(string $network, array $hashes): array
            {
                if ($this->chain->statusDown) {
                    throw new RuntimeException('the status endpoint is down');
                }

                if ($this->chain->landOnStatusRead !== null) {
                    foreach ($this->chain->pending() as $pending) {
                        $this->chain->land($pending, $this->chain->landOnStatusRead);
                    }
                }

                return array_intersect_key($this->chain->depths, array_flip($hashes));
            }
        };
    }

    public function http(): ClientInterface
    {
        $chain = $this;

        return new class($chain) implements ClientInterface
        {
            public function __construct(private readonly FakeCardanoChain $chain) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->chain->submit((string) $request->getBody());
            }
        };
    }

    /**
     * What a node says to a submission.
     */
    public function submit(string $bytes): ResponseInterface
    {
        $factory = new HttpFactory;
        $transaction = TransactionDecoder::decode($bytes);
        $hash = $transaction->hashHex();
        $this->offered[] = $hash;

        if ($this->refuse !== null && ($response = ($this->refuse)($hash, $bytes)) !== null) {
            return $response;
        }

        if (isset($this->mempool[$hash])) {
            return $factory->createResponse(400)->withBody($factory->createStream('transaction already exists in the mempool'));
        }

        if (isset($this->depths[$hash])) {
            return $factory->createResponse(400)->withBody($factory->createStream('ConwayMempoolFailure (BadInputsUTxO ...)'));
        }

        // Every input has to exist, on chain or as an output of something already in the
        // mempool, which is what lets a child follow its parent before the parent is in a block.
        $available = [];

        foreach ($this->utxos as $outputs) {
            foreach (array_keys($outputs) as $reference) {
                $available[$reference] = true;
            }
        }

        foreach ($this->mempool as $pendingHash => $pendingBytes) {
            $pending = TransactionDecoder::decode($pendingBytes);

            foreach ($pending->body->inputs() as $input) {
                unset($available[$input->transactionIdHex().'#'.$input->index->toInt()]);
            }

            foreach (array_keys($pending->body->outputs()) as $index) {
                $available[$pendingHash.'#'.$index] = true;
            }
        }

        foreach ($transaction->body->inputs() as $input) {
            if (! isset($available[$input->transactionIdHex().'#'.$input->index->toInt()])) {
                return $factory->createResponse(400)->withBody($factory->createStream('ConwayUtxowFailure (BadInputsUTxO ...)'));
            }
        }

        // The ledger's rule on scripts, which a fake that only checked inputs would let a
        // wrongly built transaction through: every script an input needs is in the witness set,
        // and nothing else is.
        $needed = $this->scriptsNeededBy($transaction);
        $provided = array_map(
            static fn ($cbor): string => NativeScript::fromCbor((string) $cbor)->hashHex(),
            $transaction->witnessSet->nativeScripts(),
        );

        if (array_diff($needed, $provided) !== []) {
            return $factory->createResponse(400)->withBody($factory->createStream('ConwayUtxowFailure (MissingScriptWitnessesUTXOW ...)'));
        }

        if (array_diff($provided, $needed) !== []) {
            return $factory->createResponse(400)->withBody($factory->createStream('ConwayUtxowFailure (ExtraneousScriptWitnessesUTXOW ...)'));
        }

        $this->mempool[$hash] = $bytes;

        return $factory->createResponse(202)->withBody($factory->createStream('"'.$hash.'"'));
    }

    /**
     * The hashes of the scripts guarding the outputs this transaction spends, found at the
     * addresses of the outputs, whether they are on the chain or in the mempool.
     *
     * @return list<string>
     */
    private function scriptsNeededBy(\Cardano\Transaction\Primitives\Transaction $transaction): array
    {
        $addresses = [];

        foreach ($this->utxos as $address => $outputs) {
            foreach (array_keys($outputs) as $reference) {
                $addresses[$reference] = $address;
            }
        }

        foreach ($this->mempool as $pendingHash => $pendingBytes) {
            foreach (TransactionDecoder::decode($pendingBytes)->body->outputs() as $index => $output) {
                $addresses[$pendingHash.'#'.$index] = Address::fromBytes($output->address)->toBech32();
            }
        }

        $needed = [];

        foreach ($transaction->body->inputs() as $input) {
            $reference = $input->transactionIdHex().'#'.$input->index->toInt();

            // An input this cannot place is not skipped: a rule checked against the inputs it
            // happened to find would let a wrong transaction through.
            $address = $addresses[$reference] ?? throw new RuntimeException("The input {$reference} is at no address this chain knows.");
            $bytes = Address::fromBech32($address)->toBytes();

            // CIP-19: the header's high nibble is the address type, and types 1, 3, 5 and 7 have a
            // script hash as the payment part.
            if (in_array(ord($bytes[0]) >> 4, [1, 3, 5, 7], true)) {
                $needed[bin2hex(substr($bytes, 1, 28))] = true;
            }
        }

        return array_keys($needed);
    }
}

<?php

namespace App\Cardano;

use CardanoPhp\DataClient\DTOs\Address\Utxo;
use CardanoPhp\DataClient\DTOs\Address\Value;
use CardanoPhp\DataClient\DTOs\Epoch\ProtocolParams\ProtocolParams;
use CardanoPhp\DataClient\Enums\CardanoNetwork;
use CardanoPhp\DataClient\Exceptions\ProviderException;
use CardanoPhp\DataClient\Exceptions\UnsupportedNetwork;
use CardanoPhp\DataClient\Providers\Koios\KoiosClient;
use CardanoPhp\DataClient\Services\ProtocolParameterService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Where this application's configuration meets the chain-data package.
 *
 * The package is deliberately framework-free: it reads through a PSR-18 client it is handed,
 * caches through a PSR-16 cache it is handed and logs through a PSR-3 logger it is handed,
 * and it holds no opinion about where any of those come from. Everything Laravel-shaped about
 * reading the chain is therefore in this one class, which is the point of the split. A second
 * deployment of the package, in another application or none, wires the same three interfaces
 * to whatever it has.
 *
 * A client is bound to one network, because the endpoint is per network and the rows carry
 * nothing that says which chain answered. Campaigns on this platform run on whichever network
 * their operator chose, so the network is a runtime value and this is a factory rather than a
 * container binding.
 */
class ChainData
{
    /** @var array<string, KoiosClient> */
    private array $providers = [];

    /** @var array<string, ProtocolParameterService> */
    private array $parameters = [];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * The chain-data provider for one network.
     *
     * @throws UnsupportedNetwork for a name that is not a Cardano network, or one this
     *                            deployment has left without an endpoint
     */
    public function provider(string $network): KoiosClient
    {
        return $this->providers[$network] ??= new KoiosClient(
            CardanoNetwork::fromName($network),
            $this->http,
            $this->requests,
            $this->streams,
            [
                // Passed through even when it matches the package's own default, so that an
                // operator pointing a deployment at a self-hosted Koios changes one env var
                // and nothing reaches the public instance behind their back. An empty value
                // is a network this deployment refuses rather than one it guesses at.
                'base_url' => (string) config('cardano.koios.'.$network.'_url'),
                'token' => (string) config('cardano.koios.token'),
                'attempts' => max(1, (int) config('cardano.koios.retries', 3)),
            ],
        );
    }

    /**
     * The protocol parameters in force on a network, read once per epoch.
     *
     * @throws ProviderException
     */
    public function parameters(string $network): ProtocolParams
    {
        return $this->parameterService($network)->current();
    }

    /**
     * Every unspent output at an address, across as many provider pages as it takes.
     *
     * @return array<int, Utxo>
     *
     * @throws ProviderException
     */
    public function utxos(string $network, string $address): array
    {
        return $this->provider($network)->addressUtxos($address);
    }

    /**
     * The lovelace in one output if it is unspent, or null if it is spent or unknown.
     *
     * Asked for by reference, so the cost does not grow with everything else held at the same
     * address. The fee address holds an output for every campaign that has collected a fee, and
     * the one a campaign cares about is named by its record.
     *
     * @throws ProviderException
     */
    public function unspentOutput(string $network, string $txHash, int $index): ?int
    {
        $row = $this->outputRow($network, $txHash, $index);

        return $row === null || ($row['is_spent'] ?? false) === true || ! is_numeric($row['value'] ?? null) ? null : (int) $row['value'];
    }

    /**
     * Whether the provider says this output has been spent. An output it does not know is not
     * said to be spent: a child of a transaction still in the mempool spends outputs the provider
     * has never seen.
     *
     * @throws ProviderException
     */
    public function outputIsSpent(string $network, string $txHash, int $index): bool
    {
        return ($this->outputRow($network, $txHash, $index)['is_spent'] ?? false) === true;
    }

    /**
     * @return ?array<string, mixed>
     *
     * @throws ProviderException
     */
    private function outputRow(string $network, string $txHash, int $index): ?array
    {
        $base = rtrim((string) config('cardano.koios.'.$network.'_url'), '/');

        if ($base === '') {
            throw new ProviderException(sprintf('KOIOS_%s_URL is not set, so there is no chain data for %s.', strtoupper($network), $network));
        }

        $request = $this->requests->createRequest('POST', $base.'/utxo_info')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode(['_utxo_refs' => [$txHash.'#'.$index], '_extended' => false], JSON_THROW_ON_ERROR)));

        if ($token = (string) config('cardano.koios.token')) {
            $request = $request->withHeader('Authorization', 'Bearer '.$token);
        }

        // Tried again as the provider's other calls are, on a connection that fails, on a server
        // that answers with an error and on being told to slow down, and not on an answer that
        // says no.
        $attempts = max(1, (int) config('cardano.koios.retries', 3));

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http->sendRequest($request);

                if (($response->getStatusCode() >= 500 || $response->getStatusCode() === 429) && $attempt < $attempts) {
                    continue;
                }

                break;
            } catch (\Psr\Http\Client\ClientExceptionInterface $e) {
                if ($attempt >= $attempts) {
                    throw new ProviderException($e->getMessage(), 0, $e);
                }
            }
        }

        if ($response->getStatusCode() >= 400) {
            throw new ProviderException(sprintf('%s answered utxo_info with status %d.', $network, $response->getStatusCode()));
        }

        $rows = json_decode((string) $response->getBody(), true);

        if (! is_array($rows)) {
            throw new ProviderException(sprintf('%s answered utxo_info with something that is not a list.', $network));
        }

        foreach ($rows as $row) {
            if (is_array($row) && ($row['tx_hash'] ?? null) === $txHash && (int) ($row['tx_index'] ?? -1) === $index) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @throws ProviderException
     */
    public function balance(string $network, string $address): Value
    {
        return $this->provider($network)->addressBalance($address);
    }

    /**
     * Where the chain is now, as a slot and the time that slot happened at.
     *
     * Read rather than derived. A slot number can be worked out from an epoch and a known era
     * start, and doing so means writing down when each era began and how long its slots are: a
     * set of constants that are correct until a hard fork, silently wrong afterwards, and wrong
     * in a way that puts a bad expiry inside an address nobody can change. Asking the chain what
     * slot it is costs one request and cannot drift.
     *
     * @return array{slot: int, time: int}
     *
     * @throws ProviderException
     */
    public function tip(string $network): array
    {
        $base = rtrim((string) config('cardano.koios.'.$network.'_url'), '/');

        if ($base === '') {
            throw new ProviderException(sprintf('KOIOS_%s_URL is not set, so there is no chain data for %s.', strtoupper($network), $network));
        }

        // Through the PSR interfaces this class already holds rather than through the package's
        // own request helper, which the package marks internal. Reaching past a package's public
        // surface works until the package moves something, and a tip read is not worth owing that
        // to. The right home for this is the package itself, where the block contract already
        // describes it and the provider has yet to implement it.
        $request = $this->requests->createRequest('GET', $base.'/tip')
            ->withHeader('Accept', 'application/json');

        if ($token = (string) config('cardano.koios.token')) {
            $request = $request->withHeader('Authorization', 'Bearer '.$token);
        }

        $response = $this->http->sendRequest($request);

        if ($response->getStatusCode() >= 400) {
            throw new ProviderException(sprintf(
                '%s answered the tip with status %d.',
                $network,
                $response->getStatusCode(),
            ));
        }

        $rows = json_decode((string) $response->getBody(), true);

        if (! is_array($rows) || ! isset($rows[0]) || ! is_array($rows[0])) {
            throw new ProviderException(sprintf('%s answered the tip with something that is not a row.', $network));
        }

        $slot = $rows[0]['abs_slot'] ?? null;
        $time = $rows[0]['block_time'] ?? null;

        if (! is_numeric($slot) || ! is_numeric($time)) {
            throw new ProviderException(sprintf(
                '%s answered the tip without an absolute slot and a block time, so nothing here knows what '
                .'slot it is.',
                $network,
            ));
        }

        return ['slot' => (int) $slot, 'time' => (int) $time];
    }

    /**
     * Drop a network's cached epoch and the parameters cached under it, for the operator who
     * has just repointed a deployment at a different provider and should not have to wait out
     * an epoch to see it take effect.
     */
    public function forget(string $network): void
    {
        $this->parameterService($network)->forget();
    }

    private function parameterService(string $network): ProtocolParameterService
    {
        return $this->parameters[$network] ??= new ProtocolParameterService(
            $this->provider($network),
            $this->cache,
            $this->log,
            max(1, (int) config('cardano.protocol_params.tip_ttl', 60)),
        );
    }
}

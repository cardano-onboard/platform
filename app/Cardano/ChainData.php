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
     * @throws ProviderException
     */
    public function balance(string $network, string $address): Value
    {
        return $this->provider($network)->addressBalance($address);
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

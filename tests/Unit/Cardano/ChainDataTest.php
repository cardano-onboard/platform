<?php

namespace Tests\Unit\Cardano;

use App\Cardano\ChainData;
use CardanoPhp\DataClient\DTOs\Epoch\ProtocolParams\ProtocolParams;
use CardanoPhp\DataClient\Exceptions\UnsupportedNetwork;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/**
 * The seam between this application's configuration and the chain-data package.
 *
 * What the package does with a Koios response is the package's own suite's business, against responses recorded
 * from the live endpoints. What is asserted here is only the half that cannot exist inside a framework-free
 * package: that the endpoint and the token come from this application's configuration, that the cache the package
 * writes into is Laravel's, that the log it writes to is Laravel's, and that a network this deployment has not
 * configured is refused rather than read against a default.
 *
 * The transport is a recorded one because there is no other way to see the request that would have gone out. The
 * bodies below are the smallest valid Koios answers rather than recordings, for the same reason: this file is
 * about where the request is pointed, not about what comes back.
 */
class ChainDataTest extends TestCase
{
    /** Public because the recorded transport below is an anonymous class rather than a method of this one. */
    public const EPOCH = 313;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * A PSR-18 client that answers each Koios endpoint with the smallest valid response and keeps every request it
     * was given.
     */
    private function transport(array $overrides = []): ClientInterface
    {
        return new class($overrides) implements ClientInterface
        {
            /** @var list<RequestInterface> */
            public array $sent = [];

            public function __construct(private readonly array $overrides) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                $endpoint = basename($request->getUri()->getPath());

                $bodies = array_merge([
                    'tip' => [['epoch_no' => ChainDataTest::EPOCH]],
                    'epoch_params' => [[
                        'epoch_no' => ChainDataTest::EPOCH,
                        'min_fee_a' => 44,
                        'min_fee_b' => 155381,
                        'coins_per_utxo_size' => '4310',
                        'max_tx_size' => 16384,
                        'max_val_size' => 5000,
                    ]],
                    'cli_protocol_params' => new \stdClass,
                ], $this->overrides);

                return new Response(200, ['Content-Type' => 'application/json'], json_encode($bodies[$endpoint]));
            }
        };
    }

    private function chainData(ClientInterface $transport): ChainData
    {
        $this->app->instance(ClientInterface::class, $transport);

        return $this->app->make(ChainData::class);
    }

    public function test_the_endpoint_comes_from_this_deployments_configuration(): void
    {
        // An operator running their own Koios sets one environment variable. Without this the package's own
        // default would quietly send every read to the public instance instead.
        config(['cardano.koios.preprod_url' => 'https://koios.internal.example/api/v1/']);

        $transport = $this->transport();

        $this->chainData($transport)->provider('preprod')->currentEpochNumber();

        $this->assertSame('koios.internal.example', $transport->sent[0]->getUri()->getHost());
        $this->assertSame('/api/v1/tip', $transport->sent[0]->getUri()->getPath());
    }

    public function test_the_configured_token_is_sent(): void
    {
        config(['cardano.koios.token' => 'a-rate-limit-token']);

        $transport = $this->transport();

        $this->chainData($transport)->provider('preprod')->currentEpochNumber();

        $this->assertSame('Bearer a-rate-limit-token', $transport->sent[0]->getHeaderLine('Authorization'));
    }

    public function test_no_token_is_sent_when_none_is_configured(): void
    {
        config(['cardano.koios.token' => '']);

        $transport = $this->transport();

        $this->chainData($transport)->provider('preprod')->currentEpochNumber();

        $this->assertFalse($transport->sent[0]->hasHeader('Authorization'));
    }

    public function test_a_network_with_no_endpoint_configured_is_refused(): void
    {
        // Left to a default, a deployment that meant to configure an endpoint and did not would read the public
        // mainnet instance and report the figures under whatever network name it was asked about.
        config(['cardano.koios.mainnet_url' => '']);

        $this->expectException(UnsupportedNetwork::class);
        $this->expectExceptionMessage('mainnet');

        $this->chainData($this->transport())->provider('mainnet');
    }

    public function test_a_network_name_that_is_not_a_cardano_network_is_refused(): void
    {
        $this->expectException(UnsupportedNetwork::class);
        $this->expectExceptionMessage('prepod');

        $this->chainData($this->transport())->provider('prepod');
    }

    public function test_the_parameters_are_cached_in_this_applications_cache(): void
    {
        // The package caches through a PSR-16 interface and holds no opinion about the store. This is what says
        // the store it was handed is Laravel's, under the key the package builds.
        $chain = $this->chainData($this->transport());

        $this->assertSame(4310, $chain->parameters('preprod')->utxoCostPerByte);

        $this->assertInstanceOf(ProtocolParams::class, Cache::get('cardano.protocol-params.preprod.'.self::EPOCH));
        $this->assertSame(self::EPOCH, Cache::get('cardano.tip-epoch.preprod'));
    }

    public function test_a_second_read_in_the_same_epoch_does_not_ask_again(): void
    {
        $transport = $this->transport();
        $chain = $this->chainData($transport);

        $chain->parameters('preprod');
        $chain->parameters('preprod');

        $this->assertCount(3, $transport->sent);
    }

    public function test_forgetting_a_network_clears_what_this_application_cached(): void
    {
        $chain = $this->chainData($this->transport());

        $chain->parameters('preprod');
        $chain->forget('preprod');

        $this->assertNull(Cache::get('cardano.protocol-params.preprod.'.self::EPOCH));
        $this->assertNull(Cache::get('cardano.tip-epoch.preprod'));
    }

    public function test_a_disagreeing_cross_check_reaches_this_applications_log(): void
    {
        // The package logs through a PSR-3 interface. This is what says the logger it was handed is Laravel's, so
        // an operator sees the alert where they already look for one.
        Log::spy();

        $chain = $this->chainData($this->transport([
            'cli_protocol_params' => [
                'txFeePerByte' => 44,
                'txFeeFixed' => 155381,
                'utxoCostPerByte' => 9999,
                'maxTxSize' => 16384,
                'maxValueSize' => 5000,
            ],
        ]));

        $this->assertSame(4310, $chain->parameters('preprod')->utxoCostPerByte);

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context) => str_contains($message, 'disagree')
                && $context['parameter'] === 'utxoCostPerByte'
                && $context['network'] === 'preprod'
                && $context['untyped'] === 9999,
        )->once();
    }

    public function test_each_network_gets_its_own_client(): void
    {
        $transport = $this->transport();
        $chain = $this->chainData($transport);

        $chain->provider('preprod')->currentEpochNumber();
        $chain->provider('preview')->currentEpochNumber();

        $this->assertSame('preprod.koios.rest', $transport->sent[0]->getUri()->getHost());
        $this->assertSame('preview.koios.rest', $transport->sent[1]->getUri()->getHost());
        $this->assertNotSame($chain->provider('preprod'), $chain->provider('preview'));
    }
}

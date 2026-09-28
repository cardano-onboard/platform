<?php

namespace Tests\Unit\Services;

use App\Contracts\TransactionBackend;
use App\Models\Campaign;
use App\Services\MinUtxoService;
use App\Support\MinUtxo;
use Tests\TestCase;

/**
 * The service that joins the arithmetic to a live protocol parameter, and the three
 * answers it gives.
 *
 * The backend is a stand-in rather than a mock with expectations. What matters is what the
 * service does with the number it is handed, including when that number is nonsense, and a
 * stand-in can be handed nonsense.
 */
class MinUtxoServiceTest extends TestCase
{
    private function backend(array $parameters): TransactionBackend
    {
        return new class($parameters) implements TransactionBackend
        {
            public int $calls = 0;

            public function __construct(private array $parameters) {}

            public function createBucket(Campaign $campaign, string $network): array
            {
                return ['address' => '', 'campaignId' => ''];
            }

            public function submitPayment(string $campaignId, array $recipients, string $network, ?string $txnMsg = null): array
            {
                return ['purchaseIds' => []];
            }

            public function checkStatus(string $purchaseId, string $network): array
            {
                return ['status' => 'unknown', 'txHash' => null];
            }

            public function refund(string $campaignId, string $address, string $network): bool
            {
                return true;
            }

            public function getBalance(string $address, string $network): array
            {
                return [];
            }

            public function protocolParameters(string $network): array
            {
                $this->calls++;

                return $this->parameters;
            }
        };
    }

    private function bundle(int $quantity = 1): array
    {
        return [[
            'policy' => str_repeat('ab', 28),
            'asset' => bin2hex('HOSKY'),
            'quantity' => $quantity,
        ]];
    }

    public function test_it_multiplies_by_the_coefficient_the_backend_reports(): void
    {
        $service = new MinUtxoService($this->backend([
            'coins_per_utxo_byte' => 8620,
            'source' => 'koios',
        ]));

        $quote = $service->quote($this->bundle(), 'mainnet');

        $this->assertSame(8620, $quote['coins_per_utxo_byte']);
        $this->assertSame('koios', $quote['source']);
        $this->assertSame(2 * 1_159_390, $quote['min_lovelace']);
    }

    public function test_a_backend_that_reports_nothing_usable_falls_back_and_says_so(): void
    {
        foreach ([0, -1, null] as $reported) {
            $service = new MinUtxoService($this->backend([
                'coins_per_utxo_byte' => $reported,
                'source' => 'koios',
            ]));

            $quote = $service->quote($this->bundle(), 'mainnet');

            $this->assertSame(MinUtxo::defaultCoinsPerUtxoByte(), $quote['coins_per_utxo_byte']);
            $this->assertSame('default', $quote['source'], 'A fallback must not be presented as a live reading.');
            $this->assertGreaterThan(0, $quote['min_lovelace'], 'A bundle is never free.');
        }
    }

    public function test_the_parameter_is_read_once_per_network_per_request(): void
    {
        $backend = $this->backend(['coins_per_utxo_byte' => 4310, 'source' => 'koios']);
        $service = new MinUtxoService($backend);

        $service->quote($this->bundle(), 'mainnet');
        $service->quote($this->bundle(), 'mainnet');
        $service->quote($this->bundle(), 'preprod');

        $this->assertSame(2, $backend->calls, 'One read per network, not one per code on a page.');
    }

    public function test_an_amount_under_the_minimum_is_below_minimum(): void
    {
        $service = new MinUtxoService($this->backend(['coins_per_utxo_byte' => 4310, 'source' => 'koios']));

        $quote = $service->quote($this->bundle(), 'mainnet', 1_000_000);

        $this->assertSame('below_minimum', $quote['state']);
        $this->assertStringContainsString('1.15939', $quote['warning']);
        $this->assertStringContainsString('reject', $quote['warning']);
    }

    public function test_an_amount_on_the_minimum_is_tight_rather_than_refused(): void
    {
        config(['cardano.min_utxo.headroom_lovelace' => 1_000_000]);
        $service = new MinUtxoService($this->backend(['coins_per_utxo_byte' => 4310, 'source' => 'koios']));

        $quote = $service->quote($this->bundle(), 'mainnet', 1_159_390);

        $this->assertSame('tight', $quote['state']);
        $this->assertSame(2_159_390, $quote['recommended_lovelace']);
        $this->assertStringContainsString('0 ADA of room', $quote['warning']);
    }

    public function test_an_amount_clearing_the_headroom_says_nothing(): void
    {
        config(['cardano.min_utxo.headroom_lovelace' => 1_000_000]);
        $service = new MinUtxoService($this->backend(['coins_per_utxo_byte' => 4310, 'source' => 'koios']));

        $quote = $service->quote($this->bundle(), 'mainnet', 2_159_390);

        $this->assertSame('ok', $quote['state']);
        $this->assertNull($quote['warning']);
    }

    public function test_the_headroom_boundary_moves_with_configuration(): void
    {
        $service = new MinUtxoService($this->backend(['coins_per_utxo_byte' => 4310, 'source' => 'koios']));

        config(['cardano.min_utxo.headroom_lovelace' => 0]);
        $this->assertSame('ok', $service->quote($this->bundle(), 'mainnet', 1_159_390)['state']);

        config(['cardano.min_utxo.headroom_lovelace' => 5_000_000]);
        $this->assertSame('tight', $service->quote($this->bundle(), 'mainnet', 5_000_000)['state']);
    }

    public function test_no_amount_asked_about_means_no_verdict(): void
    {
        $service = new MinUtxoService($this->backend(['coins_per_utxo_byte' => 4310, 'source' => 'koios']));

        $quote = $service->quote($this->bundle(), 'mainnet');

        $this->assertNull($quote['state']);
        $this->assertNull($quote['warning']);
        $this->assertGreaterThan(0, $quote['min_lovelace']);
    }

    public function test_it_counts_assets_and_policies_the_way_the_ledger_does(): void
    {
        $service = new MinUtxoService($this->backend(['coins_per_utxo_byte' => 4310, 'source' => 'koios']));

        $quote = $service->quote([
            ['policy' => str_repeat('11', 28), 'asset' => bin2hex('ONE'), 'quantity' => 1],
            ['policy' => str_repeat('11', 28), 'asset' => bin2hex('TWO'), 'quantity' => 1],
            ['policy' => str_repeat('22', 28), 'asset' => bin2hex('THREE'), 'quantity' => 1],
            ['policy' => null, 'asset' => bin2hex('NOTANASSET'), 'quantity' => 1],
        ], 'mainnet');

        $this->assertSame(3, $quote['asset_count']);
        $this->assertSame(2, $quote['policy_count']);
    }

    public function test_form_rows_are_read_by_the_names_the_forms_post(): void
    {
        $assets = MinUtxoService::assetsFromRequest([
            ['policy_id' => str_repeat('ab', 28), 'token_id' => bin2hex('HOSKY'), 'quantity' => 7],
            ['policy_id' => 1234, 'token_id' => null, 'quantity' => '9'],
        ]);

        $this->assertSame(str_repeat('ab', 28), $assets[0]['policy']);
        $this->assertSame(bin2hex('HOSKY'), $assets[0]['asset']);
        $this->assertSame(7, $assets[0]['quantity']);

        // A policy that is not a string is not a policy, and a quantity that arrives as a
        // string still has to be a number by the time it reaches the arithmetic.
        $this->assertNull($assets[1]['policy']);
        $this->assertSame(9, $assets[1]['quantity']);
    }
}

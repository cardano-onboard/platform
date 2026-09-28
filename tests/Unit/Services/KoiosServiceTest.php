<?php

namespace Tests\Unit\Services;

use App\Services\KoiosService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KoiosServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function fakeResponse(): array
    {
        return [[
            'policy_id' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
            'asset_name' => '484f534b59',
            'asset_name_ascii' => 'HOSKY',
            'fingerprint' => 'asset17q7r59zlc3dgw0venc80pdv566q6yguw03f0d9',
            'token_registry_metadata' => [
                'name' => 'HOSKY Token',
                'ticker' => 'HOSKY',
                'decimals' => 0,
            ],
        ]];
    }

    public function test_asset_info_normalizes_registry_metadata(): void
    {
        Http::fake(['*' => Http::response($this->fakeResponse())]);

        $info = (new KoiosService)->assetInfo(
            'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
            '484f534b59',
            'mainnet',
        );

        $this->assertSame('HOSKY', $info['ticker']);
        $this->assertSame('HOSKY Token', $info['name']);
        $this->assertSame(0, $info['decimals']);
        $this->assertSame('HOSKY', $info['asset_name_ascii']);
    }

    public function test_asset_info_is_cached_and_not_refetched(): void
    {
        Http::fake(['*' => Http::response($this->fakeResponse())]);
        $service = new KoiosService;

        $service->assetInfo('a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235', '484f534b59', 'mainnet');
        $service->assetInfo('a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235', '484f534b59', 'mainnet');

        Http::assertSentCount(1);
    }

    public function test_asset_info_returns_null_on_failure(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $info = (new KoiosService)->assetInfo(
            'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235',
            '484f534b59',
            'mainnet',
        );

        $this->assertNull($info);
    }

    public function test_protocol_parameters_read_coins_per_utxo_size(): void
    {
        Http::fake(['*' => Http::response([['epoch_no' => 520, 'coins_per_utxo_size' => '4310']])]);

        $this->assertSame(
            ['coins_per_utxo_byte' => 4310],
            (new KoiosService)->protocolParameters('mainnet')
        );
    }

    public function test_protocol_parameters_are_cached_per_network(): void
    {
        Http::fake(['*' => Http::response([['coins_per_utxo_size' => '4310']])]);
        $service = new KoiosService;

        $service->protocolParameters('mainnet');
        $service->protocolParameters('mainnet');
        $service->protocolParameters('preprod');

        Http::assertSentCount(2);
    }

    /**
     * A failed read has to be distinguishable from a real answer, because the caller
     * multiplies by whatever it is given. Anything that is not a positive number is
     * reported as unknown rather than passed through, which would price every output in
     * the application at nothing.
     */
    public function test_protocol_parameters_report_unknown_rather_than_zero(): void
    {
        foreach ([[], [[]], [['coins_per_utxo_size' => '0']], [['coins_per_utxo_size' => 'unexpected']]] as $body) {
            Cache::flush();
            Http::fake(['*' => Http::response($body)]);

            $this->assertNull((new KoiosService)->protocolParameters('mainnet'));
        }

        Cache::flush();
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertNull((new KoiosService)->protocolParameters('mainnet'));
    }

    /**
     * A 200 whose body is not a list of rows is not the end of the registry.
     *
     * The sync walks pages until one comes back empty. A proxy or a CDN interstitial
     * answers 200 with something that does not decode, and reading that as an empty page
     * stopped the walk at whatever offset the outage happened on and reported a successful
     * sync of however much had been read. The page is retried instead, and a page that
     * really is empty still ends the walk.
     */
    public function test_a_two_hundred_that_is_not_rows_is_not_the_end_of_the_registry(): void
    {
        $attempt = 0;

        Http::fake(['*' => function () use (&$attempt) {
            $attempt++;

            return $attempt === 1
                ? Http::response('<html>Attention Required</html>', 200)
                : Http::response($this->fakeResponse());
        }]);

        $rows = (new KoiosService)->tokenRegistryPage(0, 1000, 'mainnet');

        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame(2, $attempt);
    }

    /**
     * A list is the shape, not the content.
     *
     * A provider under load answers 200 with a list carrying a message rather than rows. That
     * is a list, so a guard that asks only whether the body is a list hands it back as a page.
     * The caller then reads each element as an array, finds nothing in it, skips every row,
     * sees a page shorter than the limit and stops, so the rest of the registry is never
     * fetched and nothing reports a problem. That silent truncation is the whole reason this
     * guard exists.
     */
    public function test_a_two_hundred_carrying_a_list_of_strings_is_not_a_page(): void
    {
        $attempt = 0;

        Http::fake(['*' => function () use (&$attempt) {
            $attempt++;

            return $attempt === 1
                ? Http::response(['rate limit exceeded'], 200)
                : Http::response($this->fakeResponse());
        }]);

        $rows = (new KoiosService)->tokenRegistryPage(0, 1000, 'mainnet');

        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertIsArray($rows[0], 'A page has to hold rows, not strings.');
        $this->assertSame(2, $attempt, 'The list of strings was taken for a page and never retried.');
    }

    /** A page that genuinely holds nothing is still the end of the registry. */
    public function test_an_empty_registry_page_ends_the_walk(): void
    {
        Http::fake(['*' => Http::response([])]);

        $this->assertSame([], (new KoiosService)->tokenRegistryPage(0, 1000, 'mainnet'));
    }
}

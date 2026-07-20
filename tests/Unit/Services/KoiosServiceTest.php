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
}

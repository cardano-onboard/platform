<?php

namespace Tests\Unit\Services;

use App\Models\Campaign;
use App\Services\ProxyBackend;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProxyBackendTest extends TestCase
{
    private ProxyBackend $backend;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cardano.proxy_api_url'   => 'https://proxy.test/api/v1/proxy',
            'cardano.proxy_api_token' => 'test-token-123',
        ]);

        $this->backend = new ProxyBackend();
    }

    public function test_create_bucket_posts_to_proxy(): void
    {
        Http::fake([
            'proxy.test/*' => Http::response([
                'address'    => 'addr_test1bucket',
                'campaignId' => 'camp-123',
            ]),
        ]);

        $campaign = new Campaign();
        $campaign->name = 'My Campaign';
        $campaign->user_id = 'user-1';

        $result = $this->backend->createBucket($campaign, 'preprod');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/bucket')
                && $request->data()['name'] === 'user-1-My Campaign'
                && $request->data()['network'] === 'preprod'
                && $request->hasHeader('Authorization', 'Bearer test-token-123');
        });

        $this->assertEquals('addr_test1bucket', $result['address']);
        $this->assertEquals('camp-123', $result['campaignId']);
    }

    public function test_submit_payment_posts_to_proxy(): void
    {
        Http::fake([
            'proxy.test/*' => Http::response([
                'purchaseIds' => ['CODE1' => ['addr1' => 'purchase-abc']],
            ]),
        ]);

        $recipients = [['pooCode' => 'CODE1', 'address' => 'addr1', 'lovelace' => 2000000, 'tokens' => []]];
        $result = $this->backend->submitPayment('camp-123', $recipients, 'preprod', 'Hello');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/payment')
                && $request->data()['campaignId'] === 'camp-123'
                && $request->data()['txnMsg'] === 'Hello';
        });

        $this->assertEquals('purchase-abc', $result['purchaseIds']['CODE1']['addr1']);
    }

    public function test_submit_payment_omits_txn_msg_when_null(): void
    {
        Http::fake([
            'proxy.test/*' => Http::response(['purchaseIds' => []]),
        ]);

        $this->backend->submitPayment('camp-123', [], 'preprod');

        Http::assertSent(function ($request) {
            return !isset($request->data()['txnMsg']);
        });
    }

    public function test_check_status_sends_get_request(): void
    {
        Http::fake([
            'proxy.test/*' => Http::response([
                'status' => 'completed',
                'txHash' => 'hash-xyz',
            ]),
        ]);

        $result = $this->backend->checkStatus('purchase-123', 'preprod');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/status/purchase-123')
                && $request->method() === 'GET';
        });

        $this->assertEquals('completed', $result['status']);
        $this->assertEquals('hash-xyz', $result['txHash']);
    }

    public function test_refund_returns_true_on_success(): void
    {
        Http::fake([
            'proxy.test/*' => Http::response(['success' => true]),
        ]);

        $result = $this->backend->refund('camp-123', 'addr_test1abc', 'preprod');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/refund')
                && $request->data()['campaignId'] === 'camp-123'
                && $request->data()['address'] === 'addr_test1abc';
        });

        $this->assertTrue($result);
    }

    public function test_refund_returns_false_on_failure(): void
    {
        Http::fake([
            'proxy.test/*' => Http::response(['success' => false], 500),
        ]);

        $this->assertFalse($this->backend->refund('camp-123', 'addr1', 'preprod'));
    }

    public function test_get_balance_sends_get_request(): void
    {
        Http::fake([
            'proxy.test/*' => Http::response(['lovelace' => 5000000]),
        ]);

        $result = $this->backend->getBalance('addr_test1abc', 'preprod');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/balance')
                && $request->method() === 'GET';
        });

        $this->assertEquals(5000000, $result['lovelace']);
    }
}

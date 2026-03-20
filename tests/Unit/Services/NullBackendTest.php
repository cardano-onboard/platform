<?php

namespace Tests\Unit\Services;

use App\Models\Campaign;
use App\Services\NullBackend;
use PHPUnit\Framework\TestCase;

class NullBackendTest extends TestCase
{
    private NullBackend $backend;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backend = new NullBackend();
    }

    public function test_create_bucket_returns_address_and_campaign_id(): void
    {
        $campaign = new Campaign();
        $campaign->name = 'test';
        $campaign->user_id = 'user-123';

        $result = $this->backend->createBucket($campaign, 'preprod');

        $this->assertArrayHasKey('address', $result);
        $this->assertArrayHasKey('campaignId', $result);
        $this->assertStringStartsWith('addr_null1', $result['address']);
        $this->assertStringStartsWith('null-', $result['campaignId']);
    }

    public function test_create_bucket_mainnet_also_uses_null_prefix(): void
    {
        $campaign = new Campaign();
        $campaign->name = 'test';
        $campaign->user_id = 'user-123';

        $result = $this->backend->createBucket($campaign, 'mainnet');

        $this->assertStringStartsWith('addr_null1', $result['address']);
    }

    public function test_submit_payment_returns_purchase_ids_for_each_recipient(): void
    {
        $recipients = [
            ['pooCode' => 'CODE1', 'address' => 'addr_test1abc'],
            ['pooCode' => 'CODE2', 'address' => 'addr_test1def'],
        ];

        $result = $this->backend->submitPayment('campaign-1', $recipients, 'preprod');

        $this->assertArrayHasKey('purchaseIds', $result);
        $this->assertArrayHasKey('CODE1', $result['purchaseIds']);
        $this->assertArrayHasKey('CODE2', $result['purchaseIds']);
        $this->assertStringStartsWith('null-purchase-', $result['purchaseIds']['CODE1']['addr_test1abc']);
        $this->assertStringStartsWith('null-purchase-', $result['purchaseIds']['CODE2']['addr_test1def']);
    }

    public function test_check_status_returns_completed_with_tx_hash(): void
    {
        $result = $this->backend->checkStatus('purchase-123', 'preprod');

        $this->assertEquals('completed', $result['status']);
        $this->assertArrayHasKey('txHash', $result);
        $this->assertStringStartsWith('null-tx-', $result['txHash']);
    }

    public function test_refund_returns_true(): void
    {
        $this->assertTrue($this->backend->refund('campaign-1', 'addr_test1abc', 'preprod'));
    }

    public function test_get_balance_returns_empty_array(): void
    {
        $this->assertEquals([], $this->backend->getBalance('addr_test1abc', 'preprod'));
    }
}

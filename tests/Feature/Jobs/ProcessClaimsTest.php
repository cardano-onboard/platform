<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\Reward;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessClaimsTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_order_sent_and_transaction_ids_recorded(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);
        $wallet = Wallet::factory()->for($campaign)->create(['key' => 'test-campaign-id', 'backend' => 'phyrhose']);
        $code = Code::factory()->for($campaign)->create(['lovelace' => 2000000]);
        Reward::factory()->for($code)->create();
        $claim = Claim::factory()->for($code)->create(['transaction_id' => null]);

        Http::fake([
            '*pooPurchaseOrder*' => Http::response([
                'status' => 'ok',
                'data' => [null, [
                    'items' => [
                        [
                            'pooCode'          => $code->code,
                            'recipientAddress' => $claim->address,
                            'purchaseId'       => 'purchase-123',
                        ],
                    ],
                ]],
            ]),
        ]);

        (new ProcessClaims($campaign->id))->handle();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'pooPurchaseOrder');
        });

        $claim->refresh();
        $this->assertEquals('purchase-123', $claim->transaction_id);
    }

    public function test_failed_phyrhose_response_does_not_update_claims(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);
        Wallet::factory()->for($campaign)->create(['key' => 'test-campaign-id', 'backend' => 'phyrhose']);
        $code = Code::factory()->for($campaign)->create();
        $claim = Claim::factory()->for($code)->create(['transaction_id' => null]);

        Http::fake([
            '*pooPurchaseOrder*' => Http::response([
                'status' => 'error',
                'message' => 'Something went wrong',
            ]),
        ]);

        (new ProcessClaims($campaign->id))->handle();

        $claim->refresh();
        $this->assertNull($claim->transaction_id);
    }
}

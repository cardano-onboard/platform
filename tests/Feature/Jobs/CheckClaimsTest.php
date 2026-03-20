<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CheckClaims;
use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckClaimsTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);
        Wallet::factory()->for($this->campaign)->create(['backend' => 'phyrhose']);
    }

    public function test_completed_claim_gets_tx_hash(): void
    {
        $code = Code::factory()->for($this->campaign)->create();
        $claim = Claim::factory()->for($code)->withTransaction()->create(['status' => 'pending']);

        Http::fake([
            '*purchaseStatus*' => Http::response([
                'status' => 'ok',
                'data' => [null, ['status' => 'completed', 'txId' => 'abc123hash']],
            ]),
        ]);

        (new CheckClaims($this->campaign->id))->handle();

        $claim->refresh();
        $this->assertEquals('abc123hash', $claim->transaction_hash);
        $this->assertEquals('completed', $claim->status);
    }

    public function test_timeout_increments_retry_count(): void
    {
        Queue::fake();

        $code = Code::factory()->for($this->campaign)->create();
        $claim = Claim::factory()->for($code)->withTransaction()->create([
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        Http::fake([
            '*purchaseStatus*' => Http::response([
                'status' => 'ok',
                'data' => [null, ['status' => 'timeout']],
            ]),
        ]);

        (new CheckClaims($this->campaign->id))->handle();

        $claim->refresh();
        $this->assertEquals(1, $claim->retry_count);
        $this->assertNull($claim->transaction_id);
        $this->assertEquals('pending', $claim->status);
    }

    public function test_max_retries_marks_claim_as_failed(): void
    {
        $code = Code::factory()->for($this->campaign)->create();
        $claim = Claim::factory()->for($code)->withTransaction()->create([
            'status' => 'pending',
            'retry_count' => CheckClaims::MAX_RETRIES - 1,
        ]);

        Http::fake([
            '*purchaseStatus*' => Http::response([
                'status' => 'ok',
                'data' => [null, ['status' => 'timeout']],
            ]),
        ]);

        (new CheckClaims($this->campaign->id))->handle();

        $claim->refresh();
        $this->assertEquals('failed', $claim->status);
        $this->assertEquals(CheckClaims::MAX_RETRIES, $claim->retry_count);
    }

    public function test_already_completed_claims_not_rechecked(): void
    {
        $code = Code::factory()->for($this->campaign)->create();
        Claim::factory()->for($code)->completed()->create();

        Http::fake();

        (new CheckClaims($this->campaign->id))->handle();

        Http::assertNothingSent();
    }
}

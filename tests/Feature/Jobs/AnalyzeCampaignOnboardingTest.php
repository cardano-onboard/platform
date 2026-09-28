<?php

namespace Tests\Feature\Jobs;

use App\Jobs\AnalyzeCampaignOnboarding;
use App\Models\Campaign;
use App\Models\CampaignAnalysis;
use App\Models\CampaignTask;
use App\Models\Claim;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClaimStatusChecker;
use App\Services\OnboardingAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A run confirms what it can before it measures anything.
 *
 * The analysis can only see a claim that carries a transaction hash, and a hash arrives
 * when the claim's status is checked rather than when the claim is made. Status checking
 * is deliberately not automatic, so a campaign that finished at a venue yesterday holds
 * claims the analysis is blind to, and a result computed over the rest reads exactly like
 * a result for the campaign.
 */
class AnalyzeCampaignOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM_TX = 'aa11';

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($user)->create(['network' => 'preprod']);
        Wallet::factory()->for($this->campaign)->create(['backend' => 'phyrhose']);
    }

    private function analyze(): void
    {
        (new AnalyzeCampaignOnboarding($this->campaign->id))->handle(
            // Unpaced: the sleep between Koios calls is politeness to a live service.
            new OnboardingAnalysisService(paced: false),
            new ClaimStatusChecker,
        );
    }

    /**
     * @param  array<string, string>  $statuses  purchase id to the status the backend reports
     */
    private function fakeBackendAndChain(array $statuses, int $blockHeight = 1000): void
    {
        Http::fake([
            '*purchaseStatus*' => function ($request) use ($statuses) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $status = $statuses[$query['purchaseId'] ?? ''] ?? 'processing';

                return Http::response([
                    'status' => 'ok',
                    'data' => [null, ['status' => $status, 'txId' => $status === 'completed' ? self::CLAIM_TX : null]],
                ]);
            },
            '*tx_info*' => Http::response([[
                'tx_hash' => self::CLAIM_TX,
                'block_height' => $blockHeight,
                'tx_timestamp' => now()->subDays(2)->timestamp,
            ]]),
            '*account_txs*' => Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => $blockHeight]]),
            '*account_info*' => Http::response([]),
        ]);
    }

    private function claim(string $purchaseId, string $stakeKey): Claim
    {
        $code = Code::factory()->for($this->campaign)->create();

        return Claim::factory()->for($code)->create([
            'transaction_id' => $purchaseId,
            'transaction_hash' => null,
            'status' => 'pending',
            'stake_key' => $stakeKey,
        ]);
    }

    public function test_a_claim_confirmed_by_the_run_is_measured_by_the_same_run(): void
    {
        $stake = 'stake1u'.str_repeat('a', 50);
        $claim = $this->claim('purchase-1', $stake);

        $this->fakeBackendAndChain(['purchase-1' => 'completed']);

        $this->analyze();

        // Confirmed on the way past, rather than left for somebody to notice.
        $this->assertSame(self::CLAIM_TX, $claim->fresh()->transaction_hash);

        $insight = $this->campaign->walletInsights()->where('stake_key', $stake)->first();
        $this->assertNotNull($insight, 'The claim confirmed by this run should have been classified by it.');
        $this->assertTrue($insight->is_new);

        $analysis = CampaignAnalysis::where('campaign_id', $this->campaign->id)->first();
        $this->assertSame(CampaignAnalysis::STATUS_COMPLETE, $analysis->status);
        $this->assertSame(1, $analysis->summary['claims_analyzable']);
        $this->assertEquals(100.0, $analysis->summary['coverage_pct']);
    }

    /**
     * A claim the backend still cannot answer for is simply absent from the numbers. What
     * stops that being the original failure again is the coverage figure recorded beside
     * them, which says how many claims the result did not describe.
     */
    public function test_a_claim_that_will_not_confirm_is_absent_and_counted(): void
    {
        $confirmed = 'stake1u'.str_repeat('b', 50);
        $this->claim('purchase-1', $confirmed);
        $this->claim('purchase-2', 'stake1u'.str_repeat('c', 50));

        $this->fakeBackendAndChain([
            'purchase-1' => 'completed',
            'purchase-2' => 'processing',
        ]);

        $this->analyze();

        $this->assertSame([$confirmed], $this->campaign->walletInsights()->pluck('stake_key')->all());

        $summary = CampaignAnalysis::where('campaign_id', $this->campaign->id)->value('summary');
        $summary = is_string($summary) ? json_decode($summary, true) : $summary;

        $this->assertSame(2, $summary['claims_total']);
        $this->assertSame(1, $summary['claims_confirmed']);
        $this->assertSame(1, $summary['claims_unconfirmed']);
        $this->assertEquals(50.0, $summary['coverage_pct']);
    }

    /**
     * A failed claim will never gain a transaction hash. Asking about it every time an
     * analysis runs spends a backend call to be told the same thing again.
     */
    public function test_a_failed_claim_is_not_asked_about(): void
    {
        $code = Code::factory()->for($this->campaign)->create();
        Claim::factory()->for($code)->withTransaction()->failed()->create();
        Claim::factory()->for($code)->completed()->create([
            'stake_key' => 'stake1u'.str_repeat('d', 50),
            'transaction_hash' => self::CLAIM_TX,
        ]);

        $this->fakeBackendAndChain([]);

        $this->analyze();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'purchaseStatus'));
        $this->assertSame(1, $this->campaign->walletInsights()->count());
    }

    /**
     * Nothing outstanding means nothing to ask, so a campaign whose claims are all
     * confirmed goes straight to the chain.
     */
    public function test_a_campaign_with_nothing_outstanding_calls_no_backend(): void
    {
        $code = Code::factory()->for($this->campaign)->create();
        Claim::factory()->for($code)->completed()->create([
            'stake_key' => 'stake1u'.str_repeat('e', 50),
            'transaction_hash' => self::CLAIM_TX,
        ]);

        $this->fakeBackendAndChain([]);

        $this->analyze();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'purchaseStatus'));
        $this->assertSame(1, $this->campaign->walletInsights()->count());
    }

    /**
     * Two passes over the same claims would ask the backend about each of them twice, and
     * a timed-out claim would have its retry count advanced twice for one failure, which
     * brings it to the permanent failure ceiling early.
     */
    public function test_a_status_pass_does_not_run_while_another_is_in_flight(): void
    {
        $this->claim('purchase-1', 'stake1u'.str_repeat('f', 50));

        $this->fakeBackendAndChain(['purchase-1' => 'completed']);

        $held = Cache::lock('claim-status-check:'.$this->campaign->id, 300);
        $this->assertTrue($held->get());

        try {
            $stats = (new ClaimStatusChecker)->check($this->campaign->fresh());
        } finally {
            $held->release();
        }

        $this->assertTrue($stats['skipped']);
        $this->assertSame(0, $stats['checked']);
        Http::assertNothingSent();
    }

    /**
     * The confirmation pass is a phase like the six the service reports, and it is the one
     * the panel describes in its own words rather than simply printing, because during it
     * nothing has been read off the chain yet. The panel matches the label, so the label is
     * held here.
     */
    public function test_the_confirmation_pass_announces_itself_on_the_task_row(): void
    {
        $this->claim('purchase-1', 'stake1u'.str_repeat('h', 50));

        $task = CampaignTask::claim(
            $this->campaign,
            AnalyzeCampaignOnboarding::TASK_TYPE,
            AnalyzeCampaignOnboarding::dedupeKey(),
        );

        $seen = [];

        Http::fake([
            '*purchaseStatus*' => function () use ($task, &$seen) {
                $row = CampaignTask::find($task->id);
                $seen[] = [$row->stage, $row->progress_done, $row->progress_total];

                return Http::response([
                    'status' => 'ok',
                    'data' => [null, ['status' => 'completed', 'txId' => self::CLAIM_TX]],
                ]);
            },
            '*tx_info*' => Http::response([[
                'tx_hash' => self::CLAIM_TX,
                'block_height' => 1000,
                'tx_timestamp' => now()->subDays(2)->timestamp,
            ]]),
            '*account_txs*' => Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]]),
            '*account_info*' => Http::response([]),
        ]);

        (new AnalyzeCampaignOnboarding($this->campaign->id, $task->id))->handle(
            new OnboardingAnalysisService(paced: false),
            new ClaimStatusChecker,
        );

        // The phase and its denominator were on the row while the backend was being asked,
        // which is the only reason the page can show them.
        $this->assertSame(
            [[AnalyzeCampaignOnboarding::STAGE_CONFIRMING, 0, 1]],
            $seen,
        );

        // And the run went on to measure, leaving no phase behind it.
        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->status);
        $this->assertNull($task->stage);
    }

    public function test_a_status_pass_runs_once_the_lock_is_free(): void
    {
        $claim = $this->claim('purchase-1', 'stake1u'.str_repeat('g', 50));

        $this->fakeBackendAndChain(['purchase-1' => 'completed']);

        $stats = (new ClaimStatusChecker)->check($this->campaign->fresh());

        $this->assertFalse($stats['skipped']);
        $this->assertSame(1, $stats['completed']);
        $this->assertSame(self::CLAIM_TX, $claim->fresh()->transaction_hash);
    }
}

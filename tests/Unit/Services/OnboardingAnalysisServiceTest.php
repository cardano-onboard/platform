<?php

namespace Tests\Unit\Services;

use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Services\OnboardingAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnboardingAnalysisServiceTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM_TX = 'aa11';

    private const LATER_TX = 'bb22';

    private function campaignWithClaim(string $stakeKey, string $txHash = self::CLAIM_TX): Campaign
    {
        $campaign = Campaign::factory()->mainnet()->create();
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        Claim::factory()->create([
            'code_id' => $code->id,
            'stake_key' => $stakeKey,
            'address' => 'addr1q'.str_repeat('x', 50),
            'transaction_id' => 'tx-id',
            'transaction_hash' => $txHash,
            'status' => 'completed',
        ]);

        return $campaign;
    }

    /**
     * Koios responses are matched on endpoint and, for tx_info, on which hashes were
     * asked for — the analysis calls tx_info twice for different reasons (claim
     * transactions, then post-claim transactions) and the two must not be conflated.
     */
    private function fakeKoios(array $claimTx, array $accountTxs, array $postTx = [], array $accountInfo = []): void
    {
        Http::fake([
            '*tx_info*' => function ($request) use ($claimTx, $postTx) {
                $hashes = $request->data()['_tx_hashes'] ?? [];

                return Http::response(in_array(self::CLAIM_TX, $hashes, true) ? $claimTx : $postTx);
            },
            '*account_txs*' => Http::response($accountTxs),
            '*account_info*' => Http::response($accountInfo),
        ]);
    }

    private function analyze(Campaign $campaign)
    {
        // Unpaced: the sleep between Koios calls is politeness to a live service and only
        // slows the suite down.
        return (new OnboardingAnalysisService(paced: false))->analyze($campaign);
    }

    public function test_a_wallet_with_no_prior_history_is_classified_as_new(): void
    {
        $stake = 'stake1u'.str_repeat('a', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(10)->timestamp]],
            accountTxs: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]],
        );

        $this->analyze($campaign);

        $insight = $campaign->walletInsights()->first();
        $this->assertTrue($insight->is_new);
        $this->assertSame(0, $insight->prior_tx_count);
    }

    public function test_a_wallet_that_transacted_before_the_claim_is_established(): void
    {
        $stake = 'stake1u'.str_repeat('b', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(10)->timestamp]],
            accountTxs: [
                ['tx_hash' => 'older', 'block_height' => 900],
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000],
            ],
        );

        $this->analyze($campaign);

        $insight = $campaign->walletInsights()->first();
        $this->assertFalse($insight->is_new);
        $this->assertSame(1, $insight->prior_tx_count);
    }

    /**
     * The distinction the whole metric rests on: a wallet that RECEIVES another payout
     * has not done anything. Only spending counts, or an operator could manufacture
     * activation by sending more tokens to their own claimants.
     */
    public function test_receiving_a_later_payout_is_not_activation(): void
    {
        $stake = 'stake1u'.str_repeat('c', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(10)->timestamp]],
            accountTxs: [
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000],
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100],
            ],
            postTx: [[
                'tx_hash' => self::LATER_TX,
                'block_height' => 1100,
                // Someone else's wallet paid it; this claimant is only an output.
                'inputs' => [['stake_addr' => 'stake1u'.str_repeat('z', 50)]],
                'outputs' => [['payment_addr' => ['bech32' => 'addr1q'.str_repeat('x', 50)]]],
            ]],
        );

        $this->analyze($campaign);

        $insight = $campaign->walletInsights()->first();
        $this->assertFalse($insight->activated);
        $this->assertSame(0, $insight->self_initiated_count);
        $this->assertSame(1, $insight->post_claim_tx_count);
    }

    public function test_spending_after_the_claim_counts_as_activation(): void
    {
        $stake = 'stake1u'.str_repeat('d', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(10)->timestamp]],
            accountTxs: [
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000],
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100],
            ],
            postTx: [[
                'tx_hash' => self::LATER_TX,
                'block_height' => 1100,
                'inputs' => [['stake_addr' => $stake]],
                'outputs' => [['payment_addr' => ['bech32' => 'addr1q'.str_repeat('y', 50)]]],
            ]],
        );

        $this->analyze($campaign);

        $insight = $campaign->walletInsights()->first();
        $this->assertTrue($insight->activated);
        $this->assertSame(1, $insight->self_initiated_count);
    }

    public function test_paying_a_script_address_is_recorded_as_a_contract_interaction(): void
    {
        $stake = 'stake1u'.str_repeat('e', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(5)->timestamp]],
            accountTxs: [
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000],
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100],
            ],
            postTx: [[
                'tx_hash' => self::LATER_TX,
                'block_height' => 1100,
                'inputs' => [['stake_addr' => $stake]],
                'outputs' => [['payment_addr' => ['bech32' => 'addr1w'.str_repeat('s', 50)]]],
            ]],
        );

        $this->analyze($campaign);

        $this->assertSame(1, $campaign->walletInsights()->first()->script_interactions);
    }

    public function test_delegation_status_is_recorded(): void
    {
        $stake = 'stake1u'.str_repeat('f', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(3)->timestamp]],
            accountTxs: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]],
            accountInfo: [['stake_address' => $stake, 'status' => 'registered', 'delegated_pool' => 'pool1abc']],
        );

        $this->analyze($campaign);

        $insight = $campaign->walletInsights()->first();
        $this->assertTrue($insight->delegated);
        $this->assertSame('pool1abc', $insight->pool_id);
    }

    /**
     * Koios keys wallet history on the stake key, so a claimant who presented an
     * enterprise address has nothing to look up. Including them would drag every
     * percentage down with wallets that were never measurable.
     */
    public function test_claimants_without_a_stake_key_are_excluded(): void
    {
        $campaign = $this->campaignWithClaim('addr1q'.str_repeat('n', 50));

        $this->fakeKoios(claimTx: [], accountTxs: []);

        $analysis = $this->analyze($campaign);

        $this->assertSame(0, $campaign->walletInsights()->count());
        $this->assertSame(0, $analysis->summary['genuine_wallets']);
    }

    public function test_unconfirmed_claims_are_not_analyzed(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);
        Claim::factory()->create([
            'code_id' => $code->id,
            'stake_key' => 'stake1u'.str_repeat('g', 50),
            'transaction_id' => 'pending',
            'transaction_hash' => null,
        ]);

        $this->fakeKoios(claimTx: [], accountTxs: []);

        $this->analyze($campaign);

        $this->assertSame(0, $campaign->walletInsights()->count());
    }

    public function test_operator_flag_survives_a_re_analysis(): void
    {
        $stake = 'stake1u'.str_repeat('h', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(2)->timestamp]],
            accountTxs: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]],
        );

        $this->analyze($campaign);
        $campaign->walletInsights()->update(['is_operator' => true]);
        $analysis = $this->analyze($campaign);

        $this->assertTrue($campaign->walletInsights()->first()->is_operator);
        $this->assertSame(1, $analysis->summary['operator_wallets']);
        $this->assertSame(0, $analysis->summary['genuine_wallets']);
    }

    public function test_summary_percentages_are_of_the_genuine_population(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        foreach (['i', 'j'] as $n => $letter) {
            Claim::factory()->create([
                'code_id' => $code->id,
                'stake_key' => 'stake1u'.str_repeat($letter, 50),
                'transaction_id' => 'tx-'.$n,
                'transaction_hash' => self::CLAIM_TX,
                'status' => 'completed',
            ]);
        }

        // One wallet has prior history, the other does not.
        Http::fake([
            '*tx_info*' => Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(7)->timestamp]]),
            '*account_txs*' => function ($request) {
                $stake = $request->data()['_stake_address'] ?? ($request->toPsrRequest()->getUri()->getQuery());

                return Http::response(str_contains((string) $stake, str_repeat('i', 20))
                    ? [['tx_hash' => 'older', 'block_height' => 900], ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]]
                    : [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]]);
            },
            '*account_info*' => Http::response([]),
        ]);

        $summary = $this->analyze($campaign)->summary;

        $this->assertSame(2, $summary['genuine_wallets']);
        $this->assertSame(1, $summary['new_wallets']);
        // assertEquals, not assertSame: the summary round-trips through JSON, where a
        // whole-number percentage comes back as an int rather than the float it was.
        $this->assertEquals(50, $summary['new_pct']);
        $this->assertNotNull($summary['observation_days_avg']);
    }
}

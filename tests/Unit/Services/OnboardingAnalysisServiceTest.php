<?php

namespace Tests\Unit\Services;

use App\Models\Campaign;
use App\Models\CampaignAnalysis;
use App\Models\CampaignWalletActivity;
use App\Models\CampaignWalletInsight;
use App\Models\Claim;
use App\Models\Code;
use App\Services\OnboardingAnalysisService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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

                if (in_array(self::CLAIM_TX, $hashes, true)) {
                    return Http::response($claimTx);
                }

                // Certificates default to off at Koios, so a caller that does not ask for
                // them gets `certificates: []` however many the transaction carried. The
                // fake behaves the same way: a post-claim read that forgot the flag must
                // not be handed the field anyway, or the test would pass against code that
                // cannot work against the real service.
                $withCerts = ($request->data()['_certs'] ?? false) === true;

                return Http::response(array_map(static function (array $tx) use ($withCerts) {
                    $tx['certificates'] = $withCerts ? ($tx['certificates'] ?? []) : [];

                    return $tx;
                }, $postTx));
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
        $this->assertSame(0, $insight->activity_count);
        $this->assertSame(0, $insight->self_initiated_count);
        $this->assertSame(1, $insight->post_claim_tx_count);
        $this->assertNull($insight->first_activity_seconds);
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
        $this->assertSame(1, $insight->activity_count);
        $this->assertSame(1, $insight->self_initiated_count);
        $this->assertSame(0, $insight->delegation_events);
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

    /**
     * Scoping a campaign to the event it was run for.
     *
     * The example this comes from: cards handed out at a conference, spares given away
     * afterwards, then a social post weeks later. All three are real claims on one
     * campaign and they answer different questions, so measuring them together makes the
     * event result unobtainable.
     */
    public function test_a_window_summarises_only_the_rows_inside_it(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();

        $this->insight($campaign, 'event-one', '2026-09-01 10:00:00', ['activity_count' => 1]);
        $this->insight($campaign, 'event-two', '2026-09-01 18:00:00', ['activity_count' => 1]);
        $this->insight($campaign, 'post-one', '2026-09-20 12:00:00', ['activity_count' => 0]);
        $this->insight($campaign, 'post-two', '2026-09-21 12:00:00', ['activity_count' => 0]);

        $service = new OnboardingAnalysisService(paced: false);

        $event = $service->summarizeWindow(
            $campaign,
            Carbon::parse('2026-09-01')->startOfDay(),
            Carbon::parse('2026-09-01')->endOfDay(),
        );
        $after = $service->summarizeWindow($campaign, Carbon::parse('2026-09-20')->startOfDay(), null);

        $this->assertSame(2, $event['genuine_wallets']);
        $this->assertEquals(100.0, $event['new_active_pct']);

        $this->assertSame(2, $after['genuine_wallets']);
        $this->assertEquals(0.0, $after['new_active_pct']);

        // And the same rows, unscoped, average the two into a figure describing neither.
        $whole = $service->summarizeWindow($campaign, null, null);
        $this->assertSame(4, $whole['genuine_wallets']);
        $this->assertEquals(50.0, $whole['new_active_pct']);
    }

    /**
     * A window is a question asked of stored rows. If it wrote anything, the campaign's
     * own result would become whatever the last person to look at it asked for.
     */
    public function test_a_window_writes_nothing(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();
        $insight = $this->insight($campaign, 'somebody', '2026-09-01 10:00:00');
        $before = $insight->fresh()->toArray();

        (new OnboardingAnalysisService(paced: false))->summarizeWindow(
            $campaign,
            Carbon::parse('2026-09-01')->startOfDay(),
            Carbon::parse('2026-09-01')->endOfDay(),
        );

        $this->assertEquals($before, $insight->fresh()->toArray());
        $this->assertSame(0, CampaignAnalysis::where('campaign_id', $campaign->id)->count());
        $this->assertSame(1, $campaign->walletInsights()->count());
    }

    /**
     * A row with no claim timestamp cannot be placed on either side of a boundary, so it
     * belongs to no window. Dropping it silently from the unscoped summary as well would
     * be the worse answer, so that one still counts it.
     */
    public function test_a_row_with_no_claim_date_is_in_no_window(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();
        $this->insight($campaign, 'dated', '2026-09-01 10:00:00');
        $this->insight($campaign, 'undated', null);

        $service = new OnboardingAnalysisService(paced: false);

        $scoped = $service->summarizeWindow($campaign, Carbon::parse('2026-09-01')->startOfDay(), null);
        $whole = $service->summarize($campaign, $campaign->walletInsights()->get());

        $this->assertSame(1, $scoped['claimants_total']);
        $this->assertSame(2, $whole['claimants_total']);
    }

    /**
     * Coverage is counted over the same window as the wallets, or a range covering one
     * day of an event would report itself against every claim the campaign ever took.
     */
    public function test_coverage_is_counted_over_the_same_window(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        Claim::factory()->completed()->create([
            'code_id' => $code->id,
            'stake_key' => 'stake1u'.str_repeat('a', 50),
            'created_at' => '2026-09-01 10:00:00',
        ]);
        Claim::factory()->withTransaction()->create([
            'code_id' => $code->id,
            'created_at' => '2026-09-01 11:00:00',
        ]);
        Claim::factory()->completed()->create([
            'code_id' => $code->id,
            'stake_key' => 'stake1u'.str_repeat('b', 50),
            'created_at' => '2026-09-20 10:00:00',
        ]);

        $summary = (new OnboardingAnalysisService(paced: false))->summarizeWindow(
            $campaign,
            Carbon::parse('2026-09-01')->startOfDay(),
            Carbon::parse('2026-09-01')->endOfDay(),
        );

        $this->assertSame(2, $summary['claims_total']);
        $this->assertSame(1, $summary['claims_confirmed']);
        $this->assertSame(1, $summary['claims_unconfirmed']);
        $this->assertEquals(50.0, $summary['coverage_pct']);
        $this->assertSame('2026-09-01T00:00:00+00:00', $summary['window_from']);
    }

    /**
     * The defect this whole change exists for.
     *
     * Registering a stake key and delegating is one transaction, and the wallet is an
     * input of it, because the wallet pays the two ada deposit and the fee. Counting
     * inputs alone therefore reports a wallet whose only act was delegating as one that
     * transacted for itself. Rare Evo showed the shape of it: three new wallets counted as
     * activated, three delegated, and they were the same three.
     *
     * The certificate shapes here are what Koios really returns for such a transaction:
     * `stake_registration` then `pool_delegation`, each carrying the subject's stake
     * address in `info`, verified against mainnet transaction
     * f1e67f5429d0a01ff3e3916713ca2fc8b4a1240b6c20d2cfadc37144f70510e7.
     */
    public function test_a_wallet_that_only_delegated_has_not_transacted_for_itself(): void
    {
        $stake = 'stake1u'.str_repeat('k', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(40)->timestamp]],
            accountTxs: [
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'block_time' => now()->subDays(40)->timestamp],
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100, 'block_time' => now()->subDays(35)->timestamp],
            ],
            postTx: [[
                'tx_hash' => self::LATER_TX,
                'block_height' => 1100,
                // The wallet pays the deposit and the fee, so it IS an input.
                'inputs' => [['stake_addr' => $stake]],
                // And the change comes straight back to it.
                'outputs' => [[
                    'payment_addr' => ['bech32' => 'addr1q'.str_repeat('x', 50)],
                    'stake_addr' => $stake,
                ]],
                'certificates' => [
                    [
                        'index' => 0,
                        'type' => 'stake_registration',
                        'info' => ['stake_address' => $stake, 'deposit' => '2000000'],
                    ],
                    [
                        'index' => 1,
                        'type' => 'pool_delegation',
                        'info' => [
                            'stake_address' => $stake,
                            'pool_id_bech32' => 'pool1'.str_repeat('q', 50),
                        ],
                    ],
                ],
            ]],
            accountInfo: [['stake_address' => $stake, 'delegated_pool' => 'pool1'.str_repeat('q', 50)]],
        );

        $analysis = $this->analyze($campaign);
        $insight = $campaign->walletInsights()->first();

        // It initiated a transaction, and it did not transact for itself.
        $this->assertSame(1, $insight->self_initiated_count);
        $this->assertSame(0, $insight->activity_count);
        $this->assertNull($insight->first_activity_seconds);

        // It delegated, and the run knows when.
        $this->assertSame(1, $insight->delegation_events);
        $this->assertSame(5 * 86400, $insight->first_delegation_seconds);
        $this->assertTrue($insight->delegated);

        $this->assertSame(0, $analysis->summary['new_active']);
        $this->assertSame(1, $analysis->summary['delegation_only']);
    }

    /**
     * Delegating and paying somebody in one transaction is both things at once.
     *
     * The exclusion is for a transaction that carried nothing but the wallet's own
     * certificate. A wallet that delegates and sends ada to a friend in the same
     * transaction has done something with its tokens, and reporting only the delegation
     * would undercount it.
     */
    public function test_delegating_while_paying_someone_still_counts_as_transacting(): void
    {
        $stake = 'stake1u'.str_repeat('l', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(20)->timestamp]],
            accountTxs: [
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'block_time' => now()->subDays(20)->timestamp],
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100, 'block_time' => now()->subDays(18)->timestamp],
            ],
            postTx: [[
                'tx_hash' => self::LATER_TX,
                'block_height' => 1100,
                'inputs' => [['stake_addr' => $stake]],
                'outputs' => [
                    ['payment_addr' => ['bech32' => 'addr1q'.str_repeat('x', 50)], 'stake_addr' => $stake],
                    ['payment_addr' => ['bech32' => 'addr1q'.str_repeat('y', 50)], 'stake_addr' => 'stake1u'.str_repeat('m', 50)],
                ],
                'certificates' => [[
                    'index' => 0,
                    'type' => 'pool_delegation',
                    'info' => ['stake_address' => $stake, 'pool_id_bech32' => 'pool1'.str_repeat('q', 50)],
                ]],
            ]],
        );

        $this->analyze($campaign);
        $insight = $campaign->walletInsights()->first();

        $this->assertSame(1, $insight->activity_count);
        $this->assertSame(1, $insight->delegation_events);
    }

    /**
     * A certificate belongs to whoever it names, not to whoever sent the transaction.
     *
     * A delegation certificate for somebody else's stake key can ride in a transaction
     * this wallet paid for, and that wallet has spent its own funds to do it. Attributing
     * the certificate by sender rather than by subject would read that as the claimant
     * merely delegating and hide real activity.
     */
    public function test_a_certificate_for_another_wallet_does_not_excuse_this_one(): void
    {
        $stake = 'stake1u'.str_repeat('n', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(20)->timestamp]],
            accountTxs: [
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'block_time' => now()->subDays(20)->timestamp],
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100, 'block_time' => now()->subDays(19)->timestamp],
            ],
            postTx: [[
                'tx_hash' => self::LATER_TX,
                'block_height' => 1100,
                'inputs' => [['stake_addr' => $stake]],
                'outputs' => [['payment_addr' => ['bech32' => 'addr1q'.str_repeat('x', 50)], 'stake_addr' => $stake]],
                'certificates' => [[
                    'index' => 0,
                    'type' => 'pool_delegation',
                    'info' => ['stake_address' => 'stake1u'.str_repeat('o', 50)],
                ]],
            ]],
        );

        $this->analyze($campaign);
        $insight = $campaign->walletInsights()->first();

        $this->assertSame(1, $insight->activity_count);
        $this->assertSame(0, $insight->delegation_events);
    }

    /**
     * The post-claim read has to ask for certificates by name.
     *
     * Every optional section of Koios tx_info defaults to off, so a call that does not pass
     * `_certs` gets an empty `certificates` array however many the transaction carried, and
     * every delegation silently becomes activity again. The flags are asserted on the
     * request rather than inferred from the result, because the result would look the same
     * as a transaction that genuinely had no certificates.
     */
    public function test_the_post_claim_read_asks_koios_for_certificates(): void
    {
        $stake = 'stake1u'.str_repeat('p', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(10)->timestamp]],
            accountTxs: [
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'block_time' => now()->subDays(10)->timestamp],
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100, 'block_time' => now()->subDays(9)->timestamp],
            ],
            postTx: [[
                'tx_hash' => self::LATER_TX,
                'block_height' => 1100,
                'inputs' => [['stake_addr' => $stake]],
                'outputs' => [['payment_addr' => ['bech32' => 'addr1q'.str_repeat('y', 50)]]],
            ]],
        );

        $this->analyze($campaign);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'tx_info')) {
                return false;
            }

            $body = $request->data();

            // Inputs too: without them nobody initiated anything.
            return in_array(self::LATER_TX, $body['_tx_hashes'] ?? [], true)
                && ($body['_certs'] ?? false) === true
                && ($body['_inputs'] ?? false) === true;
        });
    }

    /**
     * Thirty, sixty and ninety days, with the boundary inclusive.
     *
     * A transaction exactly thirty days after the claim is inside the thirty-day window.
     * One second later it is not. The offsets are stored in seconds for exactly this
     * reason: a day count would round both to thirty and the boundary would stop existing.
     */
    public function test_the_window_boundaries_are_inclusive_to_the_second(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();
        $claimedAt = '2026-01-01 00:00:00';
        // Long enough ago that every wallet is observable for all three windows.
        $observedAt = Carbon::parse($claimedAt)->addDays(200);

        $exactly = ['claimed_at' => $claimedAt, 'windows_observed_at' => $observedAt];

        $this->insight($campaign, 'on-30', $claimedAt, $exactly + ['first_activity_seconds' => 30 * 86400, 'activity_count' => 1]);
        $this->insight($campaign, 'past-30', $claimedAt, $exactly + ['first_activity_seconds' => 30 * 86400 + 1, 'activity_count' => 1]);
        $this->insight($campaign, 'on-60', $claimedAt, $exactly + ['first_activity_seconds' => 60 * 86400, 'activity_count' => 1]);
        $this->insight($campaign, 'on-90', $claimedAt, $exactly + ['first_activity_seconds' => 90 * 86400, 'activity_count' => 1]);
        $this->insight($campaign, 'past-90', $claimedAt, $exactly + ['first_activity_seconds' => 90 * 86400 + 1, 'activity_count' => 1]);

        $windows = collect(
            (new OnboardingAnalysisService(paced: false))->summarizeWindow($campaign)['windows']
        )->keyBy('days');

        $this->assertSame(5, $windows[30]['new_observable']);
        // Only the wallet landing exactly on the boundary.
        $this->assertSame(1, $windows[30]['new_active']);
        // Plus the one a second past thirty days, and the one exactly on sixty.
        $this->assertSame(3, $windows[60]['new_active']);
        // Plus the one exactly on ninety. The one a second past it stays out.
        $this->assertSame(4, $windows[90]['new_active']);
    }

    /**
     * A wallet claimed last week has no ninety-day answer, and that is not a zero.
     *
     * Reporting it as one would say the campaign failed at something it has not been given
     * time to do. The observability test is against the moment the run read the chain, not
     * against now, so the same stored rows give the same answer next month.
     */
    public function test_a_wallet_too_recent_for_a_window_is_not_counted_as_a_failure(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();
        $claimedAt = '2026-01-01 00:00:00';

        // Watched for forty-five days: long enough for the thirty-day window, not the
        // sixty or ninety day ones.
        $this->insight($campaign, 'recent', $claimedAt, [
            'windows_observed_at' => Carbon::parse($claimedAt)->addDays(45),
            'first_activity_seconds' => null,
        ]);

        $windows = collect(
            (new OnboardingAnalysisService(paced: false))->summarizeWindow($campaign)['windows']
        )->keyBy('days');

        // Thirty days: observed, and the answer is zero. That is a result and is shown.
        $this->assertSame(1, $windows[30]['new_observable']);
        $this->assertSame(0, $windows[30]['new_not_yet']);
        $this->assertSame(0, $windows[30]['new_active']);

        // Ninety days: not observed. The wallet is out of the denominator entirely rather
        // than counted as one that did nothing.
        $this->assertSame(0, $windows[90]['new_observable']);
        $this->assertSame(1, $windows[90]['new_not_yet']);
        $this->assertSame(0, $windows[90]['new_active']);
        $this->assertEquals(0.0, $windows[90]['new_active_pct']);
    }

    /**
     * Unknown and zero are different answers and must never share a representation.
     *
     * A campaign nobody has re-analysed since delegation could be excluded has rows that
     * cannot say whether anybody transacted. A campaign that has been re-analysed and found
     * nothing has rows that say so. The first is unknown, the second is zero, and a panel
     * that printed 0% for both would be lying about one of them.
     */
    public function test_a_campaign_never_re_analysed_is_unknown_not_zero(): void
    {
        $unread = Campaign::factory()->mainnet()->create();
        $read = Campaign::factory()->mainnet()->create();

        // The shape a row written before this measurement existed has: no observation
        // stamp, and therefore no answer.
        $this->insight($unread, 'never-read', '2026-01-01 00:00:00', [
            'windows_observed_at' => null,
            'observed_seconds' => null,
        ]);

        $this->insight($read, 'read-and-idle', '2026-01-01 00:00:00', [
            'windows_observed_at' => Carbon::parse('2026-06-01 00:00:00'),
            'observed_seconds' => Carbon::parse('2026-06-01 00:00:00')->getTimestamp()
                - Carbon::parse('2026-01-01 00:00:00')->getTimestamp(),
            'first_activity_seconds' => null,
        ]);

        $service = new OnboardingAnalysisService(paced: false);

        $unknown = $service->summarizeWindow($unread);
        $zero = $service->summarizeWindow($read);

        // Unknown: nothing was measured, and the summary says so rather than reporting a
        // wallet that did nothing.
        $this->assertFalse($unknown['windows_available']);
        $this->assertSame(0, $unknown['windows_observed']);
        $this->assertSame(1, $unknown['windows_unknown']);
        // The windows are still there, in order, each saying nothing is observable. A
        // missing key would leave the panel guessing; a zero row states the position.
        $this->assertSame([30, 60, 90], array_column($unknown['windows'], 'days'));
        $this->assertSame(0, $unknown['windows'][0]['new_observable']);
        $this->assertNull($unknown['windows_observed_at']);

        // Zero: it was measured, the wallet did nothing, and the row is shown.
        $this->assertTrue($zero['windows_available']);
        $this->assertSame(1, $zero['windows_observed']);
        $this->assertSame(0, $zero['windows_unknown']);
        $this->assertSame(1, collect($zero['windows'])->keyBy('days')[90]['new_observable']);
        $this->assertSame(0, collect($zero['windows'])->keyBy('days')[90]['new_active']);
        $this->assertNotNull($zero['windows_observed_at']);
    }

    /**
     * Two runs over the same campaign leave one row per transaction, not two.
     *
     * The per-transaction rows are what every window figure is counted from, so a
     * re-analysis that appended instead of replacing would double each of them. Two runs
     * see the same chain here, which is the case that would go unnoticed: the totals would
     * still look plausible.
     */
    public function test_a_re_analysis_replaces_the_recorded_transactions(): void
    {
        $stake = 'stake1u'.str_repeat('r', 50);
        $campaign = $this->campaignWithClaim($stake);

        $fake = fn () => $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(40)->timestamp]],
            accountTxs: [
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'block_time' => now()->subDays(40)->timestamp],
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100, 'block_time' => now()->subDays(30)->timestamp],
            ],
            postTx: [[
                'tx_hash' => self::LATER_TX,
                'block_height' => 1100,
                'inputs' => [['stake_addr' => $stake]],
                'outputs' => [['payment_addr' => ['bech32' => 'addr1q'.str_repeat('y', 50)], 'stake_addr' => 'stake1u'.str_repeat('s', 50)]],
            ]],
        );

        $fake();
        $this->analyze($campaign);

        $this->assertSame(1, CampaignWalletActivity::where('campaign_id', $campaign->id)->count());

        $fake();
        $this->analyze($campaign);

        $this->assertSame(1, CampaignWalletActivity::where('campaign_id', $campaign->id)->count());
        $this->assertSame(1, $campaign->walletInsights()->first()->activity_count);
    }

    /**
     * What a run saw, kept as it saw it.
     *
     * The certificate types are recorded per transaction rather than collapsed into a flag,
     * so a type this code does not yet classify is still on the record. Sorted before
     * comparing: the assertion is about which types were seen, and MySQL and SQLite do not
     * have to agree on anything else for that to be true.
     */
    public function test_the_certificates_a_transaction_carried_are_recorded(): void
    {
        // The stored time is compared against a time built the same way at the end of the
        // test. Without a frozen clock the two differ whenever the run crosses a second.
        $this->freezeTime();

        $stake = 'stake1u'.str_repeat('t', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(10)->timestamp]],
            accountTxs: [
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'block_time' => now()->subDays(10)->timestamp],
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100, 'block_time' => now()->subDays(8)->timestamp],
            ],
            postTx: [[
                'tx_hash' => self::LATER_TX,
                'block_height' => 1100,
                'inputs' => [['stake_addr' => $stake]],
                'outputs' => [['payment_addr' => ['bech32' => 'addr1q'.str_repeat('x', 50)], 'stake_addr' => $stake]],
                'certificates' => [
                    ['index' => 0, 'type' => 'stake_registration', 'info' => ['stake_address' => $stake]],
                    ['index' => 1, 'type' => 'vote_delegation', 'info' => ['stake_address' => $stake]],
                ],
            ]],
        );

        $this->analyze($campaign);

        $row = CampaignWalletActivity::where('campaign_id', $campaign->id)->firstOrFail();

        $types = $row->certificate_types;
        sort($types);
        $this->assertSame(['stake_registration', 'vote_delegation'], $types);

        $this->assertTrue($row->self_initiated);
        $this->assertFalse($row->is_activity);
        // Registering a stake key and delegating a vote is not delegating to a pool.
        $this->assertFalse($row->is_delegation);
        $this->assertSame(2 * 86400, $row->seconds_after_claim);
        $this->assertSame(
            now()->subDays(8)->startOfSecond()->toIso8601String(),
            $row->occurred_at->startOfSecond()->toIso8601String()
        );
    }

    /**
     * A transaction the provider gave no time for is in no window.
     *
     * It happened, so it is recorded and counted in the totals, but it cannot be placed
     * against a boundary. Giving it an offset of zero would put it inside every window and
     * report activity on the day of the claim that nobody observed.
     *
     * The wallet is held out of the window rather than left in its denominator. Counted as
     * observable it would be a wallet the headline reports as having transacted and every
     * window reports as not having transacted, which is one wallet described two ways on
     * one screen.
     */
    public function test_a_transaction_with_no_time_falls_into_no_window(): void
    {
        $stake = 'stake1u'.str_repeat('u', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(100)->timestamp]],
            accountTxs: [
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'block_time' => now()->subDays(100)->timestamp],
                // No block_time, which is what a provider page that omitted it looks like.
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100],
            ],
            postTx: [[
                'tx_hash' => self::LATER_TX,
                'block_height' => 1100,
                'inputs' => [['stake_addr' => $stake]],
                'outputs' => [['payment_addr' => ['bech32' => 'addr1q'.str_repeat('y', 50)], 'stake_addr' => 'stake1u'.str_repeat('v', 50)]],
            ]],
        );

        $analysis = $this->analyze($campaign);
        $insight = $campaign->walletInsights()->first();

        // The transaction is counted, because it happened.
        $this->assertSame(1, $insight->activity_count);
        // And it is in no window, because nobody knows when.
        $this->assertNull($insight->first_activity_seconds);

        // The headline says it transacted, because it did.
        $this->assertSame(1, $analysis->summary['new_active']);
        $this->assertEquals(100.0, $analysis->summary['new_active_pct']);

        // And no window says it failed to, because no window can say anything about it.
        $windows = collect($analysis->summary['windows'])->keyBy('days');
        $this->assertSame(0, $windows[30]['new_observable']);
        $this->assertSame(1, $windows[30]['new_untimed']);
        $this->assertSame(0, $windows[30]['new_not_yet']);
        $this->assertSame(0, $windows[30]['new_active']);
        $this->assertEquals(0.0, $windows[30]['new_active_pct']);

        $row = CampaignWalletActivity::where('campaign_id', $campaign->id)->firstOrFail();
        $this->assertNull($row->seconds_after_claim);
        $this->assertNull($row->occurred_at);
    }

    /**
     * The days a result was measured over are read from the run, not from the clock.
     *
     * Left to now(), the same stored rows report a longer observation every day nobody
     * re-runs anything, which is a figure about the reader rather than about the campaign.
     */
    public function test_the_observation_length_comes_from_the_run_not_from_now(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();

        $this->insight($campaign, 'measured', '2026-01-01 00:00:00', [
            'windows_observed_at' => Carbon::parse('2026-03-02 00:00:00'),
            'observed_seconds' => 60 * 86400,
        ]);

        $service = new OnboardingAnalysisService(paced: false);

        $first = $service->summarizeWindow($campaign);
        $this->assertEquals(60.0, $first['observation_days_avg']);

        // A year later, with nothing observed in between, it still says sixty.
        $this->travel(365)->days();
        $later = $service->summarizeWindow($campaign);
        $this->assertEquals(60.0, $later['observation_days_avg']);
    }

    /**
     * The headline rate and the denominator printed beside it are the same population.
     *
     * The rate is of the NEW wallets a windowed run read. Exporting the wider count of
     * every wallet it read, and printing that beside the rate, described two populations in
     * one sentence: three of ten read is thirty percent, and the tile said fifty.
     */
    public function test_the_activity_rate_carries_the_denominator_it_was_taken_over(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();

        foreach (range(1, 6) as $i) {
            $this->insight($campaign, "new-{$i}", '2026-01-01 00:00:00', [
                'is_new' => true,
                'activity_count' => $i <= 3 ? 1 : 0,
                'first_activity_seconds' => $i <= 3 ? 10 * 86400 : null,
            ]);
        }

        foreach (range(1, 4) as $i) {
            $this->insight($campaign, "established-{$i}", '2026-01-01 00:00:00', [
                'is_new' => false,
                'activity_count' => $i <= 2 ? 1 : 0,
                'first_activity_seconds' => $i <= 2 ? 10 * 86400 : null,
            ]);
        }

        $summary = (new OnboardingAnalysisService(paced: false))->summarizeWindow($campaign);

        $this->assertSame(10, $summary['windows_observed']);
        $this->assertSame(6, $summary['windows_observed_new']);
        $this->assertSame(4, $summary['windows_observed_established']);

        // The figure and the population it was taken over agree, which is the whole point.
        $this->assertSame(3, $summary['new_active']);
        $this->assertSame(50.0, $summary['new_active_pct']);
        $this->assertSame(
            $summary['new_active_pct'],
            round(100 * $summary['new_active'] / $summary['windows_observed_new'], 1)
        );
        $this->assertSame(
            $summary['established_active_pct'],
            round(100 * $summary['established_active'] / $summary['windows_observed_established'], 1)
        );
    }

    /**
     * A range nobody claimed in is a measured result, not an unmeasured one.
     *
     * Derived from "at least one windowed wallet exists", an empty population made a
     * freshly finished run render as a result that predates certificate reading, and told
     * the operator to re-run an analysis that would change nothing.
     */
    public function test_a_range_with_no_claims_is_a_result_rather_than_an_unmeasured_campaign(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();
        $this->insight($campaign, 'claimed-in-january', '2026-01-15 00:00:00');

        $service = new OnboardingAnalysisService(paced: false);

        $empty = $service->summarizeWindow(
            $campaign,
            Carbon::parse('2026-03-01 00:00:00'),
            Carbon::parse('2026-03-31 23:59:59')
        );

        $this->assertSame(0, $empty['genuine_wallets']);
        $this->assertSame(0, $empty['windows_observed']);
        $this->assertSame(0, $empty['windows_unknown']);
        // Measured and empty, not unmeasured.
        $this->assertTrue($empty['windows_available']);

        // A population that holds a wallet no windowed run has read is still unmeasured.
        $this->insight($campaign, 'never-read', '2026-03-02 00:00:00', [
            'windows_observed_at' => null,
            'observed_seconds' => null,
        ]);

        $unread = $service->summarizeWindow(
            $campaign,
            Carbon::parse('2026-03-01 00:00:00'),
            Carbon::parse('2026-03-31 23:59:59')
        );

        $this->assertFalse($unread['windows_available']);
    }

    /**
     * A claim transaction the provider would not answer for leaves the wallet unknown.
     *
     * This is the failure the whole feature exists to prevent. Without the claim's own
     * block height nothing can be placed before or after it, and the run used to record
     * that as a wallet with no prior history that went on to do nothing: a campaign whose
     * provider was down reported every claimant as newly onboarded and inactive.
     */
    public function test_a_failed_claim_transaction_read_is_not_a_measured_zero(): void
    {
        $stake = 'stake1u'.str_repeat('f', 50);
        $campaign = $this->campaignWithClaim($stake);

        Http::fake([
            '*tx_info*' => Http::response('', 500),
            '*account_txs*' => Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]]),
            '*account_info*' => Http::response([]),
        ]);

        $analysis = $this->analyze($campaign);
        $insight = $campaign->walletInsights()->firstOrFail();

        $this->assertNull($insight->is_new);
        $this->assertNull($insight->prior_tx_count);
        $this->assertNull($insight->windows_observed_at);
        $this->assertFalse($insight->isWindowed());

        // The run says it did not read the campaign rather than calling itself complete.
        $this->assertSame(CampaignAnalysis::STATUS_PARTIAL, $analysis->status);
        $this->assertSame(1, $analysis->unread_wallets);

        $summary = $analysis->summary;
        $this->assertSame(1, $summary['genuine_wallets']);
        $this->assertSame(0, $summary['classified_wallets']);
        $this->assertSame(1, $summary['unclassified_wallets']);
        $this->assertSame(1, $summary['windows_unknown']);
        $this->assertFalse($summary['windows_available']);
        // Nothing was read, so nothing is claimed: not a campaign that onboarded everybody.
        $this->assertSame(0, $summary['new_wallets']);
        $this->assertEquals(0.0, $summary['new_pct']);
    }

    /**
     * A wallet history read that failed is not a wallet with no history.
     */
    public function test_a_failed_wallet_history_read_leaves_the_wallet_unknown(): void
    {
        $stake = 'stake1u'.str_repeat('g', 50);
        $campaign = $this->campaignWithClaim($stake);

        Http::fake([
            '*tx_info*' => Http::response([[
                'tx_hash' => self::CLAIM_TX,
                'block_height' => 1000,
                'tx_timestamp' => now()->subDays(10)->timestamp,
            ]]),
            '*account_txs*' => Http::response('', 500),
            '*account_info*' => Http::response([]),
        ]);

        $analysis = $this->analyze($campaign);
        $insight = $campaign->walletInsights()->firstOrFail();

        $this->assertNull($insight->is_new);
        $this->assertNull($insight->windows_observed_at);
        $this->assertSame(CampaignAnalysis::STATUS_PARTIAL, $analysis->status);
    }

    /**
     * A follow-up transaction that could not be read leaves the windows unknown.
     *
     * Whether the wallet transacted or only delegated is in the detail of that
     * transaction. Missing it, the run knows the wallet did something and not what, which
     * is not the same as a wallet that did nothing.
     */
    public function test_a_failed_follow_up_read_leaves_the_windows_unknown(): void
    {
        $stake = 'stake1u'.str_repeat('h', 50);
        $campaign = $this->campaignWithClaim($stake);

        Http::fake([
            '*tx_info*' => function ($request) {
                $hashes = $request->data()['_tx_hashes'] ?? [];

                if (in_array(self::CLAIM_TX, $hashes, true)) {
                    return Http::response([[
                        'tx_hash' => self::CLAIM_TX,
                        'block_height' => 1000,
                        'tx_timestamp' => now()->subDays(10)->timestamp,
                    ]]);
                }

                return Http::response('', 500);
            },
            '*account_txs*' => Http::response([
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'block_time' => now()->subDays(10)->timestamp],
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100, 'block_time' => now()->subDays(5)->timestamp],
            ]),
            '*account_info*' => Http::response([]),
        ]);

        $analysis = $this->analyze($campaign);
        $insight = $campaign->walletInsights()->firstOrFail();

        // The history read succeeded, so that half of the row is known.
        $this->assertTrue($insight->is_new);
        $this->assertSame(0, $insight->prior_tx_count);
        // The follow-up read did not, so the windows are not.
        $this->assertNull($insight->windows_observed_at);
        $this->assertSame(CampaignAnalysis::STATUS_PARTIAL, $analysis->status);
        $this->assertSame(1, $analysis->summary['windows_unknown']);
    }

    /**
     * An account the delegation read failed for is not an account that is not delegating.
     *
     * A stake key missing from a successful answer is a different fact: Koios has no row
     * for a key that was never registered, and that wallet really is not delegating.
     */
    public function test_a_failed_delegation_read_is_not_a_wallet_that_never_delegated(): void
    {
        $stake = 'stake1u'.str_repeat('i', 50);
        $campaign = $this->campaignWithClaim($stake);

        Http::fake([
            '*tx_info*' => Http::response([[
                'tx_hash' => self::CLAIM_TX,
                'block_height' => 1000,
                'tx_timestamp' => now()->subDays(10)->timestamp,
            ]]),
            '*account_txs*' => Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]]),
            '*account_info*' => Http::response('', 500),
        ]);

        $analysis = $this->analyze($campaign);
        $insight = $campaign->walletInsights()->firstOrFail();

        $this->assertNull($insight->delegated);
        $this->assertTrue($insight->is_new);
        $this->assertNotNull($insight->windows_observed_at);
        $this->assertSame(CampaignAnalysis::STATUS_PARTIAL, $analysis->status);

        // Nobody is reported as delegating out of a population nobody could be read for.
        $this->assertSame(0, $analysis->summary['delegated']);
        $this->assertSame(0, $analysis->summary['delegation_known']);
        $this->assertEquals(0.0, $analysis->summary['delegated_pct']);
    }

    /**
     * A failed re-run does not erase what an earlier run measured.
     *
     * The wallet was read in full once. A provider outage since then is a gap in the new
     * run, not a reason to forget the reading that worked, and the row goes on saying when
     * it was taken.
     */
    public function test_a_failed_re_run_keeps_what_the_last_good_run_measured(): void
    {
        $stake = 'stake1u'.str_repeat('j', 50);
        $campaign = $this->campaignWithClaim($stake);

        $down = false;

        Http::fake([
            '*tx_info*' => function () use (&$down) {
                return $down
                    ? Http::response('', 500)
                    : Http::response([[
                        'tx_hash' => self::CLAIM_TX,
                        'block_height' => 1000,
                        'tx_timestamp' => now()->subDays(10)->timestamp,
                    ]]);
            },
            '*account_txs*' => function () use (&$down) {
                return $down
                    ? Http::response('', 500)
                    : Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]]);
            },
            '*account_info*' => function () use (&$down) {
                return $down ? Http::response('', 500) : Http::response([]);
            },
        ]);

        $first = $this->analyze($campaign);
        $this->assertSame(CampaignAnalysis::STATUS_COMPLETE, $first->status);
        $measuredAt = $campaign->walletInsights()->firstOrFail()->windows_observed_at;

        $down = true;

        $second = $this->analyze($campaign);
        $insight = $campaign->walletInsights()->firstOrFail();

        $this->assertSame(CampaignAnalysis::STATUS_PARTIAL, $second->status);
        $this->assertTrue($insight->is_new);
        $this->assertSame(
            $measuredAt->toIso8601String(),
            $insight->windows_observed_at->toIso8601String()
        );
    }

    /**
     * A run that read everything says so.
     */
    public function test_a_run_that_read_everything_is_complete(): void
    {
        $stake = 'stake1u'.str_repeat('k', 50);
        $campaign = $this->campaignWithClaim($stake);

        $this->fakeKoios(
            claimTx: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'tx_timestamp' => now()->subDays(10)->timestamp]],
            accountTxs: [['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]],
        );

        $analysis = $this->analyze($campaign);

        $this->assertSame(CampaignAnalysis::STATUS_COMPLETE, $analysis->status);
        $this->assertSame(0, $analysis->unread_wallets);
        $this->assertFalse($analysis->isPartial());
        $this->assertSame(0, $analysis->summary['unclassified_wallets']);
    }

    /**
     * A 200 whose body is not JSON is a failed read, not an empty history.
     *
     * This is what a proxy or a CDN interstitial looks like: the status says the request
     * succeeded and the body is a page of HTML. The decode returned null, the null became
     * an empty list, and the empty list said the wallet had never transacted, which is the
     * headline claim this whole analysis exists to make. One claimant, one outage, and the
     * campaign reported a hundred percent newly onboarded.
     */
    public function test_a_two_hundred_with_an_undecodable_body_is_not_an_empty_history(): void
    {
        $stake = 'stake1u'.str_repeat('m', 50);
        $campaign = $this->campaignWithClaim($stake);

        Http::fake([
            '*tx_info*' => Http::response([[
                'tx_hash' => self::CLAIM_TX,
                'block_height' => 1000,
                'tx_timestamp' => now()->subDays(10)->timestamp,
                'inputs' => [],
                'certificates' => [],
            ]]),
            '*account_txs*' => Http::response('<html><body>Checking your browser</body></html>', 200),
            '*account_info*' => Http::response([]),
        ]);

        $analysis = $this->analyze($campaign);
        $insight = $campaign->walletInsights()->firstOrFail();

        $this->assertNull($insight->is_new);
        $this->assertNull($insight->prior_tx_count);
        $this->assertNull($insight->windows_observed_at);

        $this->assertSame(CampaignAnalysis::STATUS_PARTIAL, $analysis->status);
        $this->assertSame(1, $analysis->unread_wallets);

        $summary = $analysis->summary;
        $this->assertSame(0, $summary['classified_wallets']);
        $this->assertSame(1, $summary['unclassified_wallets']);
        $this->assertSame(0, $summary['new_wallets']);
        $this->assertEquals(0.0, $summary['new_pct']);
    }

    /**
     * The same, on the account read, where it made every wallet read as not delegating.
     */
    public function test_an_undecodable_account_answer_is_not_a_wallet_that_is_not_delegating(): void
    {
        $stake = 'stake1u'.str_repeat('n', 50);
        $campaign = $this->campaignWithClaim($stake);

        Http::fake([
            '*tx_info*' => Http::response([[
                'tx_hash' => self::CLAIM_TX,
                'block_height' => 1000,
                'tx_timestamp' => now()->subDays(10)->timestamp,
                'inputs' => [],
                'certificates' => [],
            ]]),
            '*account_txs*' => Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]]),
            '*account_info*' => Http::response('service temporarily unavailable', 200),
        ]);

        $analysis = $this->analyze($campaign);
        $insight = $campaign->walletInsights()->firstOrFail();

        $this->assertNull($insight->delegated);
        $this->assertTrue($insight->is_new);
        $this->assertSame(CampaignAnalysis::STATUS_PARTIAL, $analysis->status);
        $this->assertSame(0, $analysis->summary['delegation_known']);
    }

    /**
     * A body that decodes to the wrong shape is a failed read too.
     *
     * These endpoints answer with a list of rows. An error document is an object carrying
     * 200, and a decoded object used to travel on as though it were rows: every lookup for
     * a stake key missed, every missing key was read as an account the chain has no
     * registration for, and the campaign was reported as one where nobody delegates.
     */
    public function test_a_two_hundred_that_decodes_to_an_object_is_not_rows(): void
    {
        $stake = 'stake1u'.str_repeat('o', 50);
        $campaign = $this->campaignWithClaim($stake);

        Http::fake([
            '*tx_info*' => Http::response([[
                'tx_hash' => self::CLAIM_TX,
                'block_height' => 1000,
                'tx_timestamp' => now()->subDays(10)->timestamp,
                'inputs' => [],
                'certificates' => [],
            ]]),
            '*account_txs*' => Http::response([['tx_hash' => self::CLAIM_TX, 'block_height' => 1000]]),
            '*account_info*' => Http::response(['code' => 'PGRST301', 'message' => 'JWT expired'], 200),
        ]);

        $analysis = $this->analyze($campaign);
        $insight = $campaign->walletInsights()->firstOrFail();

        // Not delegating is a thing the chain says. This answer said nothing.
        $this->assertNull($insight->delegated);
        $this->assertSame(CampaignAnalysis::STATUS_PARTIAL, $analysis->status);
        $this->assertSame(1, $analysis->unread_wallets);
        $this->assertSame(0, $analysis->summary['delegation_known']);
    }

    /**
     * A list that does not hold rows is not rows either.
     */
    public function test_a_two_hundred_carrying_a_list_of_scalars_is_not_rows(): void
    {
        $stake = 'stake1u'.str_repeat('p', 50);
        $campaign = $this->campaignWithClaim($stake);

        Http::fake([
            '*tx_info*' => Http::response([[
                'tx_hash' => self::CLAIM_TX,
                'block_height' => 1000,
                'tx_timestamp' => now()->subDays(10)->timestamp,
                'inputs' => [],
                'certificates' => [],
            ]]),
            '*account_txs*' => Http::response(['rate limit exceeded'], 200),
            '*account_info*' => Http::response([]),
        ]);

        $analysis = $this->analyze($campaign);

        $this->assertNull($campaign->walletInsights()->firstOrFail()->is_new);
        $this->assertSame(CampaignAnalysis::STATUS_PARTIAL, $analysis->status);
    }

    /**
     * A provider that cannot be reached at all leaves holes, like one that answers badly.
     *
     * A refused connection or a timeout is the same non-answer as a 500, and it is treated
     * the same way: the wallets it touched are unknown and the run says it is partial.
     * Letting it out as an exception threw away every wallet the run had already read and
     * recorded the whole analysis as failed.
     */
    public function test_a_provider_that_cannot_be_reached_leaves_the_wallet_unknown(): void
    {
        $stake = 'stake1u'.str_repeat('q', 50);
        $campaign = $this->campaignWithClaim($stake);

        Http::fake([
            '*tx_info*' => Http::response([[
                'tx_hash' => self::CLAIM_TX,
                'block_height' => 1000,
                'tx_timestamp' => now()->subDays(10)->timestamp,
                'inputs' => [],
                'certificates' => [],
            ]]),
            '*account_txs*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect'),
            '*account_info*' => Http::response([]),
        ]);

        $analysis = $this->analyze($campaign);
        $insight = $campaign->walletInsights()->firstOrFail();

        $this->assertNull($insight->is_new);
        $this->assertSame(CampaignAnalysis::STATUS_PARTIAL, $analysis->status);
        $this->assertSame(1, $analysis->unread_wallets);
    }

    /**
     * A follow-up transaction answered without the parts that were asked for is unread.
     *
     * tx_info returns inputs and certificates only when they are asked for by name, and
     * both are. A row that answers without them cannot say who sent the transaction or
     * whether it carried a certificate, so reading it produced a wallet that had not
     * transacted and had not delegated: two measurements out of one missing answer.
     */
    public function test_a_follow_up_row_missing_what_was_asked_for_leaves_the_windows_unknown(): void
    {
        $complete = [
            'tx_hash' => self::LATER_TX,
            'block_height' => 1100,
            'inputs' => [],
            'outputs' => [],
            'certificates' => [],
        ];

        $row = $complete;

        // One fake for the whole test, answering out of $row. Http::fake() adds to the
        // stubs already registered rather than replacing them, so registering a second one
        // inside the loop would leave every case after the first answered by the first.
        Http::fake([
            '*tx_info*' => function ($request) use (&$row) {
                $hashes = $request->data()['_tx_hashes'] ?? [];

                if (in_array(self::CLAIM_TX, $hashes, true)) {
                    return Http::response([[
                        'tx_hash' => self::CLAIM_TX,
                        'block_height' => 1000,
                        'tx_timestamp' => now()->subDays(10)->timestamp,
                    ]]);
                }

                return Http::response([$row]);
            },
            '*account_txs*' => Http::response([
                ['tx_hash' => self::CLAIM_TX, 'block_height' => 1000, 'block_time' => now()->subDays(10)->timestamp],
                ['tx_hash' => self::LATER_TX, 'block_height' => 1100, 'block_time' => now()->subDays(5)->timestamp],
            ]),
            '*account_info*' => Http::response([]),
        ]);

        // Each section on its own, because each answers a different question and any one of
        // them missing makes the transaction unreadable for a different reason.
        foreach (['inputs', 'outputs', 'certificates'] as $i => $missing) {
            $stake = 'stake1u'.str_repeat(chr(ord('r') + $i), 50);
            $campaign = $this->campaignWithClaim($stake);

            $row = $complete;
            unset($row[$missing]);

            $analysis = $this->analyze($campaign);
            $insight = $campaign->walletInsights()->firstOrFail();

            $this->assertTrue($insight->is_new, $missing);
            $this->assertNull($insight->windows_observed_at, $missing);
            $this->assertSame(CampaignAnalysis::STATUS_PARTIAL, $analysis->status, $missing);
            $this->assertSame(1, $analysis->summary['windows_unknown'], $missing);
        }
    }

    /**
     * A wallet nobody watched contributes no observation window.
     *
     * The average used to fall back to the time elapsed since the claim for a wallet with
     * no recorded length, so a campaign nobody had re-read since January reported a longer
     * and longer observation every day, off rows that had never been observed at all.
     */
    public function test_an_unwatched_wallet_does_not_lengthen_the_observation_window(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();

        $this->insight($campaign, 'watched', '2026-01-01 00:00:00', [
            'windows_observed_at' => Carbon::parse('2026-03-02 00:00:00'),
            'observed_seconds' => 60 * 86400,
        ]);

        $this->insight($campaign, 'never-watched', '2026-01-01 00:00:00', [
            'windows_observed_at' => null,
            'observed_seconds' => null,
        ]);

        $service = new OnboardingAnalysisService(paced: false);

        $this->travelTo(Carbon::parse('2026-03-02 00:00:00'));
        $summary = $service->summarizeWindow($campaign);

        // One wallet was watched, for sixty days, and that is the whole of the average.
        $this->assertSame(1, $summary['observation_wallets']);
        $this->assertEquals(60.0, $summary['observation_days_avg']);

        // A year later the unwatched wallet has still been watched for nothing.
        $this->travel(365)->days();
        $later = $service->summarizeWindow($campaign);

        $this->assertSame(1, $later['observation_wallets']);
        $this->assertEquals(60.0, $later['observation_days_avg']);
    }

    /**
     * Nothing to average is null, not zero days.
     */
    public function test_a_result_nobody_was_watched_in_reports_no_observation_window(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();

        $this->insight($campaign, 'never-watched', '2026-01-01 00:00:00', [
            'windows_observed_at' => null,
            'observed_seconds' => null,
        ]);

        $summary = (new OnboardingAnalysisService(paced: false))->summarizeWindow($campaign);

        $this->assertNull($summary['observation_days_avg']);
        $this->assertNull($summary['observation_days_max']);
        $this->assertSame(0, $summary['observation_wallets']);
    }

    /**
     * An untimed transaction does not hold the wallet out of the delegation window.
     *
     * The two events are timed separately, so one of them being unplaceable says nothing
     * about the other. Asked as one question, a wallet whose transaction carried no block
     * time was dropped from the delegation denominator as well, and a delegation the chain
     * had timed to the second went unreported.
     */
    /**
     * The converse, and the one that catches the two questions being folded back together.
     *
     * The case above has an untimed transaction, which makes the activity clause true on its
     * own, so it answers the same whether the two checks are separate or joined by an or. This
     * one has the timed event and the untimed event the other way round, so joining them drops
     * a transaction that was read and timed out of the activity window.
     */
    public function test_an_untimed_delegation_does_not_hide_a_timed_transaction(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();

        $this->insight($campaign, 'untimed-delegation', '2026-01-01 00:00:00', [
            'is_new' => true,
            'activity_count' => 1,
            'first_activity_seconds' => 5 * 86400,
            'delegation_events' => 1,
            'first_delegation_seconds' => null,
        ]);

        $summary = (new OnboardingAnalysisService(paced: false))->summarizeWindow($campaign);
        $thirty = collect($summary['windows'])->firstWhere('days', 30);

        // Inside the activity window, which it has a time for.
        $this->assertSame(1, $thirty['new_observable']);
        $this->assertSame(0, $thirty['new_untimed']);
        $this->assertSame(1, $thirty['new_active']);
        $this->assertEquals(100.0, $thirty['new_active_pct']);

        // And held out of the delegation window, which it does not.
        $this->assertSame(0, $thirty['new_delegation_observable']);
        $this->assertSame(1, $thirty['new_delegation_untimed']);
    }

    /**
     * A delegation the chain gave no time for is not a wallet that did not delegate.
     *
     * The numerator asks for a delegation timestamp, so a wallet without one fails it while
     * sitting in the denominator. Counted over every windowed new wallet it therefore reports
     * as a wallet that never delegated, and the follow-up table holding the same wallet out of
     * its window puts two answers for one fact on one screen.
     */
    public function test_a_delegation_with_no_time_is_not_a_wallet_that_did_not_delegate(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();

        $this->insight($campaign, 'timed-delegation', '2026-01-01 00:00:00', [
            'is_new' => true,
            'delegation_events' => 1,
            'first_delegation_seconds' => 5 * 86400,
        ]);

        $this->insight($campaign, 'untimed-delegation', '2026-01-01 00:00:00', [
            'is_new' => true,
            'delegation_events' => 1,
            'first_delegation_seconds' => null,
        ]);

        $summary = (new OnboardingAnalysisService(paced: false))->summarizeWindow($campaign);

        $this->assertSame(1, $summary['new_delegated_after_claim']);
        $this->assertSame(
            1,
            $summary['new_delegated_after_claim_known'],
            'The wallet whose delegation carries no time belongs in neither half of this figure.'
        );
        $this->assertEquals(
            100.0,
            $summary['new_delegated_after_claim_pct'],
            'One of one placed delegations is 100 percent; over both wallets it would read 50, '
            .'which the follow-up table contradicts for the same two wallets.'
        );
    }

    public function test_an_untimed_transaction_does_not_hide_a_timed_delegation(): void
    {
        $campaign = Campaign::factory()->mainnet()->create();

        // Transacted at a time the provider did not give, and delegated at one it did.
        $this->insight($campaign, 'untimed-tx', '2026-01-01 00:00:00', [
            'is_new' => true,
            'activity_count' => 1,
            'first_activity_seconds' => null,
            'delegation_events' => 1,
            'first_delegation_seconds' => 5 * 86400,
        ]);

        $summary = (new OnboardingAnalysisService(paced: false))->summarizeWindow($campaign);
        $thirty = collect($summary['windows'])->firstWhere('days', 30);

        // Held out of the activity window, because that is the measure it has no time for.
        $this->assertSame(0, $thirty['new_observable']);
        $this->assertSame(1, $thirty['new_untimed']);

        // And inside the delegation window, which it does have a time for.
        $this->assertSame(1, $thirty['new_delegation_observable']);
        $this->assertSame(0, $thirty['new_delegation_untimed']);
        $this->assertSame(1, $thirty['new_delegated']);
        $this->assertEquals(100.0, $thirty['new_delegated_pct']);
    }

    /**
     * A stored per-wallet row, as a windowed run would have written it.
     *
     * `windows_observed_at` defaults to a moment well after the claim, because a row
     * without it is a row no windowed run has read and means unknown rather than nothing.
     * The tests that care about that difference set it themselves.
     */
    private function insight(Campaign $campaign, string $stakeKey, ?string $claimedAt, array $attributes = []): CampaignWalletInsight
    {
        $observedAt = $attributes['windows_observed_at'] ?? ($claimedAt
            ? Carbon::parse($claimedAt)->addDays(120)
            : null);

        $defaults = [
            'campaign_id' => $campaign->id,
            'stake_key' => $stakeKey,
            'claimed_at' => $claimedAt,
            'is_new' => true,
            'activity_count' => 0,
            'delegation_events' => 0,
            'windows_observed_at' => $observedAt,
            'observed_seconds' => ($claimedAt && $observedAt)
                ? Carbon::parse($observedAt)->getTimestamp() - Carbon::parse($claimedAt)->getTimestamp()
                : null,
        ];

        return CampaignWalletInsight::create($attributes + $defaults);
    }
}

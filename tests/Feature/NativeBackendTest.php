<?php

namespace Tests\Feature;

use Cardano\Transaction\Codec\TransactionDecoder;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\NativeCampaigns;
use Tests\TestCase;

/**
 * The native backend against a chain that answers in the provider package's own types.
 */
class NativeBackendTest extends TestCase
{
    use NativeCampaigns;
    use RefreshDatabase;

    private const POLICY = '0123456789abcdef0123456789abcdef0123456789abcdef01234567';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpNativeChain();
    }

    /**
     * A payment spends the output the provider named, by its real index, and balances against
     * what that output really holds.
     *
     * The provider's Utxo carries its index as outputIndex and its value as a Value object. Read
     * by any other names, every index is zero and every value is unreadable, and nothing can be
     * paid at all.
     */
    public function test_a_payment_spends_the_provider_output_it_names(): void
    {
        $campaign = $this->nativeCampaign();
        $funding = $this->chain->fund($campaign->wallet->address, 50_000_000, index: 3);
        $claim = $this->claim($campaign, 1);

        $this->backend()->submitPayment($campaign->id, [$this->recipient($claim)], 'preprod');

        [$hash] = $this->chain->pending();
        $transaction = TransactionDecoder::decode($this->chain->mempool[$hash]);

        $inputs = $transaction->body->inputs();
        $this->assertCount(1, $inputs);
        $this->assertSame($funding, $inputs[0]->transactionIdHex());
        $this->assertSame(3, $inputs[0]->index->toInt());

        $out = $transaction->body->fee()->toInt();

        foreach ($transaction->body->outputs() as $output) {
            $out += $output->value->coin->toInt();
        }

        $this->assertSame(50_000_000, $out);
    }

    /**
     * The balance answers in the shape the campaign page reads, which is the hosted backend's:
     * one entry per output with lovelace and a list of assets by policy, name and amount.
     */
    public function test_the_balance_is_in_the_shape_the_campaign_page_reads(): void
    {
        $campaign = $this->nativeCampaign();
        $address = $campaign->wallet->address;
        $this->chain->fund($address, 7_000_000);
        $this->chain->fund($address, 2_000_000, [[self::POLICY, '4f4e45', '5']]);

        $balance = $this->backend()->getBalance($address, 'preprod');

        $this->assertCount(2, $balance);

        foreach ($balance as $utxo) {
            $this->assertIsString($utxo['lovelace']);
            $this->assertIsArray($utxo['nativeAssets']);
        }

        $this->assertSame('7000000', $balance[0]['lovelace']);
        $this->assertSame([], $balance[0]['nativeAssets']);
        $this->assertSame('2000000', $balance[1]['lovelace']);
        $this->assertSame([['policy' => self::POLICY, 'name' => '4f4e45', 'amount' => '5']], $balance[1]['nativeAssets']);
    }

    /**
     * The minimum-UTxO coefficient is read from the chain's parameters, not the fallback.
     */
    public function test_protocol_parameters_come_from_the_chain(): void
    {
        $this->assertSame(
            ['coins_per_utxo_byte' => 4310, 'source' => 'chain'],
            $this->backend()->protocolParameters('preprod'),
        );
    }

    private const FT = '4654';

    private const NFT_A = '4e46542d41';

    private const NFT_B = '4e46542d42';

    /** @return array<string, \Cardano\Transaction\Primitives\TransactionOutput[]> outputs of the one pending transaction, by address */
    private function pendingOutputs(): array
    {
        $this->assertCount(1, $this->chain->pending());
        $outputs = [];

        foreach (TransactionDecoder::decode($this->chain->mempool[$this->chain->pending()[0]])->body->outputs() as $output) {
            $outputs[\Cardano\Transaction\Address\Address::fromBytes($output->address)->toBech32()][] = $output;
        }

        return $outputs;
    }

    private function quantity(\Cardano\Transaction\Primitives\TransactionOutput $output, string $name): string
    {
        return \Cardano\Transaction\Selection\ValueBag::fromValue($output->value)
            ->quantityOf(\Cardano\Transaction\Selection\AssetId::fromHex(self::POLICY, $name))->value;
    }

    /**
     * The Babbage minimum, worked out here from the output's own CBOR and the chain's
     * coinsPerUTxOByte: (160 + |serialized output|) * coinsPerUTxOByte (CIP-55, Specification).
     */
    private function minimumOf(\Cardano\Transaction\Primitives\TransactionOutput $output): int
    {
        return (160 + strlen($output->encode())) * 4310;
    }

    private function tokenFunds(\App\Models\Campaign $campaign): void
    {
        $address = $campaign->wallet->address;
        $this->chain->fund($address, 50_000_000);
        $this->chain->fund($address, 10_000_000, [[self::POLICY, self::FT, '1000'], [self::POLICY, self::NFT_B, '1']], 1);
        $this->chain->fund($address, 2_000_000, [[self::POLICY, self::NFT_A, '1']], 2);
    }

    /**
     * Every kind of reward in one batch: ADA only, a fungible token and an NFT. Each recipient's
     * output holds its reward exactly, with the ledger minimum for that very output and no more
     * (the stored reward is below it), and the ADA-only reward is left as stored.
     */
    public function test_a_batch_mixing_ada_only_and_token_rewards_pays_each_at_its_exact_minimum(): void
    {
        $campaign = $this->nativeCampaign();
        $this->tokenFunds($campaign);
        $plain = $this->claim($campaign, 1, 2_000_000);
        $ft = $this->claim($campaign, 2, 1_000_000);
        $nft = $this->claim($campaign, 3, 1_000_000);

        $ids = $this->backend()->submitPayment($campaign->id, [
            $this->recipient($plain),
            $this->recipient($ft, [['policy' => self::POLICY, 'name' => self::FT, 'amount' => '10']]),
            $this->recipient($nft, [['policy' => self::POLICY, 'name' => self::NFT_A, 'amount' => '1']]),
        ], 'preprod')['claimIds'];

        $this->assertCount(3, $ids);
        $outputs = $this->pendingOutputs();

        $this->assertSame(2_000_000, $outputs[$plain->address][0]->value->coin->toInt());

        $this->assertSame('10', $this->quantity($outputs[$ft->address][0], self::FT));
        $this->assertSame(1, $outputs[$ft->address][0]->value->assetCount());
        $this->assertSame($this->minimumOf($outputs[$ft->address][0]), $outputs[$ft->address][0]->value->coin->toInt());
        $this->assertGreaterThan(1_000_000, $outputs[$ft->address][0]->value->coin->toInt());

        $this->assertSame('1', $this->quantity($outputs[$nft->address][0], self::NFT_A));
        $this->assertSame($this->minimumOf($outputs[$nft->address][0]), $outputs[$nft->address][0]->value->coin->toInt());
    }

    /**
     * What an input holds beyond what is sent goes back to the campaign with one asset class per
     * output: the rest of the fungible token alone, the NFT that rode along alone, and the ADA
     * alone. Each asset output holds its exact minimum.
     */
    public function test_the_remainder_goes_back_one_asset_class_per_output(): void
    {
        $campaign = $this->nativeCampaign();
        $this->tokenFunds($campaign);
        $ft = $this->claim($campaign, 1, 1_000_000);

        $this->backend()->submitPayment($campaign->id, [
            $this->recipient($ft, [['policy' => self::POLICY, 'name' => self::FT, 'amount' => '10']]),
        ], 'preprod');

        $change = $this->pendingOutputs()[$campaign->wallet->address];
        $classes = array_map(static fn ($output): int => $output->value->assetCount(), $change);
        sort($classes);

        $this->assertSame([0, 1, 1], $classes);

        $held = [];

        foreach ($change as $output) {
            if ($output->value->hasAssets()) {
                $held[] = $this->quantity($output, self::FT).'/'.$this->quantity($output, self::NFT_B);
                $this->assertSame($this->minimumOf($output), $output->value->coin->toInt());
            }
        }

        sort($held);
        $this->assertSame(['0/1', '990/0'], $held);
        $this->assertSame(0, end($change)->value->assetCount(), 'The ADA change is the last output.');
    }

    /**
     * A reward whose token the campaign does not hold is left unpaid and does not stop the rest of
     * the batch: the other claims are paid and the claim stays unsent.
     */
    public function test_a_reward_the_campaign_cannot_cover_is_skipped_and_the_rest_are_paid(): void
    {
        $campaign = $this->nativeCampaign();
        $this->tokenFunds($campaign);
        $plain = $this->claim($campaign, 1);
        $missing = $this->claim($campaign, 2);
        $tooMany = $this->claim($campaign, 3, 1_000_000);
        $ft = $this->claim($campaign, 4, 1_000_000);

        $ids = $this->backend()->submitPayment($campaign->id, [
            $this->recipient($plain),
            $this->recipient($missing, [['policy' => self::POLICY, 'name' => '4d495353', 'amount' => '1']]),
            $this->recipient($tooMany, [['policy' => self::POLICY, 'name' => self::FT, 'amount' => '5000']]),
            $this->recipient($ft, [['policy' => self::POLICY, 'name' => self::FT, 'amount' => '1']]),
        ], 'preprod')['claimIds'];

        $this->assertSame([$plain->id, $ft->id], array_keys($ids));
        $outputs = $this->pendingOutputs();
        $this->assertArrayNotHasKey($missing->address, $outputs);
        $this->assertArrayNotHasKey($tooMany->address, $outputs);
        $this->assertArrayHasKey($ft->address, $outputs);
    }

    /**
     * A spam token sent to a campaign's address does not stop it being returned. The sweep takes
     * the token with the ADA, every output pays the destination, and the campaign is left empty.
     */
    public function test_a_campaign_holding_a_spam_token_can_still_be_swept(): void
    {
        $campaign = $this->nativeCampaign();
        $address = $campaign->wallet->address;
        $this->chain->fund($address, 20_000_000);
        $this->chain->fund($address, 1_500_000, [[self::POLICY, '5350414d', '1000000']]);
        $destination = $this->claimant(9);

        $this->assertTrue($this->backend()->refund($campaign->id, $destination, 'preprod'));

        [$hash] = $this->chain->pending();
        $this->chain->land($hash);

        $fee = TransactionDecoder::decode(
            \App\Cardano\ChainRetention::signedBytes(\DB::table('campaign_transactions')->where('tx_hash', $hash)->first())
        )->body->fee()->toInt();

        $this->assertSame([], $this->chain->utxos[$address]);
        $this->assertSame(21_500_000 - $fee, $this->chain->balance($destination));

        $tokens = [];

        foreach ($this->chain->utxos[$destination] as $utxo) {
            foreach ($utxo->value->assets as $unit => $asset) {
                $tokens[$unit] = $asset->quantity;
            }
        }

        $this->assertSame([self::POLICY.'5350414d' => '1000000'], $tokens);
    }

    /**
     * A sweep goes through the gate a payment goes through. A destination on another network is
     * refused before anything is recorded or sent, rather than the whole balance going to an
     * address nobody on this chain holds.
     */
    public function test_a_sweep_to_another_network_is_refused_by_the_gate(): void
    {
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, 20_000_000);

        $mainnet = \Cardano\Transaction\Address\Address::fromBech32($this->claimant(9));
        $elsewhere = \Cardano\Transaction\Address\BaseAddress::of(
            \Cardano\Transaction\Address\Network::Mainnet,
            $mainnet->payment,
            $mainnet->delegation,
        )->toBech32();

        try {
            $this->backend()->refund($campaign->id, $elsewhere, 'preprod');
            $this->fail('A sweep to a mainnet address was sent from a preprod campaign.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('wrong network', $e->getMessage());
        }

        $this->assertSame([], $this->chain->offered);
        $this->assertSame(0, \DB::table('campaign_transactions')->count());
    }

    /**
     * A balance that cannot be read is not an empty one. The backend says so, and the campaign page
     * receives no balance rather than an empty list, and no claims-remaining figure.
     */
    public function test_a_balance_that_cannot_be_read_is_unknown_and_not_empty(): void
    {
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, 50_000_000);
        $headers = [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) (new \App\Http\Middleware\HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'Campaign/Show',
            'X-Inertia-Partial-Data' => 'balance,alerts',
        ];

        $read = $this->actingAs($campaign->user)->get(route('campaigns.show', $campaign), $headers);
        $this->assertSame('50000000', $read->json('props.balance.0.lovelace'));

        $this->chain->utxosDown = true;

        $this->assertNull($this->actingAs($campaign->user)->get(route('campaigns.show', $campaign), $headers)->json('props.balance'));
        $this->assertNull($this->actingAs($campaign->user)->get(route('campaigns.show', $campaign), $headers)->json('props.alerts.claims_remaining'));

        $this->expectException(\App\Cardano\FloatUnknown::class);
        $this->backend()->getBalance($campaign->wallet->address, 'preprod');
    }

    /**
     * The lease holder is a host name, a process id and a random token, the same for the life of
     * the process. A host name and process id alone repeat across containers and restarts, and a
     * second worker that looked like the first would be let back into a lease it does not hold.
     */
    public function test_the_lease_holder_carries_a_random_token_chosen_once_per_process(): void
    {
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, 50_000_000);
        $holders = [];

        $this->chain->refuse = function () use ($campaign, &$holders) {
            $holders[] = DB::table('campaign_chain_leases')->where('campaign_id', $campaign->id)->value('holder');

            return null;
        };

        foreach ([1, 2] as $n) {
            $this->backend()->submitPayment($campaign->id, [$this->recipient($this->claim($campaign, $n))], 'preprod');
        }

        $this->assertNotEmpty($holders);
        $this->assertCount(1, array_unique($holders));
        $this->assertMatchesRegularExpression('/^.+:\d+:[0-9a-f]{16}$/', $holders[0]);
        $this->assertLessThanOrEqual(64, strlen($holders[0]));
    }

    /**
     * Only a 2xx answer means the transaction was accepted. A redirect did not reach a node, so it
     * is a refusal, not a payment in flight.
     */
    public function test_a_redirect_is_not_an_acceptance(): void
    {
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, 50_000_000);
        $claim = $this->claim($campaign, 1);
        $this->chain->refuse = fn () => (new HttpFactory)->createResponse(302)->withHeader('Location', 'https://elsewhere.test/');

        $this->assertSame([], $this->backend()->submitPayment($campaign->id, [$this->recipient($claim)], 'preprod')['claimIds']);
        $this->assertSame('rejected', DB::table('campaign_transactions')->value('failure'));
    }

    /**
     * The expiry that bounds a payment is the one inside the script, read from the wallet's
     * recovery record, and not whatever the campaign's column says now.
     */
    public function test_a_payment_is_bounded_by_the_expiry_inside_the_script(): void
    {
        $campaign = $this->nativeCampaign();
        $this->chain->fund($campaign->wallet->address, 50_000_000);
        $claim = $this->claim($campaign, 1);

        // A column that has drifted from the script, written past the model's guard.
        DB::table('campaigns')->where('id', $campaign->id)->update(['expiry_slot' => $this->chain->slot - 1000]);

        $ids = $this->backend()->submitPayment($campaign->id, [$this->recipient($claim)], 'preprod')['claimIds'];

        $this->assertCount(1, $ids);
        $this->assertLessThan(
            $campaign->wallet->recovery_record['expiry_slot'],
            TransactionDecoder::decode($this->chain->mempool[$this->chain->pending()[0]])->body->ttl()->toInt(),
        );
    }
}

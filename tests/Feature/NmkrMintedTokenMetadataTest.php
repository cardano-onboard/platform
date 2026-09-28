<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The metadata a freshly minted token carries back to the claimant.
 *
 * A claim that mints through NMKR reads the token's own metadata out of the minter's `721`
 * map and hands it to the wallet, which is what lets a claimant see what they are about to
 * receive before it exists on chain. The map is keyed by the asset name as text, and for a
 * token named under CIP-0068 that key is the name with its label prefix removed. Looking it
 * up under the whole name finds nothing, and finding nothing there is silent: the claim
 * succeeds, the response carries a token with no metadata, and nothing says why.
 */
class NmkrMintedTokenMetadataTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = 'addr1qxegfu8m62peqmyamrdwmwqm00zjcak3u25xnanfdct4p9pf488uagw68fv50kjxv3wrx38829tay6zszthnccsradgqwt4upy';

    private const POLICY = 'd5e6bf0500378d4f0da4e8dde6becec7621cd8cbf5cbb9b87013d4cc';

    private User $user;

    private Campaign $campaign;

    private Code $code;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create([
            'network' => 'mainnet',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'one_per_wallet' => false,
            'nmkr_api_key' => 'test-key',
        ]);
        Wallet::factory()->for($this->campaign)->create();
        $this->code = Code::factory()->for($this->campaign)->create([
            'uses' => 10,
            'perWallet' => 10,
            'lovelace' => 2000000,
            'nmkr_project_uid' => 'project-uid',
            'nmkr_count_nft' => 1,
        ]);
    }

    /**
     * Stand in for NMKR: a mint that sends one token, and the details of that token with
     * its metadata filed under $metadataKey.
     */
    private function fakeNmkr(string $assetHex, string $metadataKey): void
    {
        $metadata = json_encode([
            '721' => [
                self::POLICY => [
                    $metadataKey => ['name' => 'Mascot', 'image' => 'ipfs://somewhere'],
                ],
            ],
        ]);

        Http::fake([
            '*/MintAndSendRandom/*' => Http::response(['sendedNft' => [['uid' => 'token-uid']]], 200),
            '*/GetNftDetailsById/*' => Http::response([
                'policyid' => self::POLICY,
                'assetname' => $assetHex,
                'metadata' => $metadata,
            ], 200),
            '*' => Http::response([], 200),
        ]);
    }

    private function claim(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('claim.v1', $this->campaign), [
            'code' => $this->code->code,
            'address' => self::ADDRESS,
        ]);
    }

    /**
     * The case the fix exists for. A CIP-0068 user token's metadata sits under the name
     * without its label, which is where the standard says to look for it.
     */
    public function test_a_cip68_token_carries_its_metadata_back_to_the_claimant(): void
    {
        // Label 222 in front of the name "TestToken", exactly as CIP-0068 writes it.
        $this->fakeNmkr('000de140'.bin2hex('TestToken'), 'TestToken');

        $response = $this->claim()->assertOk();

        $nfts = $response->json('nfts');

        $this->assertCount(1, $nfts);
        $this->assertSame(self::POLICY, $nfts[0]['policy_id']);
        $this->assertSame('Mascot', $nfts[0]['metadata']['name'] ?? null);
    }

    /**
     * A plain token still reads, which is every token minted before CIP-0068 and most of
     * them since.
     */
    public function test_an_unlabelled_token_carries_its_metadata_as_it_always_did(): void
    {
        $this->fakeNmkr(bin2hex('TestToken'), 'TestToken');

        $nfts = $this->claim()->assertOk()->json('nfts');

        $this->assertSame('Mascot', $nfts[0]['metadata']['name'] ?? null);
    }

    /**
     * Every registered label works, not only the one the page happens to use.
     *
     * The label is recognised by recomputing its checksum rather than by matching a list,
     * so a token minted under a label nobody here has heard of reads the same way.
     */
    public function test_each_registered_label_finds_its_metadata(): void
    {
        // Reference NFT, user NFT, fungible, rich fungible, and the royalty label.
        foreach (['000643b0', '000de140', '0014df10', '001bc280', '001f4d70'] as $prefix) {
            $this->fakeNmkr($prefix.bin2hex('TestToken'), 'TestToken');

            $nfts = $this->claim()->assertOk()->json('nfts');

            $this->assertSame('Mascot', $nfts[0]['metadata']['name'] ?? null, 'label prefix '.$prefix);
        }
    }

    /**
     * A token whose metadata genuinely is not there records the token and no metadata,
     * rather than failing the claim over it.
     */
    public function test_a_token_with_no_metadata_still_completes_the_claim(): void
    {
        $this->fakeNmkr(bin2hex('TestToken'), 'SomethingElse');

        $nfts = $this->claim()->assertOk()->json('nfts');

        $this->assertCount(1, $nfts);
        $this->assertNull($nfts[0]['metadata']);
    }
}

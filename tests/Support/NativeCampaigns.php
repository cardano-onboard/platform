<?php

namespace Tests\Support;

use App\Cardano\CampaignScript;
use App\Cardano\NativeWalletFactory;
use App\Cardano\PlatformFeeKey;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Services\NativeBackend;
use Cardano\Transaction\Address\BaseAddress;
use Cardano\Transaction\Address\Credential;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Signing\SigningKey;

/**
 * A native campaign with a real key, a real script address and a fake chain behind it.
 *
 * Everything between the campaign and the chain is the production code: the wallet factory makes
 * the key and the address, the backend selects, builds, signs, gates, records and submits, and the
 * fake chain decodes what it was sent. Only the provider is replaced.
 */
trait NativeCampaigns
{
    protected FakeCardanoChain $chain;

    /** The CIP-19 test vector reward address (type 14, testnet). */
    protected const PLATFORM_STAKE = 'stake_test1uqehkck0lajq8gr28t9uxnuvgcqrc6070x3k9r8048z8y5gssrtvn';

    protected function setUpNativeChain(): void
    {
        config([
            'cardano.transaction_backend' => 'native',
            'campaign_script.owner_key_hashes' => [str_repeat('01', 28), str_repeat('02', 28)],
            'campaign_script.wrapping_key_version' => 1,
            'campaign_script.wrapping_keys' => [1 => base64_encode(random_bytes(32))],
            'campaign_script.stake_addresses' => ['preprod' => self::PLATFORM_STAKE, 'preview' => null, 'mainnet' => null],
            'campaign_script.validity_seconds' => 7200,
            'campaign_script.confirmation_depth' => 3,
        ]);

        $this->chain = new FakeCardanoChain;
        $this->chain->bind($this->app);
    }

    /**
     * Turn the platform fee on for the native campaigns of this test: pricing set the way the
     * hosted deployment sets it, and the fee key generated. Set here rather than read from the
     * deployment's file so the same tests hold on an edition that has no pricing file.
     *
     * @param  array<int, int>  $tiers  claim ordinal a rate starts at, to the rate in lovelace
     */
    protected function collectPlatformFees(array $tiers = []): PlatformFeeKey
    {
        $tiers = $tiers ?: [1 => 1_000_000, 101 => 750_000];

        config(['pricing' => [
            'enabled' => true,
            'default_path' => 'ada',
            'paths' => ['ada' => [
                'currency' => 'ada',
                'enabled' => true,
                'collection' => 'in_band',
                'in_band_rates' => ['platform_fee_lovelace' => 1_000_000],
                'tiers' => $tiers,
            ]],
        ]]);

        $key = app(PlatformFeeKey::class);

        if (! $key->exists()) {
            $key->generate(PlatformFeeKey::configuredOwners());
        }

        return $key;
    }

    /**
     * A campaign with fees already collected: a confirmed fees output at the fee address holding
     * this much, recorded as the campaign's current one, with no payouts behind it. For tests that
     * need many campaigns holding fees without paying a claim for each.
     */
    protected function holdingFees(int $lovelace, array $attributes = []): Campaign
    {
        $campaign = $this->nativeCampaign($attributes + [
            'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
        ]);

        $hash = $this->chain->fund($this->feeAddress(), $lovelace);

        \Illuminate\Support\Facades\DB::table('campaign_transactions')->insert([
            'campaign_id' => $campaign->id,
            'sequence' => 1,
            'tx_hash' => $hash,
            'signed_cbor' => '',
            'inputs_consumed' => '[]',
            'fees_output_index' => 0,
            'validity_end' => 1,
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $campaign;
    }

    protected function feeAddress(string $network = 'preprod'): string
    {
        return app(PlatformFeeKey::class)->address($network);
    }

    protected function nativeCampaign(array $attributes = []): Campaign
    {
        $campaign = Campaign::factory()->create($attributes + [
            'network' => 'preprod',
            'expiry_slot' => $this->chain->slot + 180 * 86400,
            'funding_path' => CampaignScript::FUNDING_CREDIT,
        ]);

        app(NativeWalletFactory::class)->forCampaign($campaign);

        return $campaign->fresh('wallet');
    }

    /**
     * A claimant's address, a real base address on the test network, different for each $n.
     */
    protected function claimant(int $n): string
    {
        $payment = SigningKey::fromSeed(str_pad("claimant-{$n}", 32, '.'))->credential();
        $stake = Credential::keyHash(str_pad(dechex($n), 56, '0', STR_PAD_LEFT));

        return BaseAddress::of(Network::Testnet, $payment, $stake)->toBech32();
    }

    protected function claim(Campaign $campaign, int $n, int $lovelace = 2_000_000, array $claim = []): Claim
    {
        $code = Code::factory()->create(['campaign_id' => $campaign->id, 'lovelace' => $lovelace]);

        return Claim::factory()->create($claim + [
            'code_id' => $code->id,
            'address' => $this->claimant($n),
            'transaction_id' => null,
            'transaction_hash' => null,
        ]);
    }

    /**
     * A recipient in the shape ProcessClaims hands the backend.
     */
    protected function recipient(Claim $claim, array $tokens = []): array
    {
        return [
            'claimId' => $claim->id,
            'pooCode' => $claim->code->code,
            'address' => $claim->address,
            'lovelace' => $claim->code->lovelace,
            'tokens' => $tokens,
        ];
    }

    protected function backend(): NativeBackend
    {
        return app(NativeBackend::class);
    }
}

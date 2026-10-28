<?php

namespace Tests\Feature;

use App\Cardano\CampaignKeyVault;
use App\Cardano\CampaignScript;
use App\Cardano\NativeWalletFactory;
use App\Models\Campaign;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * What must not become readable while this work is paused.
 *
 * Two things in the transaction layer cannot be withdrawn once done. The first is persistence:
 * from the first secret written, backups carry key material for their whole retention period. The
 * second is an address becoming observable, and it is the one this file is about. Funds can arrive
 * at an address the moment anybody can read it, whether or not the code that was going to look
 * after them was finished, and nothing can be done afterwards to make that not have happened.
 *
 * So the flag has to do more than stop a feature being used. While it is off, no campaign address
 * may exist anywhere a person or a system could pick one up. A test that only checked the happy
 * path would pass against an implementation that derived the address, declined to use it, and
 * wrote it to a log on the way.
 */
class NativeWalletDisclosureTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER_ONE = '00000000000000000000000000000000000000000000000000000001';

    private const OWNER_TWO = '00000000000000000000000000000000000000000000000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'campaign_script.owner_key_hashes' => [self::OWNER_ONE, self::OWNER_TWO],
            'campaign_script.wrapping_key_version' => 1,
            'campaign_script.wrapping_keys' => [1 => base64_encode(random_bytes(32))],
            'campaign_script.stake_addresses.preprod' => 'stake_test1uqehkck0lajq8gr28t9uxnuvgcqrc6070x3k9r8048z8y5gssrtvn',
        ]);
    }

    private function campaign(): Campaign
    {
        return Campaign::factory()->create([
            'network' => 'preprod',
            'expiry_slot' => 140000000,
            'funding_path' => CampaignScript::FUNDING_CREDIT,
        ]);
    }

    /**
     * With a required setting missing, asking for a wallet writes nothing and says nothing.
     *
     * Every log channel is captured, not only the one the code under test happens to use, because
     * the question is whether an address reached a log rather than whether it reached a particular
     * one.
     */
    public function test_nothing_about_an_address_is_written_or_logged_while_a_setting_is_missing(): void
    {
        config(['campaign_script.wrapping_keys' => []]);

        $campaign = $this->campaign();
        $lines = [];

        Log::listen(function ($message) use (&$lines) {
            $lines[] = $message->message.' '.json_encode($message->context);
        });

        try {
            (new NativeWalletFactory(new CampaignKeyVault))->forCampaign($campaign);
            $this->fail('A wallet was created with a setting missing.');
        } catch (RuntimeException $e) {
            // The refusal itself must not name an address either, which is the subtler half: an
            // exception message reaches a log, an error tracker and sometimes a response body.
            // A bech32 address, not the word. The refusal is allowed to say "address"; what it
            // must not do is contain one.
            $this->assertDoesNotMatchRegularExpression('/\baddr(_test)?1[0-9a-z]{20,}/', $e->getMessage());
        }

        $this->assertSame(0, Wallet::where('campaign_id', $campaign->id)->count());

        foreach ($lines as $line) {
            $this->assertStringNotContainsString('addr_test1', $line);
            $this->assertStringNotContainsString('addr1', $line);
        }
    }

    /**
     * The creation job does not put an address in a log even when the feature is on.
     *
     * An address in a log line is an address in whatever aggregates those logs, readable by
     * whoever can read that, for as long as it is retained. The campaign id identifies the
     * campaign for anybody entitled to look, without carrying the address to everybody who is not.
     */
    public function test_the_creation_job_logs_no_address(): void
    {
        $source = file_get_contents(base_path('app/Jobs/CreateCampaignBucket.php'));

        $logCall = substr($source, strpos($source, "Log::info('CreateCampaignBucket: wallet created."));
        $logCall = substr($logCall, 0, strpos($logCall, ']);'));

        $this->assertStringNotContainsString(
            'address',
            $logCall,
            'The wallet creation log carries an address, so every campaign address reaches the log store.'
        );
    }

    /**
     * A wallet does not carry its key material into anything that renders one.
     *
     * An Inertia prop is the surface that matters here: a campaign page serialises models, and a
     * field that is not hidden reaches the browser of anybody who can open the page.
     */
    public function test_key_material_does_not_reach_a_rendered_wallet(): void
    {
        $wallet = (new NativeWalletFactory(new CampaignKeyVault))->forCampaign($this->campaign());
        $rendered = json_encode($wallet->toArray());

        $this->assertStringNotContainsString($wallet->skey, $rendered);
        $this->assertStringNotContainsString($wallet->vkey, $rendered);

        foreach (['skey', 'vkey', 'wrapping_key_version', 'recovery_record'] as $field) {
            $this->assertStringNotContainsString($field, $rendered);
        }
    }

    /**
     * A queued job carrying a wallet does not carry its secret into the queue.
     *
     * A failed job is stored with its payload, so anything serialised into one outlives the run
     * that made it and sits in a table that is not treated as key storage.
     */
    public function test_a_serialised_job_payload_carries_no_secret(): void
    {
        $wallet = (new NativeWalletFactory(new CampaignKeyVault))->forCampaign($this->campaign());

        // Through the trait the queue actually uses, not through raw serialisation. The two give
        // very different answers: PHP's own serialiser writes every attribute, hidden or not,
        // while the trait replaces a model with its class and key and reloads it on the far side.
        // Testing the first would report a leak that no queued job has, and testing neither would
        // have missed it if the trait ever stopped being used.
        $payload = serialize(new WalletCarryingJob($wallet));

        $this->assertStringNotContainsString(
            $wallet->skey,
            $payload,
            'A queued job holding a wallet carries its encrypted secret, so a failed job stores it in a table '
            .'nobody treats as key storage.'
        );
        $this->assertStringNotContainsString($wallet->address, $payload);
    }
}

/**
 * A job shaped the way the queue's jobs are shaped, holding a wallet.
 *
 * Named rather than anonymous because PHP will not serialise an anonymous class, which is the
 * whole operation under test here.
 */
class WalletCarryingJob
{
    use \Illuminate\Queue\SerializesModels;

    public function __construct(public Wallet $wallet) {}
}

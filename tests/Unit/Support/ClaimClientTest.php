<?php

namespace Tests\Unit\Support;

use App\Support\ClaimClient;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClaimClientTest extends TestCase
{
    /**
     * The four strings below were captured from a live mainnet claim on 8 September 2026,
     * one per wallet, and are the whole evidence base for the rules. They are reproduced
     * verbatim so that a rule change has to be made against what wallets actually send.
     */
    public static function realWalletStrings(): array
    {
        return [
            'Lace' => ['okhttp/4.12.0', 'okhttp', 'lace'],
            'Eternl' => [
                'Dalvik/2.1.0 (Linux; U; Android 17; Pixel 7 Pro Build/BP3A.250905.014)',
                'dalvik',
                'eternl',
            ],
            'VESPR' => ['axios/1.19.0', 'axios', 'vespr'],
            'Begin' => [
                'Mozilla/5.0 (Linux; Android 17; Pixel 7 Pro Build/BP3A.250905.014; wv) '
                .'AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/151.0.7922.199 '
                .'Mobile Safari/537.36',
                'webview',
                'begin',
            ],
        ];
    }

    #[DataProvider('realWalletStrings')]
    public function test_it_classifies_the_wallets_that_were_tested(string $userAgent, string $client, string $wallet): void
    {
        $classified = ClaimClient::from($userAgent);

        $this->assertSame($client, $classified->client);
        $this->assertSame($wallet, $classified->wallet);
    }

    public function test_a_request_with_no_user_agent_is_absent_rather_than_unknown(): void
    {
        foreach ([null, '', '   '] as $empty) {
            $classified = ClaimClient::from($empty);

            $this->assertSame(ClaimClient::ABSENT, $classified->client);
            $this->assertNull($classified->wallet);
            $this->assertNull($classified->userAgent);
            $this->assertFalse($classified->hasUserAgent());
            $this->assertNull($classified->fingerprint());
        }
    }

    public function test_an_unrecognised_client_keeps_its_string_so_it_can_be_identified_later(): void
    {
        $classified = ClaimClient::from('SomeNewWallet/2.0');

        $this->assertSame(ClaimClient::UNKNOWN, $classified->client);
        $this->assertNull($classified->wallet);
        $this->assertSame('SomeNewWallet/2.0', $classified->userAgent);
        $this->assertTrue($classified->hasUserAgent());
    }

    /**
     * The rule that has to survive a wallet update. Begin's Chrome build moved from 140 to
     * 151 between two tests six weeks apart, so anything keyed to a version would already
     * have broken once.
     */
    public function test_the_webview_rule_does_not_depend_on_a_browser_version(): void
    {
        $older = ClaimClient::from(
            'Mozilla/5.0 (Linux; Android 15; wv) AppleWebKit/537.36 Chrome/140.0.0.0 Mobile Safari/537.36'
        );
        $newer = ClaimClient::from(
            'Mozilla/5.0 (Linux; Android 17; wv) AppleWebKit/537.36 Chrome/151.0.7922.199 Mobile Safari/537.36'
        );

        $this->assertSame('webview', $older->client);
        $this->assertSame('webview', $newer->client);
    }

    public function test_an_ordinary_mobile_browser_is_not_read_as_a_webview(): void
    {
        $classified = ClaimClient::from(
            'Mozilla/5.0 (Linux; Android 17; Pixel 7 Pro) AppleWebKit/537.36 (KHTML, like Gecko) '
            .'Chrome/151.0.7922.199 Mobile Safari/537.36'
        );

        $this->assertSame(ClaimClient::UNKNOWN, $classified->client);
        $this->assertNull($classified->wallet);
    }

    public function test_a_long_user_agent_is_truncated_before_it_is_stored_or_hashed(): void
    {
        $classified = ClaimClient::from('okhttp/4.12.0 '.str_repeat('A', 5000));

        $this->assertSame(ClaimClient::MAX_LENGTH, mb_strlen($classified->userAgent));
        $this->assertSame('okhttp', $classified->client);
        $this->assertSame(hash('sha256', $classified->userAgent), $classified->fingerprint());
    }

    public function test_the_fingerprint_is_determined_by_the_string_and_nothing_else(): void
    {
        $first = ClaimClient::from('okhttp/4.12.0');
        $second = ClaimClient::from('okhttp/4.12.0');
        $other = ClaimClient::from('okhttp/5.0.0');

        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertNotSame($first->fingerprint(), $other->fingerprint());
    }

    /**
     * The display mapping is read from the same rules the classifier matches on, so this
     * asserts the pairing rather than a second copy of it.
     */
    public function test_a_stored_shorthand_maps_back_to_a_wallet_name(): void
    {
        $this->assertSame('Lace', ClaimClient::walletFor('okhttp'));
        $this->assertSame('Eternl', ClaimClient::walletFor('dalvik'));
        $this->assertSame('VESPR', ClaimClient::walletFor('axios'));
        $this->assertSame('Begin', ClaimClient::walletFor('webview'));
    }

    public function test_a_shorthand_that_names_no_wallet_maps_to_null(): void
    {
        $this->assertNull(ClaimClient::walletFor(ClaimClient::UNKNOWN));
        $this->assertNull(ClaimClient::walletFor(ClaimClient::ABSENT));
        $this->assertNull(ClaimClient::walletFor('something-that-was-never-a-rule'));
    }
}

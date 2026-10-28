<?php

namespace Tests\Unit\Cardano;

use App\Cardano\CampaignKeyVault;
use RuntimeException;
use Tests\TestCase;

/**
 * What has to remain true of a campaign's secret while the application around it changes.
 */
class CampaignKeyVaultTest extends TestCase
{
    private const KEY_ONE = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private const KEY_TWO = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'campaign_script.wrapping_key_version' => 1,
            'campaign_script.wrapping_keys' => [1 => self::KEY_ONE],
        ]);
    }

    private function vault(): CampaignKeyVault
    {
        return new CampaignKeyVault;
    }

    public function test_a_secret_comes_back_as_it_went_in(): void
    {
        $secret = random_bytes(32);

        $wrapped = $this->vault()->wrap($secret);

        $this->assertSame(1, $wrapped['version']);
        $this->assertStringNotContainsString($secret, $wrapped['ciphertext']);
        $this->assertSame($secret, $this->vault()->unwrap($wrapped['ciphertext'], $wrapped['version']));
    }

    /**
     * Two wrappings of one secret do not look alike.
     *
     * A construction that produced the same ciphertext for the same input would leak that two
     * campaigns share a key, which is the one thing about these secrets that should never be
     * inferable from the database.
     */
    public function test_wrapping_the_same_secret_twice_gives_different_ciphertext(): void
    {
        $secret = random_bytes(32);
        $vault = $this->vault();

        $this->assertNotSame(
            $vault->wrap($secret)['ciphertext'],
            $vault->wrap($secret)['ciphertext'],
        );
    }

    /**
     * THE POINT OF THIS CLASS EXISTING.
     *
     * Rotating the application key is a documented one-command operation, and the framework's own
     * guidance is to do it if that key is ever exposed. Under the encrypted cast every campaign
     * secret would become unreadable at that moment, which would leave every campaign's funds
     * unreachable by the platform branch of its script. This asserts the secret survives.
     */
    public function test_rotating_the_application_key_does_not_destroy_a_campaign_secret(): void
    {
        $secret = random_bytes(32);
        $wrapped = $this->vault()->wrap($secret);

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertSame(
            $secret,
            $this->vault()->unwrap($wrapped['ciphertext'], $wrapped['version']),
            'A campaign secret stopped being readable when the application key changed, so rotating that key '
            .'would strand every campaign.'
        );
    }

    /**
     * A secret written before a rotation still reads after one.
     *
     * This is what lets a rotation be done row by row instead of all at once, and it is why the
     * version is stored beside the ciphertext rather than inferred from configuration.
     */
    public function test_a_secret_written_under_an_older_wrapping_key_still_reads(): void
    {
        $secret = random_bytes(32);
        $old = $this->vault()->wrap($secret);

        // The rotation: the new key is added and becomes current, the old one stays available.
        config([
            'campaign_script.wrapping_key_version' => 2,
            'campaign_script.wrapping_keys' => [1 => self::KEY_ONE, 2 => self::KEY_TWO],
        ]);

        $this->assertSame(1, $old['version']);
        $this->assertSame($secret, $this->vault()->unwrap($old['ciphertext'], $old['version']));

        // And a secret written now goes under the new one.
        $new = $this->vault()->wrap($secret);
        $this->assertSame(2, $new['version']);
        $this->assertNotSame($old['ciphertext'], $new['ciphertext']);
    }

    public function test_rewrapping_moves_a_secret_to_the_current_key_without_changing_it(): void
    {
        $secret = random_bytes(32);
        $old = $this->vault()->wrap($secret);

        config([
            'campaign_script.wrapping_key_version' => 2,
            'campaign_script.wrapping_keys' => [1 => self::KEY_ONE, 2 => self::KEY_TWO],
        ]);

        $moved = $this->vault()->rewrap($old['ciphertext'], $old['version']);

        $this->assertSame(2, $moved['version']);
        $this->assertSame($secret, $this->vault()->unwrap($moved['ciphertext'], $moved['version']));
    }

    /**
     * An altered ciphertext fails to open rather than opening to something else.
     *
     * The plaintext here is a signing key. A construction without authentication would hand back a
     * corrupted one, which signs, produces witnesses that verify against nothing, and fails at the
     * node with no indication of where the problem began.
     */
    public function test_an_altered_secret_is_refused_rather_than_returned_wrong(): void
    {
        $wrapped = $this->vault()->wrap(random_bytes(32));

        [$envelope, $nonce, $box] = explode(':', $wrapped['ciphertext'], 3);
        $raw = base64_decode($box, true);
        $raw[0] = $raw[0] === "\x00" ? "\x01" : "\x00";
        $tampered = implode(':', [$envelope, $nonce, base64_encode($raw)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not open');

        $this->vault()->unwrap($tampered, $wrapped['version']);
    }

    /**
     * Reading under the wrong key fails loudly.
     */
    public function test_a_secret_read_under_the_wrong_wrapping_key_is_refused(): void
    {
        $wrapped = $this->vault()->wrap(random_bytes(32));

        config(['campaign_script.wrapping_keys' => [1 => self::KEY_TWO]]);

        $this->expectException(RuntimeException::class);

        $this->vault()->unwrap($wrapped['ciphertext'], 1);
    }

    /**
     * A wrapping key of the wrong length is refused rather than made to fit.
     *
     * Padding or hashing a short key into shape would work, produce ciphertext, and leave the
     * deployment with less protection than it believes it has, with nothing to notice it by.
     */
    public function test_a_wrapping_key_of_the_wrong_length_is_refused(): void
    {
        config(['campaign_script.wrapping_keys' => [1 => base64_encode('too short')]]);

        $this->assertFalse($this->vault()->isConfigured());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('base64-encoded bytes');

        $this->vault()->wrap(random_bytes(32));
    }

    /**
     * A deployment with no wrapping key says so before a campaign is created, not during.
     */
    public function test_a_deployment_without_a_wrapping_key_reports_it_before_anything_is_created(): void
    {
        config(['campaign_script.wrapping_keys' => []]);

        $this->assertFalse($this->vault()->isConfigured());
    }

    /**
     * Removing an old wrapping key before every row has moved is the permanent mistake.
     */
    public function test_removing_a_wrapping_key_still_in_use_is_refused_with_a_message_saying_why(): void
    {
        $wrapped = $this->vault()->wrap(random_bytes(32));

        config([
            'campaign_script.wrapping_key_version' => 2,
            'campaign_script.wrapping_keys' => [2 => self::KEY_TWO],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('re-wrapped');

        $this->vault()->unwrap($wrapped['ciphertext'], $wrapped['version']);
    }
}

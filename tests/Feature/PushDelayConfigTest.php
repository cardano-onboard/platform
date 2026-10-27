<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The batching window before claims are sent, now in seconds.
 *
 * POO_PUSH_DELAY_SECONDS is read first, and a blank value counts as unset. POO_PUSH_DELAY, in
 * whole minutes, is the setting a deployment may already carry, and whenever it is present it
 * keeps meaning exactly what it meant, blank or 0 included, until the new one is set beside
 * it. The 30-second default is for a deployment that carries neither.
 */
class PushDelayConfigTest extends TestCase
{
    private const SECONDS = 'POO_PUSH_DELAY_SECONDS';

    private const MINUTES = 'POO_PUSH_DELAY';

    public function test_the_default_is_thirty_seconds_when_neither_is_present(): void
    {
        $this->assertSame(30, $this->delay([self::SECONDS => null, self::MINUTES => null]));
    }

    /**
     * A blank POO_PUSH_DELAY has always meant no wait, and a deployment carrying one keeps
     * that. It is present, so the 30-second default does not apply.
     */
    public function test_a_blank_minutes_setting_still_means_no_wait(): void
    {
        $this->assertSame(0, $this->delay([self::SECONDS => null, self::MINUTES => '']));
        $this->assertSame(0, $this->delay([self::SECONDS => '', self::MINUTES => '']));
    }

    public function test_a_zero_minutes_setting_still_means_no_wait(): void
    {
        $this->assertSame(0, $this->delay([self::SECONDS => null, self::MINUTES => '0']));
        $this->assertSame(0, $this->delay([self::SECONDS => '', self::MINUTES => '0']));
    }

    public function test_a_set_minutes_setting_is_read_in_minutes(): void
    {
        $this->assertSame(300, $this->delay([self::SECONDS => null, self::MINUTES => '5']));
        $this->assertSame(120, $this->delay([self::SECONDS => '', self::MINUTES => '2']));
    }

    public function test_a_blank_seconds_setting_with_no_minutes_setting_takes_the_default(): void
    {
        $this->assertSame(30, $this->delay([self::SECONDS => '', self::MINUTES => null]));
    }

    public function test_the_seconds_setting_is_honoured(): void
    {
        $this->assertSame(20, $this->delay([self::SECONDS => '20', self::MINUTES => null]));
    }

    public function test_the_seconds_setting_wins_over_any_minutes_setting(): void
    {
        $this->assertSame(45, $this->delay([self::SECONDS => '45', self::MINUTES => '5']));
        $this->assertSame(45, $this->delay([self::SECONDS => '45', self::MINUTES => '']));
        $this->assertSame(45, $this->delay([self::SECONDS => '45', self::MINUTES => '0']));
    }

    public function test_the_delay_stays_within_what_a_queue_accepts(): void
    {
        $this->assertSame(900, $this->delay([self::SECONDS => '5000', self::MINUTES => null]));
        $this->assertSame(900, $this->delay([self::SECONDS => null, self::MINUTES => '60']));
        $this->assertSame(0, $this->delay([self::SECONDS => '-5', self::MINUTES => null]));
    }

    /**
     * A fresh self-hosted copy of the example environment keeps sending claims without
     * waiting, as it always has: it carries both names, and both blank.
     */
    public function test_the_example_environment_sends_claims_without_waiting(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $values = [];
        foreach ([self::SECONDS, self::MINUTES] as $name) {
            $this->assertSame(1, preg_match('/^'.$name.'=(.*)$/m', $example, $match), ".env.example does not carry $name");
            $values[$name] = trim($match[1]);
        }

        $this->assertSame(0, $this->delay($values));
    }

    /**
     * Read fresh from the file, because config() holds what was resolved at boot.
     *
     * @param  array<string, string|null>  $vars  null force-unsets a name for the duration
     */
    private function delay(array $vars): int
    {
        $restore = [];

        foreach ($vars as $name => $value) {
            $restore[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];

            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
                putenv("$name=$value");
            }
        }

        try {
            return (require config_path('cardano.php'))['push_delay_seconds'];
        } finally {
            foreach ($restore as $name => [$server, $env, $put]) {
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }

                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }

                putenv($put === false ? $name : "$name=$put");
            }
        }
    }
}

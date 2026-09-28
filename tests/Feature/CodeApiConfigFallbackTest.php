<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The code API's four settings each read a CODE_API_* variable first and fall back to the
 * PASSPORT_API_* name they were called before, so a deployment whose env file still sets
 * the old name keeps working exactly as it did until it is updated.
 *
 * Both directions have to hold, not just one: the new name has to be honoured when it is
 * set, and the old name has to still be honoured when the new one is not — and it has to
 * be the new one that wins when a deployment somehow has both.
 */
class CodeApiConfigFallbackTest extends TestCase
{
    /**
     * Every setting the code API resolves this way, keyed by its config sub-key, paired
     * with its current env name, its earlier one, and the default when neither is set.
     * Without this list, a setting dropped from the fallback in future would pass every
     * assertion below by simply not being checked.
     */
    private const SETTINGS = [
        'create_rate_per_token' => ['CODE_API_CREATE_RATE_PER_TOKEN', 'PASSPORT_API_CREATE_RATE_PER_TOKEN', 60],
        'status_rate_per_token' => ['CODE_API_STATUS_RATE_PER_TOKEN', 'PASSPORT_API_STATUS_RATE_PER_TOKEN', 60],
        'max_tokens_per_code' => ['CODE_API_MAX_TOKENS_PER_CODE', 'PASSPORT_API_MAX_TOKENS_PER_CODE', 20],
        'max_token_quantity' => ['CODE_API_MAX_TOKEN_QUANTITY', 'PASSPORT_API_MAX_TOKEN_QUANTITY', 1_000_000],
    ];

    public function test_the_new_name_is_honoured_when_set(): void
    {
        foreach (self::SETTINGS as $key => [$new, $old, $default]) {
            $value = $default + 7;

            $codeApi = $this->withEnv([$new => (string) $value, $old => null], fn () => $this->freshCodeApiConfig());

            $this->assertSame($value, $codeApi[$key], "$new is not read for cardano.code_api.$key");
        }
    }

    public function test_the_earlier_name_is_still_honoured_when_the_new_one_is_not_set(): void
    {
        foreach (self::SETTINGS as $key => [$new, $old, $default]) {
            $value = $default + 11;

            $codeApi = $this->withEnv([$new => null, $old => (string) $value], fn () => $this->freshCodeApiConfig());

            $this->assertSame(
                $value,
                $codeApi[$key],
                "the earlier name $old is no longer read for cardano.code_api.$key, which breaks a deployment whose env file has not been updated",
            );
        }
    }

    public function test_the_new_name_wins_when_a_deployment_has_both(): void
    {
        foreach (self::SETTINGS as $key => [$new, $old, $default]) {
            $newValue = $default + 3;
            $oldValue = $default + 99;

            $codeApi = $this->withEnv(
                [$new => (string) $newValue, $old => (string) $oldValue],
                fn () => $this->freshCodeApiConfig(),
            );

            $this->assertSame($newValue, $codeApi[$key], "$new should take priority over $old for cardano.code_api.$key");
        }
    }

    /**
     * A blank value is not a set value. A deployment's env file can carry
     * CODE_API_CREATE_RATE_PER_TOKEN= with nothing after the equals sign — a template line
     * nobody has filled in yet, or a value cleared while editing — and that has to fall
     * through to the earlier name exactly as an absent variable does. (int) '' is 0, so a
     * fallback keyed only on "is this env var set at all" would turn a blank line into a
     * rate limit of zero, refusing every request instead of reading the deployment's actual
     * setting from the name it is still under.
     */
    public function test_a_blank_new_name_still_falls_through_to_the_earlier_name(): void
    {
        foreach (self::SETTINGS as $key => [$new, $old, $default]) {
            $value = $default + 13;

            $codeApi = $this->withEnv([$new => '', $old => (string) $value], fn () => $this->freshCodeApiConfig());

            $this->assertSame(
                $value,
                $codeApi[$key],
                "a blank $new is not treated as absent for cardano.code_api.$key, so it never falls through to $old",
            );
        }
    }

    public function test_neither_name_set_falls_back_to_the_documented_default(): void
    {
        foreach (self::SETTINGS as $key => [$new, $old, $default]) {
            $codeApi = $this->withEnv([$new => null, $old => null], fn () => $this->freshCodeApiConfig());

            $this->assertSame($default, $codeApi[$key], "cardano.code_api.$key no longer defaults to $default when neither $new nor $old is set");
        }
    }

    /**
     * cardano.php's code_api block, read fresh from the file rather than config(), which
     * holds whatever was resolved when the application booted and cannot be asked the
     * question again once the environment underneath it has changed.
     */
    private function freshCodeApiConfig(): array
    {
        return (require config_path('cardano.php'))['code_api'];
    }

    /**
     * Runs $callback with $vars set across every layer env() can read from, restoring
     * whatever was there before even if the callback throws. A null value force-unsets a
     * name for the duration.
     *
     * All three of $_SERVER, $_ENV and putenv(), because this test's .env already sets a
     * CODE_API_* name to its own default: Laravel's dotenv loader writes a variable it
     * reads with no override already in place through putenv() as well as the two
     * superglobals, so clearing only $_SERVER and $_ENV leaves env() falling through to a
     * putenv() value this test never set and thinks it has unset a name it has not.
     */
    private function withEnv(array $vars, callable $callback): mixed
    {
        $restore = [];

        foreach ($vars as $name => $value) {
            $restore[$name] = [
                '_SERVER' => $_SERVER[$name] ?? null,
                '_ENV' => $_ENV[$name] ?? null,
                'putenv' => getenv($name),
            ];

            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $value;
                $_ENV[$name] = $value;
                putenv("$name=$value");
            }
        }

        try {
            return $callback();
        } finally {
            foreach ($restore as $name => $previous) {
                if ($previous['_SERVER'] === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $previous['_SERVER'];
                }

                if ($previous['_ENV'] === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $previous['_ENV'];
                }

                putenv($previous['putenv'] === false ? $name : "$name={$previous['putenv']}");
            }
        }
    }
}

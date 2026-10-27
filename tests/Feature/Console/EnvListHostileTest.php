<?php

namespace Tests\Feature\Console;

use App\Support\EnvInventory;
use App\Support\EnvTypes;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The listing and the check, run as a real process against an environment in which every variable
 * the application reads holds something hostile.
 *
 * Every variable, secret-named or not, typed or not, is set to a value that is a credential or
 * that contains one: a short human password, an address whose password holds a slash, a question
 * mark and a hash, a signed token, an application key, a recovery phrase, an identifier a service
 * issued. Nothing in this environment is a value anyone would want printed, so any six
 * characters of any of them in any output are a leak, whatever mode produced it and whether or
 * not the configuration is cached.
 *
 * A process rather than a changed config repository, because that is how the values reach the
 * application in a deployment: as environment variables, read when the configuration is built
 * or cached.
 */
class EnvListHostileTest extends TestCase
{
    /** The length of the run of characters of a planted value that may not appear in any output. */
    private const WINDOW = 6;

    /**
     * Variables the framework resolves while it boots, which are given a valid value instead of a
     * hostile one: the cache store, which it opens at boot, and the application address, which it
     * builds the console's request from. It stops with its own error on a bad value of these, and
     * for some it prints the value: that error is not this command's to control, and is the output
     * the documentation warns an operator not to paste. What the listing and the check do with a hostile value of such a variable is covered
     * with the configuration set directly, in EnvListTest.
     *
     * @var array<string, string>
     */
    private const VALID_AT_BOOT = ['CACHE_DRIVER' => 'array', 'APP_URL' => 'https://app.example.test'];

    /**
     * One hostile value for every variable the configuration reads, each with the parts of it that
     * are secret. A part shorter than the window cannot be searched for, so each is longer.
     *
     * A variable that has a type gets the hostile value that is closest to what its type allows,
     * which is the one most likely to get through: an address with credentials in it for an
     * address, a host name with a key for a label for a host, a list with a key in it for a list.
     * A variable that has no type gets a value that would pass for a short name, which is shown
     * only if something shows a value it was never told was safe. Nothing planted in a typed
     * variable is a value its type allows, since that would rightly be shown.
     *
     * @return array<string, array{value: string, secrets: list<string>}>
     */
    private function planted(): array
    {
        mt_srand(20261001);

        $random = function (int $length, string $alphabet): string {
            $text = '';

            for ($i = 0; $i < $length; $i++) {
                $text .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }

            return $text;
        };

        $alnum = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $words = ['legal', 'winner', 'thank', 'year', 'wave', 'sausage', 'worth', 'useful', 'abandon', 'ability', 'absorb', 'abstract'];

        $types = [];

        foreach (EnvInventory::reads() as $read) {
            $types[$read['name']] ??= EnvTypes::of($read['name'], $read['default']);
        }

        $names = array_keys($types);
        sort($names);

        $planted = [];

        foreach ($names as $i => $name) {
            $n = sprintf('%03d', $i);
            $type = $types[$name];

            // The kind of hostile value: by the type where there is one, otherwise by position.
            $kind = match (true) {
                $type === 'url' => 1,
                in_array($type, ['host', 'hostport', 'cookiehost'], true) => 12,
                $type !== null && str_starts_with($type, 'list:') => 13,
                $type === 'id' => 11,
                in_array($type, ['int', 'float', 'port', 'bool'], true) => 9,
                $type !== null && str_starts_with($type, 'enum:') => 0,
                $type === 'email' => 0,
                default => [0, 2, 3, 4, 5, 6, 7, 8, 10, 14, 1][$i % 11],
            };

            $planted[$name] = match ($kind) {
                0 => ['value' => "Sp4rrow!{$n}", 'secrets' => ["Sp4rrow!{$n}"]],
                1 => (function () use ($n, $random, $alnum, $i) {
                    $user = 'svcuser'.$n;
                    $password = "Pa/ss?w#rd{$n}Zq";
                    $path = 'Tk9'.$random(10, $alnum);
                    $query = 'Wv7'.$random(10, $alnum);
                    $short = 'x'.$random(5, $alnum);
                    $fragment = "frag{$n}Zz";

                    // A third carry a query, a third a fragment with nothing before it, and a third
                    // a key as a label of the host name, which an address is judged strictly for.
                    if ($i % 3 === 0) {
                        return [
                            'value' => "https://{$user}:{$password}@host-{$n}.example.test:8443/api/{$path}?token={$query}&t={$short}&pw=ab{$n}&{$short}Zq9",
                            'secrets' => [$user, $password, $path, $query, $short],
                        ];
                    }

                    if ($i % 3 === 1) {
                        return [
                            'value' => "https://{$user}:{$password}@host-{$n}.example.test:8443/api/{$path}#{$fragment}",
                            'secrets' => [$user, $password, $path, $fragment],
                        ];
                    }

                    $label = $random(14, 'abcdefghijklmnopqrstuvwxyz0123456789').'k9';

                    return [
                        'value' => "https://{$label}.ingest.example.test/collect",
                        'secrets' => [$label],
                    ];
                })(),
                2 => (function () use ($random, $alnum) {
                    $jwt = 'eyJ'.$random(30, $alnum).'.eyJ'.$random(40, $alnum).'.'.$random(43, $alnum);

                    return ['value' => $jwt, 'secrets' => [$jwt]];
                })(),
                3 => (function () use ($random, $alnum) {
                    $blob = $random(40, $alnum.'+/');

                    return ['value' => $blob, 'secrets' => [$blob]];
                })(),
                4 => (function () use ($random) {
                    $uuid = sprintf('%s-%s-%s-%s-%s', $random(8, '0123456789abcdef'), $random(4, '0123456789abcdef'), $random(4, '0123456789abcdef'), $random(4, '0123456789abcdef'), $random(12, '0123456789abcdef'));

                    return ['value' => $uuid, 'secrets' => [$uuid]];
                })(),
                5 => (function () use ($random) {
                    $hex = $random(32, '0123456789abcdef');

                    return ['value' => $hex, 'secrets' => [$hex]];
                })(),
                6 => (function () use ($words) {
                    shuffle($words);
                    $phrase = implode(',', array_map('ucfirst', $words));

                    return ['value' => $phrase, 'secrets' => [$phrase]];
                })(),
                7 => (function () use ($random, $alnum) {
                    $token = '7|'.$random(40, $alnum);

                    return ['value' => $token, 'secrets' => [$token]];
                })(),
                8 => (function () use ($random) {
                    $key = 'key-'.$random(32, '0123456789abcdef');

                    return ['value' => $key, 'secrets' => [$key]];
                })(),
                9 => (function () use ($random, $alnum) {
                    $short = 'Zq4!'.$random(4, $alnum);

                    return ['value' => $short, 'secrets' => [$short]];
                })(),
                10 => (function () use ($random, $alnum) {
                    $jwt = 'eyJ'.$random(30, $alnum).'.eyJ'.$random(40, $alnum).'.'.$random(43, $alnum);

                    return ['value' => '"'.$jwt.'"', 'secrets' => [$jwt]];
                })(),
                11 => (function () use ($random) {
                    // Made of the characters of a short name, so only the shape checks stand
                    // between this and the output.
                    $hex = $random(24, '0123456789abcdef');

                    return ['value' => $hex, 'secrets' => [$hex]];
                })(),
                12 => (function () use ($random) {
                    // A key as a label of a host name. A host setting is judged loosely, since the
                    // names a managed service gives out are long and carry digits, so the label is
                    // secret-shaped (hexadecimal, as a key is) rather than a plain lower case word.
                    $label = $random(24, '0123456789abcdef');

                    return ['value' => "{$label}.example.test", 'secrets' => [$label]];
                })(),
                13 => (function () use ($random, $alnum) {
                    $key = $random(20, $alnum);

                    return ['value' => "localhost,{$key},127.0.0.1", 'secrets' => [$key]];
                })(),
                default => (function () use ($random, $n) {
                    // Lower case letters and digits, which is what a short name looks like.
                    $word = 'sparrowpw'.$n.$random(6, 'abcdefghijklmnopqrstuvwxyz');

                    return ['value' => $word, 'secrets' => [$word]];
                })(),
            };
        }

        return $planted;
    }

    /**
     * Runs one artisan command in a process whose environment is the planted one.
     *
     * @param  array<string, string>  $env
     * @param  list<string>  $arguments
     */
    private function runArtisan(array $env, array $arguments): string
    {
        $process = new Process([PHP_BINARY, base_path('artisan'), ...$arguments, '--no-ansi'], base_path(), $env, null, 120);
        $process->run();

        return $process->getOutput().$process->getErrorOutput();
    }

    /**
     * Every run of $window characters of $text, as keys.
     *
     * @return array<string, true>
     */
    private function windows(string $text, int $window): array
    {
        $found = [];

        for ($i = 0, $last = strlen($text) - $window; $i <= $last; $i++) {
            $found[substr($text, $i, $window)] = true;
        }

        return $found;
    }

    /**
     * Fails if six characters of any planted secret are in the output. The variables' own names are
     * taken out of the output first, so a name that happens to share a run with a secret is not a
     * finding.
     *
     * @param  array<string, array{value: string, secrets: list<string>}>  $planted
     */
    private function assertNothingPlantedAppears(array $planted, string $mode, string $output): void
    {
        $names = array_keys($planted);
        usort($names, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $clean = str_replace($names, "\0", $output);
        $seen = $this->windows($clean, self::WINDOW);

        foreach ($planted as $name => $plant) {
            foreach ($plant['secrets'] as $secret) {
                $this->assertGreaterThanOrEqual(self::WINDOW, strlen($secret), "{$name}: a planted secret shorter than the window proves nothing");

                foreach ($this->windows($secret, self::WINDOW) as $window => $_) {
                    $this->assertArrayNotHasKey((string) $window, $seen, "{$mode}: the output holds \"{$window}\", part of what is planted in {$name}");
                }
            }
        }
    }

    /** @return array<string, string> The environment: every inventory variable hostile, nothing else changed. */
    private function environment(array $planted, string $configCache): array
    {
        $env = ['APP_CONFIG_CACHE' => $configCache, 'COLUMNS' => '110', 'NO_COLOR' => '1'];

        foreach ($planted as $name => $plant) {
            $env[$name] = $plant['value'];
        }

        return $env;
    }

    /** @return array<string, list<string>> */
    private function modes(): array
    {
        return [
            'all' => ['poo:env-check', '--all'],
            'all json' => ['poo:env-check', '--all', '--json'],
            'changed' => ['poo:env-check', '--all', '--changed'],
            'changed json' => ['poo:env-check', '--all', '--changed', '--json'],
            'grep password' => ['poo:env-check', '--all', '--grep=password'],
            'grep url json' => ['poo:env-check', '--all', '--grep=url', '--json'],
            'groups' => ['poo:env-check', '--all', '--group=database,mail,cardano,cache,session'],
            'groups json' => ['poo:env-check', '--all', '--group=database,mail,cardano,cache,session', '--json'],
            'check production' => ['poo:env-check', '--profile=production'],
            'check staging strict' => ['poo:env-check', '--profile=staging', '--strict'],
            'check self-hosted' => ['poo:env-check', '--profile=self-hosted'],
        ];
    }

    private function hostileRun(bool $cached): void
    {
        $planted = $this->planted();

        $this->assertGreaterThan(190, count($planted), 'every variable the configuration reads is planted');
        $this->assertSame([], array_diff(array_keys(self::VALID_AT_BOOT), array_keys($planted)), 'a variable that is exempt at boot is one the configuration reads');

        $exempt = array_intersect_key($planted, self::VALID_AT_BOOT);
        $planted = array_diff_key($planted, self::VALID_AT_BOOT);

        // A cache file of this run's own, which is empty unless the configuration is cached, so
        // neither run can read one a developer left in the application's own cache folder.
        $cache = sys_get_temp_dir().'/envlist-hostile-'.bin2hex(random_bytes(4)).'.php';
        $env = $this->environment($planted, $cache) + self::VALID_AT_BOOT;
        $this->assertNotSame([], $exempt);

        try {
            if ($cached) {
                $this->runArtisan($env, ['config:cache']);
                $this->assertFileExists($cache, 'the configuration was cached');
            } else {
                $this->assertFileDoesNotExist($cache);
            }

            foreach ($this->modes() as $mode => $arguments) {
                $output = $this->runArtisan($env, $arguments);

                // Not vacuous: the run produced the listing or the check it was asked for.
                if (in_array('--all', $arguments, true) && in_array('--json', $arguments, true)) {
                    $decoded = json_decode($output, true);

                    $this->assertIsArray($decoded, "{$mode}: not JSON: ".substr($output, 0, 300));
                    $this->assertNotSame([], $decoded['variables'], "{$mode}: the listing was empty");
                } elseif (in_array('--all', $arguments, true)) {
                    $this->assertStringContainsString('VARIABLE', $output, "{$mode}: no listing: ".substr($output, 0, 300));
                } else {
                    $this->assertStringContainsString('Profile ', $output, "{$mode}: no check ran: ".substr($output, 0, 300));
                }

                $this->assertNothingPlantedAppears($planted, ($cached ? 'cached ' : 'uncached ').$mode, $output);
            }
        } finally {
            if (is_file($cache)) {
                unlink($cache);
            }
        }
    }

    public function test_nothing_hostile_in_the_environment_reaches_any_output_without_a_configuration_cache(): void
    {
        $this->hostileRun(false);
    }

    public function test_nothing_hostile_in_the_environment_reaches_any_output_with_a_configuration_cache(): void
    {
        $this->hostileRun(true);
    }

    public function test_the_harness_would_notice_a_leak(): void
    {
        $planted = ['DEMO_ONE' => ['value' => 'Sp4rrow!000', 'secrets' => ['Sp4rrow!000']]];

        $this->assertNothingPlantedAppears($planted, 'clean', "Showing 3 rows\nDEMO_ONE set (hidden)");

        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->assertNothingPlantedAppears($planted, 'leaky', 'DEMO_ONE set Sp4rrow!000');
    }

    public function test_every_kind_of_planted_value_is_present_and_none_is_a_value_anyone_would_show(): void
    {
        $planted = $this->planted();
        $kinds = [];

        foreach ($planted as $name => $plant) {
            $kinds[substr($plant['value'], 0, 3)] = true;
            $this->assertNotSame('', $plant['value'], $name);
        }

        // A human password, an address with credentials, a signed token and a quoted one, an
        // identifier, a key, a phrase: more than one shape reaches each kind of variable.
        $this->assertGreaterThanOrEqual(8, count($kinds));
        $this->assertCount(count($planted), array_unique(array_column($planted, 'value')), 'every planted value is its own');
    }
}

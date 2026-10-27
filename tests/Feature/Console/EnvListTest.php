<?php

namespace Tests\Feature\Console;

use App\Support\EnvInventory;
use App\Support\EnvListing;
use App\Support\EnvMask;
use App\Support\EnvReadiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * poo:env-check --all: every variable the application reads, the value it has, the default,
 * and what changed. The listing is for a person to read and a script to parse, and in both
 * forms it must not carry a credential.
 */
class EnvListTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('COLUMNS');

        parent::tearDown();
    }

    /** The command's text output, and its exit status asserted to be a success. */
    private function listText(array $options = []): string
    {
        $this->assertSame(0, Artisan::call('poo:env-check', ['--all' => true] + $options));

        return Artisan::output();
    }

    /** The command's JSON output, decoded. */
    private function listJson(array $options = []): array
    {
        $decoded = json_decode($this->listText(['--json' => true] + $options), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** The row of one variable in one config file, from the JSON listing. */
    private function row(string $variable, ?string $group = null, array $options = [], ?string $key = null): array
    {
        $rows = array_values(array_filter(
            $this->listJson($options)['variables'],
            fn (array $row) => $row['variable'] === $variable && ($group === null || $row['group'] === $group) && ($key === null || in_array($key, $row['config_keys'], true)),
        ));

        $this->assertNotSame([], $rows, "no row for {$variable}");

        return $rows[0];
    }

    // ------------------------------------------------------------------ the flag

    public function test_without_all_the_command_checks_as_before_and_lists_nothing(): void
    {
        Artisan::call('poo:env-check', ['--profile' => 'self-hosted']);
        $output = Artisan::output();

        $this->assertStringContainsString('Profile self-hosted:', $output);
        $this->assertStringContainsString('Reason', $output);
        $this->assertStringNotContainsString('VARIABLE', $output);
        $this->assertStringNotContainsString('Showing', $output);
    }

    public function test_all_lists_and_does_not_check(): void
    {
        $output = $this->listText();

        $this->assertStringContainsString('VARIABLE', $output);
        $this->assertStringNotContainsString('Profile ', $output);
        $this->assertStringNotContainsString('Redeploy with', $output);
    }

    public function test_listing_never_fails_the_command_even_on_a_wrong_environment(): void
    {
        config(['app.debug' => true, 'app.key' => '']);

        $this->assertSame(0, Artisan::call('poo:env-check', ['--all' => true, '--profile' => 'production', '--strict' => true]));
    }

    #[DataProvider('listingOptions')]
    public function test_the_listing_options_are_refused_without_all(array $options): void
    {
        $this->assertSame(1, Artisan::call('poo:env-check', $options));
        $this->assertStringContainsString('go with --all', Artisan::output());
    }

    public static function listingOptions(): array
    {
        return [
            'grep' => [['--grep' => 'redis']],
            'changed' => [['--changed' => true]],
            'group' => [['--group' => 'app']],
            'json' => [['--json' => true]],
        ];
    }

    // ------------------------------------------------------------------ the inventory

    public function test_every_variable_the_configuration_reads_has_a_row(): void
    {
        $listed = array_unique(array_column($this->listJson()['variables'], 'variable'));
        $read = array_unique(array_column(EnvInventory::reads(), 'name'));

        $this->assertGreaterThan(150, count($read));
        $this->assertEqualsCanonicalizing($read, $listed);
    }

    public function test_a_variable_added_to_a_config_file_appears_with_no_other_change(): void
    {
        $directory = sys_get_temp_dir().'/envlist-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            file_put_contents("{$directory}/demo.php", "<?php\nreturn [\n    'concurrency' => (int) env('DEMO_CONCURRENCY', 4),\n];\n");
            config(['demo' => ['concurrency' => 9]]);

            $rows = (new EnvListing($directory))->rows();

            $this->assertCount(1, $rows);
            $this->assertSame('DEMO_CONCURRENCY', $rows[0]['variable']);
            $this->assertSame('demo.concurrency', $rows[0]['config_key']);
            $this->assertSame('9', $rows[0]['value']);
            $this->assertSame('4', $rows[0]['default']);
            $this->assertSame('set', $rows[0]['state']);
        } finally {
            array_map('unlink', glob("{$directory}/*") ?: []);
            rmdir($directory);
        }
    }

    public function test_every_row_finds_its_value_in_the_running_configuration(): void
    {
        foreach ((new EnvListing)->rows() as $row) {
            // A key under a constant exists only where the extension defining it is loaded.
            if (str_contains($row['config_key'], '::')) {
                continue;
            }

            $this->assertTrue($row['resolved'], "{$row['variable']} at {$row['config_key']} is not in the running configuration");
        }
    }

    public function test_the_values_come_from_config_so_a_changed_value_is_what_is_listed(): void
    {
        config(['cardano.claim_rate_per_ip' => 7]);

        $this->assertSame('7', $this->row('CLAIM_RATE_PER_IP')['value']);
    }

    // ------------------------------------------------------------------ state

    public function test_a_value_that_differs_from_its_default_is_set(): void
    {
        config(['cardano.claim_rate_per_ip' => 99]);

        $row = $this->row('CLAIM_RATE_PER_IP');

        $this->assertSame('set', $row['state']);
        $this->assertTrue($row['changed']);
        $this->assertSame('99', $row['value']);
        $this->assertSame('60', $row['default']);
        $this->assertSame('literal', $row['default_kind']);
    }

    public function test_a_value_equal_to_its_default_is_default(): void
    {
        config(['cardano.claim_rate_per_ip' => 60]);

        $row = $this->row('CLAIM_RATE_PER_IP');

        $this->assertSame('default', $row['state']);
        $this->assertFalse($row['changed']);
    }

    public function test_a_default_is_compared_as_the_setting_it_is_not_as_text(): void
    {
        config([
            'cardano.claim_rate_per_ip' => '60',
            'app.debug' => 'false',
            'cardano.allowed_networks' => ['preprod', 'preview', 'mainnet'],
            'database.connections.mysql.port' => 3306,
        ]);

        $this->assertSame('default', $this->row('CLAIM_RATE_PER_IP')['state']);
        $this->assertSame('default', $this->row('APP_DEBUG')['state']);
        $this->assertSame('default', $this->row('ALLOWED_NETWORKS')['state']);
        $this->assertSame('default', $this->row('DB_PORT', 'database')['state']);

        config(['cardano.allowed_networks' => ['preprod']]);
        $this->assertSame('set', $this->row('ALLOWED_NETWORKS')['state']);
        $this->assertSame('preprod', $this->row('ALLOWED_NETWORKS')['value']);
    }

    public function test_a_blank_value_is_empty_and_counts_as_changed_only_when_the_default_was_something(): void
    {
        config(['notice.type' => '', 'notice.message' => '']);

        $type = $this->row('APP_NOTICE_TYPE');
        $message = $this->row('APP_NOTICE');

        $this->assertSame('empty', $type['state']);
        $this->assertTrue($type['changed']);
        $this->assertNull($type['value']);

        $this->assertSame('empty', $message['state']);
        $this->assertFalse($message['changed']);
        $this->assertNull($message['default']);
        $this->assertSame('none', $message['default_kind']);

        // A default written as an empty string is that, not the absence of one.
        $id = $this->row('PHYRHOSE_MAINNET_ID');
        $this->assertSame('', $id['default']);
        $this->assertSame('literal', $id['default_kind']);
        $this->assertSame('', $this->row('KOIOS_API_TOKEN')['default']);
    }

    public function test_a_computed_default_cannot_be_compared_and_is_unknown(): void
    {
        $row = $this->row('SANCTUM_STATEFUL_DOMAINS');

        $this->assertSame('computed', $row['default']);
        $this->assertSame('computed', $row['default_kind']);
        $this->assertSame('unknown', $row['state']);
        $this->assertFalse($row['changed']);
        $this->assertNotNull($row['value']);
    }

    public function test_a_variable_read_only_as_an_earlier_name_is_unknown_because_the_value_may_come_from_either(): void
    {
        $earlier = array_values(array_filter(
            $this->listJson()['variables'],
            fn (array $row) => str_starts_with($row['purpose'], 'Earlier name for '),
        ));

        $this->assertGreaterThanOrEqual(4, count($earlier));

        foreach ($earlier as $row) {
            $this->assertSame('unknown', $row['state'], $row['variable']);
            $this->assertFalse($row['changed'], $row['variable']);
        }
    }

    public function test_a_value_built_from_more_than_its_variable_is_unknown_in_the_file_that_builds_it(): void
    {
        $row = $this->row('APP_URL', 'filesystems');

        $this->assertSame('unknown', $row['state']);
        $this->assertStringEndsWith('/storage', $row['value']);
        $this->assertSame('default', $this->row('APP_URL', 'app')['state']);
    }

    public function test_reads_inside_another_default_are_listed_under_the_variable_not_as_rows(): void
    {
        $app = $this->row('APP_NAME', 'app');

        $this->assertContains('cache.prefix', $app['config_keys']);
        $this->assertContains('session.cookie', $app['config_keys']);
        $this->assertNotContains('APP_NAME', array_column(array_filter($this->listJson()['variables'], fn ($row) => $row['group'] === 'session'), 'variable'));
    }

    public function test_a_key_that_is_not_in_the_running_configuration_is_unknown_and_says_so(): void
    {
        config(['cardano' => array_diff_key(config('cardano'), ['claim_rate_per_ip' => 1])]);

        $row = $this->row('CLAIM_RATE_PER_IP');

        $this->assertSame('unknown', $row['state']);
        $this->assertNull($row['value']);
        $this->assertStringContainsString('(not in config)', $this->listText(['--grep' => 'claim_rate_per_ip']));
    }

    public function test_variables_set_the_same_for_several_connections_are_one_row_listing_every_key(): void
    {
        $rows = array_values(array_filter($this->listJson()['variables'], fn ($row) => $row['variable'] === 'REDIS_HOST'));

        $this->assertCount(1, $rows);
        $this->assertSame(['database.redis.default.host', 'database.redis.cache.host'], $rows[0]['config_keys']);
        $this->assertStringContainsString('(+1 more)', $this->listText(['--grep' => 'REDIS_HOST']));
    }

    // ------------------------------------------------------------------ filters

    public function test_changed_shows_only_the_variables_that_differ_from_their_default(): void
    {
        config(['cardano.claim_rate_per_ip' => 99, 'app.debug' => true, 'cardano.claim_rate_per_campaign' => 120]);

        $all = $this->listJson();
        $changed = $this->listJson(['--changed' => true]);

        $this->assertLessThan(count($all['variables']), count($changed['variables']));
        $this->assertContains('CLAIM_RATE_PER_IP', array_column($changed['variables'], 'variable'));
        $this->assertContains('APP_DEBUG', array_column($changed['variables'], 'variable'));
        $this->assertNotContains('CLAIM_RATE_PER_CAMPAIGN', array_column($changed['variables'], 'variable'));

        foreach ($changed['variables'] as $row) {
            $this->assertTrue($row['changed'], $row['variable']);
        }

        $text = $this->listText(['--changed' => true]);
        $this->assertStringContainsString('CLAIM_RATE_PER_IP', $text);
        $this->assertStringNotContainsString('CLAIM_RATE_PER_CAMPAIGN', $text);
    }

    public function test_grep_matches_the_variable_the_config_key_the_group_and_the_purpose(): void
    {
        $byVariable = array_column($this->listJson(['--grep' => 'claim_rate_per'])['variables'], 'variable');
        $this->assertEqualsCanonicalizing(['CLAIM_RATE_PER_CAMPAIGN', 'CLAIM_RATE_PER_IP', 'CLAIM_RATE_PER_TOKEN'], $byVariable);

        $byKey = array_column($this->listJson(['--grep' => 'mailers.smtp.host'])['variables'], 'variable');
        $this->assertSame(['MAIL_HOST'], $byKey);

        $byGroup = $this->listJson(['--grep' => 'broadcasting'])['variables'];
        $this->assertNotSame([], $byGroup);
        $this->assertSame(['broadcasting'], array_unique(array_column($byGroup, 'group')));

        $byPurpose = array_column($this->listJson(['--grep' => 'administrator seeder'])['variables'], 'variable');
        $this->assertEqualsCanonicalizing(['ADMIN_EMAIL', 'ADMIN_PASSWORD'], $byPurpose);
    }

    public function test_grep_with_several_words_needs_every_one_and_ignores_case(): void
    {
        $rows = $this->listJson(['--grep' => 'ADMINISTRATOR password'])['variables'];

        $this->assertSame(['ADMIN_PASSWORD'], array_column($rows, 'variable'));
    }

    public function test_grep_never_matches_a_value(): void
    {
        config(['cardano.claim_domain' => 'distinctive-host.example.test']);

        $this->assertSame('distinctive-host.example.test', $this->row('CLAIM_DOMAIN')['value']);
        $this->assertSame([], $this->listJson(['--grep' => 'distinctive-host'])['variables']);
        $this->assertStringContainsString('No variable matches', $this->listText(['--grep' => 'distinctive-host']));
    }

    public function test_group_narrows_to_one_config_file_or_several_whatever_the_case(): void
    {
        $one = $this->listJson(['--group' => 'mail'])['variables'];
        $this->assertSame(['mail'], array_unique(array_column($one, 'group')));

        $two = $this->listJson(['--group' => 'Mail, SESSION'])['variables'];
        $this->assertEqualsCanonicalizing(['mail', 'session'], array_unique(array_column($two, 'group')));
    }

    public function test_an_unknown_group_is_refused_and_the_known_ones_are_named(): void
    {
        $this->assertSame(1, Artisan::call('poo:env-check', ['--all' => true, '--group' => 'nope']));

        $output = Artisan::output();

        $this->assertStringContainsString('No config file named "nope"', $output);
        $this->assertStringContainsString('cardano', $output);
    }

    public function test_the_filters_combine(): void
    {
        config(['cardano.claim_rate_per_ip' => 99, 'mail.mailers.smtp.port' => 2525]);

        $rows = $this->listJson(['--changed' => true, '--group' => 'cardano', '--grep' => 'rate'])['variables'];

        $this->assertSame(['CLAIM_RATE_PER_IP'], array_column($rows, 'variable'));
    }

    // ------------------------------------------------------------------ layout

    public function test_rows_are_sorted_by_group_then_variable(): void
    {
        $rows = $this->listJson()['variables'];
        $sorted = $rows;
        usort($sorted, fn (array $a, array $b) => [$a['group'], $a['variable'], $a['config_key']] <=> [$b['group'], $b['variable'], $b['config_key']]);

        $this->assertSame($sorted, $rows);
        $this->assertSame('admin', $rows[0]['group']);

        $text = $this->listText();
        $this->assertLessThan(strpos($text, '[cardano]'), strpos($text, '[app]'));
        $this->assertLessThan(strpos($text, '[mail]'), strpos($text, '[cardano]'));
    }

    #[DataProvider('widths')]
    public function test_no_line_is_wider_than_the_terminal_and_a_long_value_is_cut_with_an_ellipsis(int $width): void
    {
        putenv("COLUMNS={$width}");
        $long = 'https://'.str_repeat('a-long-host-name.', 12).'example.test/path';
        config(['app.url' => $long, 'cardano.claim_domain' => 'short.example.test']);

        $text = $this->listText();

        foreach (explode("\n", $text) as $line) {
            $this->assertLessThanOrEqual($width, mb_strlen($line), $line);
        }

        $this->assertStringContainsString('...', $text);
        $this->assertStringNotContainsString($long, $text);
        $this->assertStringContainsString('short.example.test', $text);
    }

    public static function widths(): array
    {
        return ['80 columns' => [80], '100 columns' => [100], '120 columns' => [120]];
    }

    public function test_a_terminal_wider_or_narrower_than_the_supported_range_is_held_to_it(): void
    {
        $rows = (new EnvListing)->rows();

        foreach ([20 => 80, 300 => 120] as $asked => $held) {
            $lines = EnvListing::lines($rows, $asked);

            foreach ($lines as $line) {
                $this->assertLessThanOrEqual($held, mb_strlen($line));
            }
        }
    }

    public function test_json_carries_the_whole_value_where_the_text_cuts_it(): void
    {
        $long = 'https://'.str_repeat('a-long-host-name.', 12).'example.test/path';
        config(['app.url' => $long]);

        $this->assertSame($long, $this->row('APP_URL', 'app')['value']);
    }

    public function test_a_control_character_or_bad_byte_in_a_row_cannot_reach_the_terminal(): void
    {
        $row = [
            'group' => 'demo', 'variable' => 'DEMO_ONE', 'config_key' => 'demo.one', 'config_keys' => ['demo.one'],
            'value' => "line-one\nline-two\e[31mred\e[0m\r", 'default' => "bad-\xB1\x31-byte", 'state' => 'set', 'checked' => false,
            'purpose' => '', 'unused' => false, 'resolved' => true,
        ];

        $lines = EnvListing::lines([$row], 100);
        $text = implode("\n", $lines);

        $this->assertStringNotContainsString("\e", $text);
        $this->assertStringNotContainsString("\r", $text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertCount(5, $lines, 'a heading line, a blank, the group, the row and its key');
        $this->assertStringContainsString('line-one line-two', $lines[3]);
    }

    public function test_a_tag_in_what_is_printed_is_printed_as_written(): void
    {
        $directory = sys_get_temp_dir().'/envlist-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            file_put_contents("{$directory}/demo.php", "<?php\nreturn [\n    '<error>tag</error>' => env('DEMO_TAG', 1),\n];\n");
            config(['demo' => ['<error>tag</error>' => 1]]);
            $this->app->useConfigPath($directory);

            $this->assertStringContainsString('demo.<error>tag</error>', $this->listText());
        } finally {
            array_map('unlink', glob("{$directory}/*") ?: []);
            rmdir($directory);
        }
    }

    public function test_the_footer_counts_the_rows_shown_and_the_ones_that_differ(): void
    {
        config(['cardano.claim_rate_per_ip' => 99]);

        $text = $this->listText(['--grep' => 'claim_rate_per']);

        $this->assertMatchesRegularExpression('/Showing 3 of \d+ rows, 3 variables, 1 differ from their default\./', $text);
    }

    public function test_json_says_how_many_rows_were_shown_and_how_many_there_are(): void
    {
        $all = $this->listJson();
        $some = $this->listJson(['--grep' => 'claim_rate_per']);

        $this->assertSame($all['total'], $all['shown']);
        $this->assertSame(3, $some['shown']);
        $this->assertSame($all['total'], $some['total']);
        $this->assertSame(
            ['group', 'variable', 'config_key', 'config_keys', 'value', 'default', 'default_kind', 'state', 'changed', 'unused', 'secret', 'masked', 'purpose', 'checked'],
            array_keys($some['variables'][0]),
        );
    }

    // ------------------------------------------------------------------ purpose and rule marker

    public function test_a_comment_in_a_config_file_is_neither_shown_nor_searchable(): void
    {
        $directory = sys_get_temp_dir().'/envlist-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            file_put_contents("{$directory}/demo.php", "<?php\nreturn [\n    // The vault recovery phrase lives in this setting.\n    'one' => env('DEMO_ONE', 1),\n];\n");
            config(['demo' => ['one' => 1]]);
            $this->app->useConfigPath($directory);

            $row = $this->row('DEMO_ONE');

            $this->assertSame('', $row['purpose']);
            $this->assertStringNotContainsString('vault', $this->listText());
            $this->assertSame([], $this->listJson(['--grep' => 'vault'])['variables']);
            $this->assertSame([], $this->listJson(['--grep' => 'recovery phrase'])['variables']);
        } finally {
            array_map('unlink', glob("{$directory}/*") ?: []);
            rmdir($directory);
        }
    }

    public function test_a_variable_with_a_written_purpose_shows_it(): void
    {
        $this->assertStringStartsWith('Claim requests allowed per IP address each minute', $this->row('CLAIM_RATE_PER_IP')['purpose']);
    }

    public function test_a_variable_the_check_has_a_rule_for_is_marked_and_one_it_has_not_is_not(): void
    {
        $this->assertTrue($this->row('QUEUE_CONNECTION')['checked']);
        $this->assertTrue($this->row('MAIL_PORT')['checked']);
        $this->assertTrue($this->row('KOIOS_PREVIEW_URL')['checked'], 'a name a rule builds from the network is covered');
        $this->assertTrue($this->row('PHYRHOSE_PREPROD_JWT')['checked']);
        $this->assertFalse($this->row('BCRYPT_ROUNDS')['checked']);
        $this->assertFalse($this->row('DYNAMODB_CACHE_TABLE')['checked']);

        $this->assertMatchesRegularExpression('/^QUEUE_CONNECTION \*/m', $this->listText());
        $this->assertDoesNotMatchRegularExpression('/^BCRYPT_ROUNDS \*/m', $this->listText());
    }

    public function test_every_variable_the_check_reports_on_is_marked(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake();

        $marked = [];

        foreach ($this->listJson()['variables'] as $row) {
            $marked[$row['variable']] = $row['checked'];
        }

        $reported = [];

        foreach (EnvReadiness::PROFILES as $profile) {
            $check = new EnvReadiness($profile);

            foreach (array_merge($check->run(), $check->live(2)) as $finding) {
                $name = preg_replace('/ \(live\)$/', '', $finding['name']);

                if (isset($marked[$name])) {
                    $reported[$name] = true;
                }
            }
        }

        $this->assertGreaterThan(20, count($reported));

        foreach (array_keys($reported) as $name) {
            $this->assertTrue($marked[$name], "{$name} is reported by poo:env-check but not marked");
        }
    }

    // ------------------------------------------------------------------ secrets

    /** Config keys of every variable whose name is classified as holding a credential. */
    private function secretKeys(): array
    {
        $keys = [];

        foreach (EnvInventory::reads() as $read) {
            if ($read['path'] === null || ! EnvMask::isSecretName($read['name'])) {
                continue;
            }

            if (in_array(false, array_column($read['path'], 'literal'), true)) {
                continue;
            }

            $keys[$read['key']] = $read['name'];
        }

        return $keys;
    }

    public function test_the_application_reads_credentials_and_the_test_means_something(): void
    {
        $names = array_values($this->secretKeys());

        $this->assertGreaterThan(25, count($names));

        foreach (['APP_KEY', 'ADMIN_PASSWORD', 'PHYRHOSE_MAINNET_JWT', 'PHYRHOSE_PREPROD_JWT', 'DB_PASSWORD', 'MAIL_PASSWORD', 'POSTMARK_TOKEN', 'AWS_SECRET_ACCESS_KEY', 'PUSHER_APP_SECRET', 'KOIOS_API_TOKEN'] as $name) {
            $this->assertContains($name, $names);
        }
    }

    public function test_no_variable_classified_as_a_credential_is_ever_printed(): void
    {
        $sentinels = [];

        foreach ($this->secretKeys() as $key => $name) {
            $sentinels[$name] = 'SENTINEL-'.$name.'-'.bin2hex(random_bytes(6));
            config([$key => $sentinels[$name]]);
        }

        $outputs = [
            'text' => $this->listText(),
            'json' => $this->listText(['--json' => true]),
            'changed' => $this->listText(['--changed' => true]),
            'changed json' => $this->listText(['--changed' => true, '--json' => true]),
        ];

        foreach ($sentinels as $name => $sentinel) {
            $fragment = substr($sentinel, 9 + strlen($name) + 1);

            foreach ($outputs as $mode => $output) {
                $this->assertStringNotContainsString($sentinel, $output, "{$name} appears in the {$mode} output");
                $this->assertStringNotContainsString($fragment, $output, "part of {$name} appears in the {$mode} output");
            }
        }

        // Each is listed, hidden, rather than left out.
        foreach (['DB_PASSWORD', 'PHYRHOSE_MAINNET_JWT', 'KOIOS_API_TOKEN'] as $name) {
            $row = $this->row($name);

            $this->assertSame('set (hidden)', $row['value'], $name);
            $this->assertTrue($row['masked']);
            $this->assertTrue($row['secret']);
        }
    }

    public function test_an_unset_credential_is_said_to_be_unset(): void
    {
        config(['cardano.koios.token' => '']);

        $this->assertSame('unset', $this->row('KOIOS_API_TOKEN')['value']);
    }

    public function test_the_default_of_a_credential_is_not_printed_either(): void
    {
        config(['admin.password' => 'password']);

        $row = $this->row('ADMIN_PASSWORD');

        $this->assertSame('(hidden)', $row['default']);
        $this->assertSame('default', $row['state']);
        $this->assertSame('set (hidden)', $row['value']);
    }

    #[DataProvider('credentialShapes')]
    public function test_a_credential_under_an_innocent_name_is_hidden(string $value): void
    {
        config(['notice.message' => $value, 'cardano.claim_domain' => $value, 'mail.mailers.smtp.host' => $value]);

        foreach (['text' => $this->listText(), 'json' => $this->listText(['--json' => true])] as $mode => $output) {
            $this->assertStringNotContainsString($value, $output, "the {$mode} output shows it");
        }

        foreach (['APP_NOTICE', 'CLAIM_DOMAIN', 'MAIL_HOST'] as $name) {
            $this->assertSame('set (hidden)', $this->row($name)['value'], $name);
        }
    }

    public static function credentialShapes(): array
    {
        return [
            'a signed token' => ['eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c'],
            'hex' => [str_repeat('9f', 32)],
            'base64' => ['dGhpcyBpcyBhIGxvbmcgYmFzZTY0IGVuY29kZWQgc2VjcmV0IHZhbHVlIDEyMw'],
            'a signing key' => ['ed25519_sk1k4ehvdlhwksx3vg3chrjnqcw5v0n6jyyn7j4gm9tdwvxc4ym4yhq5j2f9w'],
            'a recovery phrase' => ['legal winner thank year wave sausage worth useful legal winner thank yellow'],
            'an application key' => ['base64:uO9S0xk3m1QpV2yJ7wZc4bNfA6tHdEgL8rXoIqYnMsU='],
        ];
    }

    public function test_an_address_with_credentials_is_printed_without_them(): void
    {
        config([
            'cardano.mainnet_app_url' => 'https://svc-user-8841:Sup3rS3cret@app.example.test/hooks?token=AbCd1234EfGh5678&page=2',
            'database.connections.mysql.url' => 'mysql://dbuser:dbpassw0rd@db.example.test:3306/onboard',
            'cardano.koios.mainnet_url' => 'https://koiouser:Sp4rr/ow99@koios.example.test/api/v1/',
        ]);

        foreach (['text' => $this->listText(), 'json' => $this->listText(['--json' => true])] as $mode => $output) {
            foreach (['svc-user-8841', 'Sup3rS3cret', 'AbCd1234EfGh5678', 'dbuser', 'dbpassw0rd', 'koiouser', 'Sp4rr', 'ow99'] as $secret) {
                $this->assertStringNotContainsString($secret, $output, "{$secret} is in the {$mode} output");
            }
        }

        $this->assertSame('https://app.example.test/hooks?token=***&page=***', $this->row('MAINNET_APP_URL')['value']);
        $this->assertSame('https://koios.example.test/api/v1/', $this->row('KOIOS_MAINNET_URL')['value']);

        // A connection string carries credentials by design, so it is hidden whole.
        $this->assertSame('set (hidden)', $this->row('DATABASE_URL', 'database')['value']);
    }

    public function test_the_application_key_never_appears_in_any_mode(): void
    {
        $key = 'base64:SENTINELAPPKEYVALUE0123456789abcdefghij=';
        config(['app.key' => $key]);
        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake();

        $outputs = [];

        foreach (EnvReadiness::PROFILES as $profile) {
            Artisan::call('poo:env-check', ['--profile' => $profile]);
            $outputs["check {$profile}"] = Artisan::output();

            Artisan::call('poo:env-check', ['--profile' => $profile, '--live' => true, '--strict' => true]);
            $outputs["live {$profile}"] = Artisan::output();
        }

        $outputs['all'] = $this->listText();
        $outputs['all json'] = $this->listText(['--json' => true]);
        $outputs['all grep'] = $this->listText(['--grep' => 'app_key']);
        $outputs['all grep json'] = $this->listText(['--grep' => 'key', '--json' => true]);
        $outputs['all changed'] = $this->listText(['--changed' => true]);
        $outputs['all group'] = $this->listText(['--group' => 'app']);
        $outputs['all group json'] = $this->listText(['--group' => 'app', '--json' => true]);

        foreach ($outputs as $mode => $output) {
            $this->assertStringNotContainsString($key, $output, "{$mode} shows the application key");
            $this->assertStringNotContainsString('SENTINELAPPKEY', $output, "{$mode} shows part of the application key");
        }

        $row = $this->row('APP_KEY');
        $this->assertSame('set (hidden)', $row['value']);
        $this->assertSame('empty', $this->rowWithKey('')['state']);
    }

    /** The application key row once the key is blank. */
    private function rowWithKey(string $key): array
    {
        config(['app.key' => $key]);

        return $this->row('APP_KEY');
    }

    // ------------------------------------------------------------------ hide unless proven safe

    public function test_the_ordinary_settings_of_a_normal_installation_show_their_values(): void
    {
        config([
            'app.env' => 'production', 'app.debug' => false, 'logging.channels.single.level' => 'warning', 'queue.default' => 'database',
            'cache.default' => 'redis', 'session.driver' => 'redis', 'mail.default' => 'smtp', 'cardano.transaction_backend' => 'phyrhose',
            'cardano.allowed_networks' => ['mainnet'], 'database.connections.mysql.host' => 'db.example.test', 'database.connections.mysql.port' => '3306',
            'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.mailers.smtp.port' => 587, 'app.url' => 'https://app.example.test',
            'cardano.phyrhose.mainnet_url' => 'https://api.example.test/', 'cardano.claim_rate_per_ip' => 90,
        ]);

        $expected = [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'QUEUE_CONNECTION' => 'database', 'CACHE_DRIVER' => 'redis', 'SESSION_DRIVER' => 'redis',
            'MAIL_MAILER' => 'smtp', 'TRANSACTION_BACKEND' => 'phyrhose', 'ALLOWED_NETWORKS' => 'mainnet', 'MAIL_HOST' => 'smtp.example.test',
            'MAIL_PORT' => '587', 'APP_URL' => 'https://app.example.test', 'PHYRHOSE_MAINNET_URL' => 'https://api.example.test/', 'CLAIM_RATE_PER_IP' => '90',
        ];

        foreach ($expected as $variable => $value) {
            $this->assertSame($value, $this->row($variable)['value'], $variable);
        }

        $this->assertSame('warning', $this->row('LOG_LEVEL', 'logging', [], 'logging.channels.single.level')['value']);
        $this->assertSame('db.example.test', $this->row('DB_HOST', 'database')['value']);
        $this->assertSame('3306', $this->row('DB_PORT', 'database')['value']);
    }

    public function test_a_free_text_setting_is_hidden_with_whatever_it_holds(): void
    {
        config(['app.name' => 'Perfectly Ordinary Name', 'mail.from.name' => 'Claims Team', 'notice.message' => 'Back at nine']);

        foreach (['APP_NAME' => 'app', 'MAIL_FROM_NAME' => 'mail', 'APP_NOTICE' => 'notice'] as $variable => $group) {
            $row = $this->row($variable, $group);

            $this->assertSame('set (hidden)', $row['value'], $variable);
            $this->assertTrue($row['masked']);
        }

        $output = $this->listText().$this->listText(['--json' => true]);

        foreach (['Perfectly Ordinary Name', 'Claims Team', 'Back at nine'] as $text) {
            $this->assertStringNotContainsString($text, $output);
        }
    }

    public function test_the_default_of_a_free_text_setting_is_hidden_too_and_the_state_still_says_if_it_changed(): void
    {
        // Not whatever the environment this runs in holds: the default is written in the
        // configuration as 'Example', so the two cases are set here.
        config(['mail.from.name' => 'Claims Team']);

        $row = $this->row('MAIL_FROM_NAME');

        $this->assertSame('(hidden)', $row['default']);
        $this->assertSame('set (hidden)', $row['value']);
        $this->assertSame('set', $row['state']);
        $this->assertTrue($row['changed']);

        config(['mail.from.name' => 'Example']);

        $row = $this->row('MAIL_FROM_NAME');

        $this->assertSame('(hidden)', $row['default']);
        $this->assertSame('set (hidden)', $row['value']);
        $this->assertSame('default', $row['state']);
        $this->assertFalse($row['changed']);
    }

    public function test_a_value_that_is_built_from_a_hidden_variable_is_hidden_too(): void
    {
        config(['app.name' => 'Perfectly Ordinary Name']);
        // The prefixes and the cookie name are built from the application name.
        config(['cache.prefix' => 'perfectly_ordinary_name_cache_', 'session.cookie' => 'perfectly_ordinary_name_session', 'database.redis.options.prefix' => 'perfectly_ordinary_name_database_']);

        foreach ([['CACHE_PREFIX', 'cache'], ['SESSION_COOKIE', 'session'], ['REDIS_PREFIX', 'database']] as [$variable, $group]) {
            $this->assertSame('set (hidden)', $this->row($variable, $group)['value'], $variable);
        }

        $this->assertStringNotContainsString('perfectly_ordinary', $this->listText().$this->listText(['--json' => true]));
    }

    public function test_a_value_two_variables_build_between_them_is_hidden_when_either_is_not_safe(): void
    {
        config(['broadcasting.connections.pusher.options.host' => 'api-mt1.pusher.example.test']);
        $this->assertSame('api-mt1.pusher.example.test', $this->row('PUSHER_HOST')['value']);

        // Safe as a host, but not as the short name the cluster variable holds: a host built
        // from a cluster name is hidden when the cluster name would be.
        config(['broadcasting.connections.pusher.options.host' => 'api-mt1.pusher-service.region-one.example.test']);
        $this->assertSame('set (hidden)', $this->row('PUSHER_HOST')['value']);

        // A credential pasted into the cluster variable by mistake, and the host built from it.
        config(['broadcasting.connections.pusher.options.host' => 'api-Sp4rrow!.pusher.example.test', 'broadcasting.connections.pusher.options.cluster' => 'Sp4rrow!']);
        $this->assertSame('set (hidden)', $this->row('PUSHER_HOST')['value']);
        $this->assertSame('set (hidden)', $this->row('PUSHER_APP_CLUSTER')['value']);
        $this->assertStringNotContainsString('Sp4rrow', $this->listText().$this->listText(['--json' => true]));
    }

    public function test_a_value_that_is_not_what_its_variable_holds_is_hidden(): void
    {
        // A host that is a password, a port that is words, a switch that is something else.
        config(['database.connections.mysql.host' => 'Sp4rrow!', 'database.connections.mysql.port' => 'Sp4rrow!', 'database.default' => 'Sp4rrow!', 'app.debug' => 'Sp4rrow!', 'logging.channels.single.level' => 'Sp4rrow!']);

        foreach ([['DB_HOST', 'database', 'database.connections.mysql.host'], ['DB_PORT', 'database', 'database.connections.mysql.port'], ['DB_CONNECTION', 'database', 'database.default'], ['LOG_LEVEL', 'logging', 'logging.channels.single.level']] as [$variable, $group, $key]) {
            $this->assertSame('set (hidden)', $this->row($variable, $group, [], $key)['value'], $variable);
        }

        $this->assertStringNotContainsString('Sp4rrow', $this->listText().$this->listText(['--json' => true]));
    }

    // ------------------------------------------------------------------ state

    public function test_the_word_null_in_a_default_is_the_same_as_a_value_that_is_null(): void
    {
        config(['logging.deprecations.channel' => null]);

        $row = $this->row('LOG_DEPRECATIONS_CHANNEL');

        $this->assertFalse($row['changed']);
        $this->assertSame('empty', $row['state']);
        $this->assertNotContains('LOG_DEPRECATIONS_CHANNEL', array_column($this->listJson(['--changed' => true])['variables'], 'variable'));
    }

    public function test_a_connection_that_is_not_the_selected_one_is_marked_unused_and_not_counted_as_changed(): void
    {
        config(['database.default' => 'mysql']);

        $unused = array_values(array_filter(
            $this->listJson()['variables'],
            fn (array $row) => $row['group'] === 'database' && str_starts_with($row['config_key'], 'database.connections.') && ! str_starts_with($row['config_key'], 'database.connections.mysql.') && $row['unused'],
        ));

        $this->assertNotSame([], $unused);

        foreach ($unused as $row) {
            $this->assertFalse($row['changed'], $row['variable'].' at '.$row['config_key']);
        }

        // The selected connection's own settings are still judged, and a database server the
        // application really uses is not marked: the chain-data connections are not alternatives.
        config(['database.connections.mysql.host' => 'db.example.test', 'database.connections.preprod.host' => 'dbsync.example.test']);
        $mysql = $this->row('DB_HOST', 'database', []);
        $this->assertFalse($mysql['unused']);
        $this->assertTrue($mysql['changed']);
        $this->assertFalse($this->row('DBSYNC_PREPROD_HOST', 'database')['unused']);
        $this->assertStringContainsString('(unused connection)', $this->listText(['--grep' => 'sqlsrv.host']));
    }

    public function test_changed_leaves_out_the_settings_of_unused_connections(): void
    {
        config(['database.default' => 'mysql', 'database.connections.pgsql.port' => '6543', 'queue.default' => 'sync', 'queue.connections.redis.queue' => 'other']);

        $changed = array_column($this->listJson(['--changed' => true])['variables'], 'config_key');

        $this->assertNotContains('database.connections.pgsql.port', $changed);
        $this->assertNotContains('queue.connections.redis.queue', $changed);

        config(['database.default' => 'pgsql']);
        $this->assertContains('database.connections.pgsql.port', array_column($this->listJson(['--changed' => true])['variables'], 'config_key'));
    }

    public function test_the_host_names_a_managed_service_gives_out_are_shown_for_a_host_setting(): void
    {
        config([
            'database.connections.mysql.host' => 'prod-db.cluster-c9akciq32.us-east-1.db.example.test',
            'database.redis.default.host' => 'us1-merry-cat-32748.cache.example.test',
            'database.redis.cache.host' => 'us1-merry-cat-32748.cache.example.test',
            'mail.mailers.smtp.host' => 'xn--e1afmkfd.xn--p1ai',
        ]);

        $this->assertSame('prod-db.cluster-c9akciq32.us-east-1.db.example.test', $this->row('DB_HOST', 'database', [], 'database.connections.mysql.host')['value']);
        $this->assertSame('us1-merry-cat-32748.cache.example.test', $this->row('REDIS_HOST', 'database')['value']);
        $this->assertSame('xn--e1afmkfd.xn--p1ai', $this->row('MAIL_HOST')['value']);

        // The same names inside an address are judged strictly, and a key is still not shown as a label.
        config(['database.connections.mysql.host' => '9f8e7d6c5b4a39281706deadbeef.example.test', 'cardano.koios.mainnet_url' => 'https://prod-db.cluster-c9akciq32.us-east-1.db.example.test/']);

        $this->assertSame('set (hidden)', $this->row('DB_HOST', 'database', [], 'database.connections.mysql.host')['value']);
        $this->assertSame('set (hidden)', $this->row('KOIOS_MAINNET_URL')['value']);
    }

    public function test_the_pusher_app_id_is_a_number(): void
    {
        config(['broadcasting.connections.pusher.app_id' => '1234567']);
        $this->assertSame('1234567', $this->row('PUSHER_APP_ID')['value']);

        config(['broadcasting.connections.pusher.app_id' => 'Sp4rrow!']);
        $this->assertSame('set (hidden)', $this->row('PUSHER_APP_ID')['value']);
    }

    public function test_a_bare_query_key_in_an_address_is_not_printed(): void
    {
        config(['cardano.mainnet_app_url' => 'https://app.example.test/cb?ZqsklivexK7p2&sk_live_abcdefgh1234&page=2']);

        $value = $this->row('MAINNET_APP_URL')['value'];

        $this->assertSame('https://app.example.test/cb?***&***&page=***', $value);
    }
}

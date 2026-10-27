<?php

namespace Tests\Unit\Support;

use App\Support\EnvInventory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The parse that finds every environment variable the configuration reads. It is run on small
 * invented sources, to say exactly what it takes from each construct, and on the real
 * configuration, to say it misses nothing.
 */
class EnvInventoryTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function reads(string $body): array
    {
        return EnvInventory::parse("<?php\n\nreturn [\n{$body}\n];\n", 'demo')['reads'];
    }

    /** The one read the body holds. */
    private function only(string $body): array
    {
        $reads = $this->reads($body);

        $this->assertCount(1, $reads, 'expected one read, got '.json_encode(array_column($reads, 'name')));

        return $reads[0];
    }

    // ------------------------------------------------------------------ what a read carries

    public function test_a_read_carries_its_name_its_config_key_and_its_default(): void
    {
        $read = $this->only("    'limit' => env('DEMO_LIMIT', 60),");

        $this->assertSame('DEMO_LIMIT', $read['name']);
        $this->assertSame('demo', $read['group']);
        $this->assertSame('demo.limit', $read['key']);
        $this->assertSame(['kind' => 'literal', 'value' => 60], $read['default']);
        $this->assertSame('direct', $read['derived']);
    }

    public function test_a_call_split_over_several_lines_is_one_read(): void
    {
        $read = $this->only("    'session' => [\n        'cookie' => env(\n            'DEMO_COOKIE',\n            'fallback'\n        ),\n    ],");

        $this->assertSame('DEMO_COOKIE', $read['name']);
        $this->assertSame('demo.session.cookie', $read['key']);
        $this->assertSame('fallback', $read['default']['value']);
    }

    public function test_the_key_follows_every_level_the_read_is_nested_under(): void
    {
        $reads = $this->reads("    'stores' => [\n        'redis' => ['host' => env('R_HOST')],\n        'file' => ['path' => env('F_PATH', 'x')],\n    ],\n    'servers' => [\n        ['host' => env('S_HOST'), 'port' => env('S_PORT', 1)],\n    ],");

        $this->assertSame(
            ['demo.stores.redis.host', 'demo.stores.file.path', 'demo.servers.0.host', 'demo.servers.0.port'],
            array_column($reads, 'key'),
        );
    }

    public function test_the_array_function_syntax_is_followed_too(): void
    {
        $read = EnvInventory::parse("<?php\nreturn array('a' => array('b' => env('OLD_STYLE', 'x')));\n", 'demo')['reads'][0];

        $this->assertSame('demo.a.b', $read['key']);
    }

    public function test_a_key_written_as_a_constant_is_kept_as_its_source_and_marked_not_literal(): void
    {
        $read = $this->only("    'options' => [\n        PDO::MYSQL_ATTR_SSL_CA => env('DEMO_CA'),\n    ],");

        $this->assertSame('demo.options.PDO::MYSQL_ATTR_SSL_CA', $read['key']);
        $this->assertSame(['label' => 'PDO::MYSQL_ATTR_SSL_CA', 'literal' => false], $read['path'][1]);
    }

    #[DataProvider('defaults')]
    public function test_a_default_is_a_literal_when_it_is_one_and_computed_when_it_is_not(string $written, array $expected): void
    {
        $this->assertSame($expected, $this->only("    'a' => env('DEMO', {$written}),")['default']);
    }

    public static function defaults(): array
    {
        return [
            'single quoted' => ["'abc'", ['kind' => 'literal', 'value' => 'abc']],
            'double quoted' => ['"abc"', ['kind' => 'literal', 'value' => 'abc']],
            'escaped quote' => ["'it\\'s'", ['kind' => 'literal', 'value' => "it's"]],
            'empty string' => ["''", ['kind' => 'literal', 'value' => '']],
            'integer' => ['60', ['kind' => 'literal', 'value' => 60]],
            'integer with separators' => ['200_000', ['kind' => 'literal', 'value' => 200000]],
            'negative' => ['-1', ['kind' => 'literal', 'value' => -1]],
            'float' => ['1.5', ['kind' => 'literal', 'value' => 1.5]],
            'product of whole numbers' => ['10 * 1024 * 1024', ['kind' => 'literal', 'value' => 10485760]],
            'true' => ['true', ['kind' => 'literal', 'value' => true]],
            'false' => ['FALSE', ['kind' => 'literal', 'value' => false]],
            'null' => ['null', ['kind' => 'literal', 'value' => null]],
            'empty array' => ['[]', ['kind' => 'literal', 'value' => []]],
            'a call' => ["Str::slug('a')", ['kind' => 'computed', 'source' => "Str :: slug ( 'a' )"]],
            'a constant' => ['PHP_INT_MAX', ['kind' => 'computed', 'source' => 'PHP_INT_MAX']],
            'a concatenation' => ["'a'.'b'", ['kind' => 'computed', 'source' => "'a' . 'b'"]],
            'a sum' => ['1 + 2', ['kind' => 'computed', 'source' => '1 + 2']],
        ];
    }

    public function test_a_call_with_no_default_says_so(): void
    {
        $this->assertSame(['kind' => 'none'], $this->only("    'a' => env('DEMO'),")['default']);
    }

    // ------------------------------------------------------------------ how the value is built

    #[DataProvider('constructions')]
    public function test_a_read_says_whether_the_value_is_the_variable_or_built_from_it(string $expression, string $derived): void
    {
        $this->assertSame($derived, $this->reads("    'a' => {$expression},")[0]['derived']);
    }

    public static function constructions(): array
    {
        return [
            'bare' => ["env('DEMO', 1)", 'direct'],
            'cast' => ["(int) env('DEMO', 1)", 'direct'],
            'inside a call' => ["max(1, (int) env('DEMO', 1))", 'wrapped'],
            'inside two calls' => ["array_values(array_filter(explode(',', (string) env('DEMO', 'a,b'))))", 'wrapped'],
            'joined after' => ["env('DEMO').'/storage'", 'composed'],
            'joined before' => ["'x'.env('DEMO')", 'composed'],
            'elvis' => ["env('DEMO') ?: 'y'", 'composed'],
            'coalesce' => ["env('DEMO') ?? 'y'", 'composed'],
            'compared' => ["env('DEMO', 'https') === 'https'", 'composed'],
            'negated' => ["! env('DEMO', true)", 'composed'],
            'inside a closure' => ["(function () {\n        return env('DEMO', 1);\n    })()", 'composed'],
        ];
    }

    public function test_a_call_inside_another_default_is_marked_with_the_variable_it_sits_in(): void
    {
        $reads = $this->reads("    'a' => env('OUTER', Str::slug(env('INNER', 'x'), '_').'_suffix'),");

        $this->assertSame(['OUTER', 'INNER'], array_column($reads, 'name'));
        $this->assertNull($reads[0]['nested_in']);
        $this->assertSame('OUTER', $reads[1]['nested_in']);
        $this->assertSame('composed', $reads[1]['derived']);
        $this->assertSame('computed', $reads[0]['default']['kind']);
    }

    // ------------------------------------------------------------------ what is not a read

    public function test_a_call_inside_a_comment_is_not_a_read(): void
    {
        $reads = $this->reads("    'a' => env('REAL', 1),\n    // 'b' => env('COMMENTED_OUT', 2),\n    /* env('BLOCK_COMMENT') */\n    # env('HASH_COMMENT')");

        $this->assertSame(['REAL'], array_column($reads, 'name'));
    }

    public function test_a_method_or_static_call_that_happens_to_be_named_env_is_not_a_read(): void
    {
        $reads = $this->reads("    'a' => \$config->env('METHOD'),\n    'b' => Foo::env('STATIC'),\n    'c' => env('REAL'),");

        $this->assertSame(['REAL'], array_column($reads, 'name'));
    }

    public function test_a_fully_qualified_call_is_a_read(): void
    {
        $this->assertSame('QUALIFIED', $this->only("    'a' => \\env('QUALIFIED'),")['name']);
    }

    public function test_a_call_whose_name_is_not_a_literal_is_reported_as_dynamic_and_not_dropped(): void
    {
        $found = EnvInventory::parse("<?php\nfunction reader(\$name) {\n    return env(\$name);\n}\nreturn ['a' => env('REAL')];\n", 'demo');

        $this->assertSame(['REAL'], array_column($found['reads'], 'name'));
        $this->assertSame([['group' => 'demo', 'line' => 3, 'function' => 'reader']], $found['dynamic']);
    }

    public function test_a_read_outside_the_returned_array_has_no_config_key(): void
    {
        $read = EnvInventory::parse("<?php\n\$x = env('LOOSE', 1);\nreturn [];\n", 'demo')['reads'][0];

        $this->assertNull($read['key']);
        $this->assertNull($read['path']);
        $this->assertSame('composed', $read['derived']);
    }

    // ------------------------------------------------------------------ helpers

    public function test_a_helper_that_reads_a_variable_under_two_names_gives_a_read_for_each(): void
    {
        $reads = $this->reads("    'rate' => cardano_env_with_earlier_name('NEW_NAME', 'OLD_NAME', 60),");

        $this->assertSame(['NEW_NAME', 'OLD_NAME'], array_column($reads, 'name'));
        $this->assertSame(['demo.rate', 'demo.rate'], array_column($reads, 'key'));
        $this->assertSame([null, 'NEW_NAME'], array_column($reads, 'fallback_for'));
        $this->assertSame(['cardano_env_with_earlier_name', 'cardano_env_with_earlier_name'], array_column($reads, 'helper'));
        $this->assertSame(60, $reads[1]['default']['value']);
        $this->assertSame('wrapped', $reads[0]['derived']);
    }

    public function test_the_declaration_of_a_helper_is_not_a_call_to_it(): void
    {
        $found = EnvInventory::parse("<?php\nfunction cardano_env_with_earlier_name(string \$a, string \$b, int \$c) { return env(\$a); }\nreturn [];\n", 'demo');

        $this->assertSame([], $found['reads']);
        $this->assertSame('cardano_env_with_earlier_name', $found['dynamic'][0]['function']);
    }

    // ------------------------------------------------------------------ comments

    public function test_a_comment_is_never_read_out(): void
    {
        $reads = $this->reads("    // The secret recovery phrase is stored here. Do not share.\n    'a' => env('DEMO', 1),\n    /*\n    | Boxed Title\n    */\n    'b' => env('OTHER'),");

        $this->assertSame(['DEMO', 'OTHER'], array_column($reads, 'name'));

        foreach ($reads as $read) {
            $this->assertArrayNotHasKey('purpose', $read);
            $this->assertArrayNotHasKey('comment', $read);
            $this->assertStringNotContainsString('recovery phrase', json_encode($read));
            $this->assertStringNotContainsString('Boxed Title', json_encode($read));
        }
    }

    // ------------------------------------------------------------------ a directory

    public function test_every_php_file_in_a_directory_is_read_in_name_order_and_named_for_its_group(): void
    {
        $directory = sys_get_temp_dir().'/envinventory-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            file_put_contents("{$directory}/zeta.php", "<?php\nreturn ['z' => env('IN_ZETA')];\n");
            file_put_contents("{$directory}/alpha.php", "<?php\nreturn ['a' => ['b' => env('IN_ALPHA', 5)]];\n");
            file_put_contents("{$directory}/notes.txt", "env('NOT_PHP')");

            $reads = EnvInventory::reads($directory);

            $this->assertSame(['IN_ALPHA', 'IN_ZETA'], array_column($reads, 'name'));
            $this->assertSame(['alpha.a.b', 'zeta.z'], array_column($reads, 'key'));
        } finally {
            array_map('unlink', glob("{$directory}/*") ?: []);
            rmdir($directory);
        }
    }

    // ------------------------------------------------------------------ the real configuration

    /** Source of every file in config/, with comments removed, by file. */
    private function configCode(): array
    {
        $code = [];

        foreach (glob(config_path('*.php')) as $file) {
            $text = '';

            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $text .= is_array($token) ? $token[1] : $token;
            }

            $code[basename($file)] = $text;
        }

        return $code;
    }

    public function test_it_finds_every_env_call_in_the_configuration(): void
    {
        $scan = EnvInventory::scan();

        // Counted a different way: a pattern over the code with its comments taken out. Every
        // call is either a read with a literal name or reported as dynamic, and the calls the
        // helpers make on their callers' behalf are not env() calls in the source.
        $calls = 0;
        $named = [];

        foreach ($this->configCode() as $code) {
            $calls += preg_match_all('/(?<![\w>:$])\\\\?env\s*\(/i', $code);
            preg_match_all('/(?<![\w>:$])\\\\?env\s*\(\s*[\'"]([A-Za-z0-9_]+)[\'"]/i', $code, $matches);
            $named = array_merge($named, $matches[1]);
        }

        $direct = array_filter($scan['reads'], fn (array $read) => $read['helper'] === null);

        $this->assertGreaterThan(150, $calls, 'the pattern found almost nothing, so it proves nothing');
        $this->assertSame($calls, count($direct) + count($scan['dynamic']));
        $this->assertEqualsCanonicalizing($named, array_column($direct, 'name'));
    }

    public function test_every_call_made_through_a_helper_is_found_too(): void
    {
        $scan = EnvInventory::scan();

        foreach (EnvInventory::HELPERS as $helper => $shape) {
            $calls = 0;

            foreach ($this->configCode() as $code) {
                // Calls, not the declaration.
                $calls += preg_match_all('/(?<!function\s)(?<![\w>:$])'.preg_quote($helper, '/').'\s*\(/', $code);
            }

            $reads = array_filter($scan['reads'], fn (array $read) => $read['helper'] === $helper);

            $this->assertGreaterThan(0, $calls, "{$helper} is registered but nothing calls it");
            $this->assertCount($calls * count($shape['names']), $reads);
        }
    }

    public function test_the_only_calls_it_cannot_name_are_inside_the_helpers_it_knows(): void
    {
        $dynamic = EnvInventory::scan()['dynamic'];

        $this->assertNotSame([], $dynamic, 'the configuration has a helper that reads a variable by a parameter');

        foreach ($dynamic as $call) {
            $this->assertArrayHasKey(
                $call['function'] ?? '',
                EnvInventory::HELPERS,
                "{$call['group']}.php line {$call['line']} reads a variable by a name the parse cannot see, in ".json_encode($call['function']).'. Register the helper in EnvInventory::HELPERS.',
            );
        }
    }

    public function test_it_finds_every_name_the_production_checklist_inventory_lists(): void
    {
        // Built from parts: the file is operator notes the self-hosted edition does not carry, and
        // this test skips there, so it is not a published test that needs the file to exist.
        $checklist = base_path('scripts').DIRECTORY_SEPARATOR.'production-env-checklist.md';

        if (! is_file($checklist)) {
            $this->markTestSkipped('The checklist is operator notes and does not ship in the self-hosted edition.');
        }

        preg_match_all('/^\| `([A-Z][A-Z0-9_]+)` \|/m', (string) file_get_contents($checklist), $matches);
        $listed = array_unique($matches[1]);

        $this->assertGreaterThan(150, count($listed), 'the checklist inventory was not read');

        $found = array_unique(array_column(EnvInventory::reads(), 'name'));
        $missing = array_values(array_diff($listed, $found));

        // The checklist also lists two names that only appear in commented-out code, which the
        // application never reads. They are the only names it may list that the parse does not.
        $this->assertSame(['DB_ENCRYPT', 'DB_TRUST_SERVER_CERTIFICATE'], $missing);

        foreach ($missing as $name) {
            $this->assertNotSame([], $this->mentionsInComments($name), "{$name} is not found as a read, and is not in a comment either");
            $this->assertFalse($this->readInCode($name), "{$name} is read in code but the parse missed it");
        }
    }

    /** @return list<string> Files whose comments mention the name. */
    private function mentionsInComments(string $name): array
    {
        $files = [];

        foreach (glob(config_path('*.php')) as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) && str_contains($token[1], "'{$name}'")) {
                    $files[] = basename($file);
                }
            }
        }

        return $files;
    }

    private function readInCode(string $name): bool
    {
        foreach ($this->configCode() as $code) {
            if (str_contains($code, "'{$name}'")) {
                return true;
            }
        }

        return false;
    }

    public function test_every_variable_is_found_with_a_config_key_that_exists_in_the_running_configuration(): void
    {
        foreach (EnvInventory::reads() as $read) {
            if ($read['path'] === null) {
                $this->fail("{$read['name']} in {$read['group']}.php line {$read['line']} is read outside the returned array");
            }

            $node = config($read['group']);

            foreach ($read['path'] as $part) {
                if (! $part['literal']) {
                    // A constant key exists only where the extension that defines it is loaded.
                    continue 2;
                }

                $this->assertIsArray($node, "{$read['key']} ({$read['name']}): the key above it is not an array");
                $this->assertArrayHasKey($part['label'], $node, "{$read['key']} ({$read['name']}) is not a key of the running configuration");
                $node = $node[$part['label']];
            }
        }
    }
}

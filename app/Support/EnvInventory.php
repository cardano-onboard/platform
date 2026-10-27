<?php

namespace App\Support;

/**
 * Every environment variable the configuration files read, found by parsing those files
 * rather than from a list kept beside them, so the answer is what the application does now.
 *
 * A read is a call to env() whose first argument is a string literal, or a call to one of the
 * helper functions the configuration declares for reading a variable under two names. Each
 * read carries the config key it feeds and the default as written. A call inside a comment is
 * not a read and is not returned. Comments themselves are never read out: what a variable is
 * for is written in EnvPurposes, so nothing typed into a config comment can reach a listing.
 *
 * What the parse cannot see is returned too, so nothing is dropped silently: an env() call
 * whose name is not a literal comes back under "dynamic".
 */
final class EnvInventory
{
    /**
     * Functions declared in the configuration that read variables on the caller's behalf:
     * the argument positions that name a variable (the first is current, the rest are earlier
     * names read as fallbacks) and the position of the default.
     */
    public const HELPERS = [
        'cardano_env_with_earlier_name' => ['names' => [0, 1], 'default' => 2],
    ];

    /**
     * Operators that, next to an env() call, mean the config value is built from more than the
     * variable, so comparing the value with the default would say nothing.
     */
    private const OPERATORS = [
        '.', '+', '-', '*', '/', '%', '?', ':', '!', '&', '|', '<', '>', '=',
        T_COALESCE, T_BOOLEAN_AND, T_BOOLEAN_OR, T_IS_EQUAL, T_IS_IDENTICAL, T_IS_NOT_EQUAL,
        T_IS_NOT_IDENTICAL, T_IS_SMALLER_OR_EQUAL, T_IS_GREATER_OR_EQUAL, T_SL, T_SR,
    ];

    private const CASTS = [T_INT_CAST, T_BOOL_CAST, T_DOUBLE_CAST, T_STRING_CAST, T_ARRAY_CAST, T_OBJECT_CAST];

    /**
     * Each read of a variable in the configuration directory, and the calls that could not
     * be attributed to a name.
     *
     * A read:
     *   name        the variable
     *   group       the config file, which is also the first part of the config key
     *   key         the config key it feeds, as written (for display)
     *   path        the parts of that key below the file, each {label, literal}, for lookup
     *   line        the line of the call
     *   default     {kind: literal, value}, {kind: computed, source} or {kind: none}
     *   derived     direct, wrapped (inside a function call) or composed (the value is built
     *               from more than the variable, so it cannot be compared with the default)
     *   nested_in   the variable whose default this call sits inside, when it does
     *   fallback_for  the current name, when this is an earlier name read as a fallback
     *   helper      the helper function the read went through, when one
     *
     * @return array{reads: list<array<string, mixed>>, dynamic: list<array<string, mixed>>}
     */
    public static function scan(?string $directory = null): array
    {
        $directory = rtrim($directory ?? config_path(), '/');
        $files = glob($directory.'/*.php') ?: [];
        sort($files);

        $reads = [];
        $dynamic = [];

        foreach ($files as $file) {
            $found = self::parse((string) file_get_contents($file), pathinfo($file, PATHINFO_FILENAME));
            $reads = array_merge($reads, $found['reads']);
            $dynamic = array_merge($dynamic, $found['dynamic']);
        }

        return ['reads' => $reads, 'dynamic' => $dynamic];
    }

    /** @return list<array<string, mixed>> */
    public static function reads(?string $directory = null): array
    {
        return self::scan($directory)['reads'];
    }

    /**
     * @return array{reads: list<array<string, mixed>>, dynamic: list<array<string, mixed>>}
     */
    public static function parse(string $source, string $group): array
    {
        $tokens = self::tokens($source);
        $count = count($tokens);

        $reads = [];
        $dynamic = [];

        /** @var list<array<string, mixed>> $stack */
        $stack = [];
        $pendingFunction = null;

        /** @var list<array{int, string}> $envCalls Calls whose arguments are being read: end index and name. */
        $envCalls = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = $token['id'];
            $text = $token['text'];

            while ($envCalls !== [] && $i > end($envCalls)[0]) {
                array_pop($envCalls);
            }

            // Element bookkeeping for the array being filled in.
            $top = $stack === [] ? null : count($stack) - 1;

            if ($top !== null && $stack[$top]['type'] === 'array') {
                if ($stack[$top]['start']) {
                    $stack[$top]['start'] = false;
                    $stack[$top]['buffer'] = [];
                }

                if ($id === T_DOUBLE_ARROW) {
                    $stack[$top]['key'] = self::keyOf($stack[$top]['buffer']);
                    $stack[$top]['buffer'] = [];

                    continue;
                }

                if ($id === ',') {
                    $stack[$top]['index']++;
                    $stack[$top]['key'] = null;
                    $stack[$top]['start'] = true;

                    continue;
                }

                $stack[$top]['buffer'][] = $token;
            }

            // Openers and closers.
            if ($id === T_FUNCTION) {
                $next = $tokens[$i + 1] ?? null;

                if ($next !== null && $next['id'] === '&') {
                    $next = $tokens[$i + 2] ?? null;
                }

                $pendingFunction = $next !== null && $next['id'] === T_STRING ? $next['text'] : '{closure}';
            } elseif ($id === T_ARRAY && ($tokens[$i + 1]['id'] ?? null) === '(') {
                $stack[] = self::frame('array', $stack === [] && ($tokens[$i - 1]['id'] ?? null) === T_RETURN);
                $i++;

                continue;
            } elseif ($id === '[') {
                $isRoot = $stack === [] && ($tokens[$i - 1]['id'] ?? null) === T_RETURN;
                $stack[] = self::frame('array', $isRoot);

                continue;
            } elseif ($id === '(') {
                $stack[] = self::frame('paren');

                continue;
            } elseif ($id === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $frame = self::frame('brace');
                $frame['function'] = $pendingFunction;
                $pendingFunction = null;
                $stack[] = $frame;

                continue;
            } elseif ($id === ']' || $id === ')' || $id === '}') {
                array_pop($stack);

                continue;
            }

            // A call that reads a variable.
            if (! self::isCall($tokens, $i)) {
                continue;
            }

            $isEnv = ($id === T_STRING && strtolower($text) === 'env') || ($id === T_NAME_FULLY_QUALIFIED && strtolower($text) === '\env');
            $isHelper = $id === T_STRING && isset(self::HELPERS[$text]);

            if (! $isEnv && ! $isHelper) {
                continue;
            }

            $end = self::closer($tokens, $i + 1);
            $arguments = self::arguments($tokens, $i + 1, $end);

            $context = self::context($stack, $tokens, $i, $end, $envCalls);

            if ($isEnv) {
                $name = self::literal($arguments[0] ?? [])['value'] ?? null;

                if (! is_string($name)) {
                    $dynamic[] = ['group' => $group, 'line' => $token['line'], 'function' => self::enclosingFunction($stack)];

                    continue;
                }

                $reads[] = self::read($group, $name, $token['line'], $arguments[1] ?? null, isset($arguments[1]), $context);
                $envCalls[] = [$end, $name];

                continue;
            }

            $helper = self::HELPERS[$text];
            $first = null;

            foreach ($helper['names'] as $position) {
                $name = self::literal($arguments[$position] ?? [])['value'] ?? null;

                if (! is_string($name)) {
                    $dynamic[] = ['group' => $group, 'line' => $token['line'], 'function' => $text];

                    continue;
                }

                $read = self::read($group, $name, $token['line'], $arguments[$helper['default']] ?? null, isset($arguments[$helper['default']]), $context);
                $read['helper'] = $text;
                $read['derived'] = $read['derived'] === 'composed' ? 'composed' : 'wrapped';

                if ($first === null) {
                    $first = $name;
                } else {
                    $read['fallback_for'] = $first;
                }

                $reads[] = $read;
            }
        }

        return ['reads' => $reads, 'dynamic' => $dynamic];
    }

    // ------------------------------------------------------------------ tokens

    /**
     * The tokens that carry meaning, each with the line it starts on. Whitespace, comments and
     * the PHP tags are dropped.
     *
     * @return list<array{id: int|string, text: string, line: int}>
     */
    private static function tokens(string $source): array
    {
        $tokens = [];
        $cursor = 1;

        foreach (token_get_all($source) as $raw) {
            [$id, $text] = is_array($raw) ? $raw : [$raw, $raw];
            $line = $cursor;
            $cursor += substr_count($text, "\n");

            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                continue;
            }

            $tokens[] = ['id' => $id, 'text' => $text, 'line' => $line];
        }

        return $tokens;
    }

    // ------------------------------------------------------------------ structure

    /** @return array<string, mixed> */
    private static function frame(string $type, bool $root = false): array
    {
        return [
            'type' => $type,
            'root' => $root,
            'key' => null,
            'index' => 0,
            'start' => $type === 'array',
            'buffer' => [],
            'function' => null,
        ];
    }

    /**
     * The key an array element was written under: its string, when it is a plain one, else
     * the source of the expression, flagged so a lookup knows to evaluate it.
     *
     * @param  list<array<string, mixed>>  $buffer
     * @return array{label: string, literal: bool}
     */
    private static function keyOf(array $buffer): array
    {
        if (count($buffer) === 1 && $buffer[0]['id'] === T_CONSTANT_ENCAPSED_STRING) {
            return ['label' => self::unquote($buffer[0]['text']), 'literal' => true];
        }

        if (count($buffer) === 1 && $buffer[0]['id'] === T_LNUMBER) {
            return ['label' => (string) intval(str_replace('_', '', $buffer[0]['text']), 0), 'literal' => true];
        }

        return ['label' => implode('', array_column($buffer, 'text')), 'literal' => false];
    }

    /**
     * Where a call sits: the config key above it, and whether the value is built from more than
     * the variable.
     *
     * @param  list<array<string, mixed>>  $stack
     * @param  list<array<string, mixed>>  $tokens
     * @param  list<array{int, string}>  $envCalls
     * @return array<string, mixed>
     */
    private static function context(array $stack, array $tokens, int $at, int $end, array $envCalls): array
    {
        $rooted = false;
        $path = [];
        $inBrace = false;

        foreach ($stack as $frame) {
            $rooted = $rooted || $frame['root'];

            if (! $rooted) {
                continue;
            }

            if ($frame['type'] === 'array') {
                $path[] = $frame['key'] ?? ['label' => (string) $frame['index'], 'literal' => true];
            } elseif ($frame['type'] === 'brace') {
                $inBrace = true;
            }
        }

        $top = $stack === [] ? null : end($stack);

        $composed = $inBrace
            || $envCalls !== []
            || self::operatorBefore($tokens, $at)
            || self::operatorAfter($tokens, $end);

        return [
            'rooted' => $rooted,
            'path' => $path,
            'derived' => $composed ? 'composed' : (($top['type'] ?? 'array') === 'array' ? 'direct' : 'wrapped'),
            'nested_in' => $envCalls === [] ? null : end($envCalls)[1],
        ];
    }

    /** @param list<array<string, mixed>> $stack */
    private static function enclosingFunction(array $stack): ?string
    {
        foreach (array_reverse($stack) as $frame) {
            if (($frame['function'] ?? null) !== null) {
                return $frame['function'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array<string, mixed>>|null  $default
     * @return array<string, mixed>
     */
    private static function read(string $group, string $name, int $line, ?array $default, bool $hasDefault, array $context): array
    {
        $path = $context['rooted'] ? $context['path'] : null;

        return [
            'name' => $name,
            'group' => $group,
            'key' => $path === null ? null : $group.'.'.implode('.', array_column($path, 'label')),
            'path' => $path,
            'line' => $line,
            'default' => $hasDefault ? self::defaultOf($default ?? []) : ['kind' => 'none'],
            'derived' => $path === null ? 'composed' : $context['derived'],
            'nested_in' => $context['nested_in'],
            'fallback_for' => null,
            'helper' => null,
        ];
    }

    // ------------------------------------------------------------------ calls and arguments

    /** @param list<array<string, mixed>> $tokens */
    private static function isCall(array $tokens, int $i): bool
    {
        if (($tokens[$i + 1]['id'] ?? null) !== '(') {
            return false;
        }

        $before = $tokens[$i - 1]['id'] ?? null;

        return ! in_array($before, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
    }

    /** The index of the token that closes the bracket opened at $open. */
    private static function closer(array $tokens, int $open): int
    {
        $depth = 0;

        for ($i = $open, $count = count($tokens); $i < $count; $i++) {
            $id = $tokens[$i]['id'];

            if (in_array($id, ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (in_array($id, [')', ']', '}'], true) && --$depth === 0) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * The arguments of the call opened at $open, as lists of tokens.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return list<list<array<string, mixed>>>
     */
    private static function arguments(array $tokens, int $open, int $close): array
    {
        $arguments = [];
        $current = [];
        $depth = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            $id = $tokens[$i]['id'];

            if (in_array($id, ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif (in_array($id, [')', ']', '}'], true)) {
                $depth--;
            }

            if ($id === ',' && $depth === 0) {
                $arguments[] = $current;
                $current = [];

                continue;
            }

            $current[] = $tokens[$i];
        }

        if ($current !== []) {
            $arguments[] = $current;
        }

        return $arguments;
    }

    /** @param list<array<string, mixed>> $tokens */
    private static function operatorBefore(array $tokens, int $at): bool
    {
        $i = $at - 1;

        while ($i >= 0 && in_array($tokens[$i]['id'], self::CASTS, true)) {
            $i--;
        }

        return $i >= 0 && in_array($tokens[$i]['id'], self::OPERATORS, true);
    }

    /** @param list<array<string, mixed>> $tokens */
    private static function operatorAfter(array $tokens, int $end): bool
    {
        return in_array($tokens[$end + 1]['id'] ?? null, self::OPERATORS, true);
    }

    // ------------------------------------------------------------------ values

    /**
     * The default of a call, as written: a literal when the tokens are one, else computed.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return array{kind: string, value?: mixed, source?: string}
     */
    private static function defaultOf(array $tokens): array
    {
        $literal = self::literal($tokens);

        if ($literal !== null) {
            return ['kind' => 'literal', 'value' => $literal['value']];
        }

        return ['kind' => 'computed', 'source' => implode(' ', array_column($tokens, 'text'))];
    }

    /**
     * The value of tokens that are a literal, as ['value' => ...], or null when they are not:
     * a string, a number (a product of whole numbers counts, as in 10 * 1024 * 1024), true,
     * false, null or an empty array. A null result is what "computed" means.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return array{value: mixed}|null
     */
    private static function literal(array $tokens): ?array
    {
        $count = count($tokens);

        if ($count === 0) {
            return null;
        }

        $first = $tokens[0];

        if ($count === 1) {
            return match ($first['id']) {
                T_CONSTANT_ENCAPSED_STRING => ['value' => self::unquote($first['text'])],
                T_LNUMBER => ['value' => intval(str_replace('_', '', $first['text']), 0)],
                T_DNUMBER => ['value' => (float) str_replace('_', '', $first['text'])],
                T_STRING => match (strtolower($first['text'])) {
                    'true' => ['value' => true],
                    'false' => ['value' => false],
                    'null' => ['value' => null],
                    default => null,
                },
                default => null,
            };
        }

        if ($count === 2 && $first['id'] === '-' && in_array($tokens[1]['id'], [T_LNUMBER, T_DNUMBER], true)) {
            return ['value' => -self::literal([$tokens[1]])['value']];
        }

        if ($count === 2 && $first['id'] === '[' && $tokens[1]['id'] === ']') {
            return ['value' => []];
        }

        // A product of whole numbers.
        if ($count % 2 === 1) {
            $product = 1;

            foreach ($tokens as $position => $token) {
                if ($position % 2 === 0 && $token['id'] === T_LNUMBER) {
                    $product *= intval(str_replace('_', '', $token['text']), 0);
                } elseif ($position % 2 !== 1 || $token['id'] !== '*') {
                    return null;
                }
            }

            return ['value' => $product];
        }

        return null;
    }

    private static function unquote(string $quoted): string
    {
        $body = substr($quoted, 1, -1);

        if ($quoted[0] === "'") {
            return strtr($body, ['\\\\' => '\\', "\\'" => "'"]);
        }

        return stripcslashes($body);
    }
}

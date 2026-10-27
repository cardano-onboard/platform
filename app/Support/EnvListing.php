<?php

namespace App\Support;

use ReflectionClass;

/**
 * Every environment variable the application reads, with the value it is running with, the
 * default written in the configuration, and whether the two differ.
 *
 * The variables come from EnvInventory, which parses the configuration files, so a variable
 * added to a config file appears here with no other change. The value comes from config(), so
 * a cached configuration is listed the same as a fresh one. Everything a row carries has been
 * through EnvMask, which hides every value that is not proven safe to show: a row never holds a
 * secret, so a caller can print it, log it or encode it without checking.
 *
 * A row's state:
 *   set      the value differs from the default
 *   default  the value equals the default (it may also have been set to that value)
 *   empty    the value is blank
 *   unknown  it cannot be told: the default is computed, or the value is built from more than
 *            this variable, or the variable is read only as an earlier name for another
 */
final class EnvListing
{
    public const SET = 'set';

    public const DEFAULT = 'default';

    public const EMPTY = 'empty';

    public const UNKNOWN = 'unknown';

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    public function __construct(private ?string $directory = null) {}

    /**
     * Every row, sorted by group, then variable.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $reads = EnvInventory::reads($this->directory);
        $covered = $this->covered(array_values(array_unique(array_column($reads, 'name'))));

        // Every read of each config key. A value that more than one variable feeds is shown only
        // when it is safe to show for each of them.
        $readers = [];

        foreach ($reads as $read) {
            if ($read['key'] !== null) {
                $readers[$read['key']][] = $read;
            }
        }

        $rows = array_map(fn (array $read) => $this->row($read, in_array($read['name'], $covered, true), $readers[$read['key']] ?? [$read]), $reads);

        $rows = $this->fold($rows);
        $rows = $this->merge($rows);

        usort($rows, fn (array $a, array $b) => [$a['group'], $a['variable'], $a['config_key']] <=> [$b['group'], $b['variable'], $b['config_key']]);

        return $this->rows = $rows;
    }

    /** @return list<string> */
    public function groups(): array
    {
        $groups = array_values(array_unique(array_column($this->rows(), 'group')));
        sort($groups);

        return $groups;
    }

    /**
     * Rows narrowed by what an operator typed.
     *
     * @param  string|null  $grep  Words that must all appear in the variable, config key, group or purpose. Never the value.
     * @param  bool  $changed  Only rows whose value differs from the default.
     * @param  list<string>|null  $groups  Only these config files.
     * @return list<array<string, mixed>>
     */
    public function filter(?string $grep = null, bool $changed = false, ?array $groups = null): array
    {
        $terms = array_values(array_filter(preg_split('/\s+/', strtolower(trim((string) $grep))) ?: []));
        $groups = $groups === null ? null : array_map('strtolower', $groups);

        return array_values(array_filter($this->rows(), function (array $row) use ($terms, $changed, $groups): bool {
            if ($changed && ! $row['changed']) {
                return false;
            }

            if ($groups !== null && ! in_array(strtolower($row['group']), $groups, true)) {
                return false;
            }

            $haystack = strtolower(implode(' ', [$row['group'], $row['variable'], ...$row['config_keys'], $row['purpose']]));

            foreach ($terms as $term) {
                if (! str_contains($haystack, $term)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * The rows as lines for a terminal that is $width columns wide: a group heading, then for
     * each variable one line with its state, value and default, and one beneath it with its
     * config key and purpose. Long values are cut with an ellipsis, so nothing wraps.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    public static function lines(array $rows, int $width = 100): array
    {
        $width = self::clamp($width);

        $nameWidth = min(40, max(8, ...array_map(fn (array $row) => mb_strlen($row['variable']) + ($row['checked'] ? 2 : 0), $rows ?: [['variable' => '', 'checked' => false]])));
        $stateWidth = 7;
        $rest = $width - $nameWidth - $stateWidth - 3;
        $valueWidth = (int) floor($rest * 0.6);
        $defaultWidth = $rest - $valueWidth;

        $lines = [self::pad('VARIABLE', $nameWidth).' '.self::pad('STATE', $stateWidth).' '.self::pad('VALUE', $valueWidth).' DEFAULT'];
        $group = null;

        foreach ($rows as $row) {
            if ($row['group'] !== $group) {
                $group = $row['group'];
                $lines[] = '';
                $lines[] = "[{$group}]";
            }

            $name = $row['variable'].($row['checked'] ? ' *' : '');

            $lines[] = rtrim(self::pad(self::cut($name, $nameWidth), $nameWidth)
                .' '.self::pad($row['state'], $stateWidth)
                .' '.self::pad(self::cut(self::plain($row['value'] ?? self::none($row)), $valueWidth), $valueWidth)
                .' '.self::cut(self::plain($row['default'] ?? '(none)'), $defaultWidth));

            $key = $row['config_key'].(count($row['config_keys']) > 1 ? ' (+'.(count($row['config_keys']) - 1).' more)' : '').($row['unused'] ? ' (unused connection)' : '');
            $lines[] = '    '.self::cut($key.($row['purpose'] === '' ? '' : ' - '.$row['purpose']), $width - 4);
        }

        return $lines;
    }

    /** The width lines are laid out for: the terminal's, held to what stays readable. */
    public static function clamp(int $width): int
    {
        return max(80, min(120, $width));
    }

    /**
     * A row as it is exported: the fields a reader of the listing needs, and none of the
     * bookkeeping used to build it.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function export(array $row): array
    {
        return [
            'group' => $row['group'],
            'variable' => $row['variable'],
            'config_key' => $row['config_key'],
            'config_keys' => $row['config_keys'],
            'value' => $row['value'],
            'default' => match (true) {
                $row['default_kind'] === 'none' => null,
                $row['default_kind'] === 'literal' && $row['default'] === '(empty)' => '',
                default => $row['default'],
            },
            'default_kind' => $row['default_kind'],
            'state' => $row['state'],
            'changed' => $row['changed'],
            'unused' => $row['unused'],
            'secret' => $row['secret'],
            'masked' => $row['masked'],
            'purpose' => $row['purpose'],
            'checked' => $row['checked'],
        ];
    }

    /**
     * The state of a row that has no value to show, said in words.
     *
     * @param  array<string, mixed>  $row
     */
    private static function none(array $row): string
    {
        return $row['resolved'] ? '(empty)' : '(not in config)';
    }

    // ------------------------------------------------------------------ rows

    /**
     * @param  array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private function row(array $read, bool $checked, array $siblings): array
    {
        $name = $read['name'];
        [$resolved, $effective] = $this->resolve($read);

        $secret = EnvMask::isSecretName($name);
        $empty = $effective === null || $effective === '' || $effective === [];
        $shown = $resolved ? $this->shown($name, $read['default'], $effective, $siblings) : ['text' => '(not in config)', 'hidden' => false];

        $default = $read['default'];
        $defaultText = match ($default['kind']) {
            'none' => '(none)',
            'computed' => 'computed',
            default => $this->defaultText($name, $default),
        };

        [$state, $changed] = $this->state($read, $resolved, $effective, $empty);

        $unused = $resolved && $this->unused($read);

        $purpose = EnvPurposes::BY_NAME[$name]
            ?? ($read['fallback_for'] !== null ? "Earlier name for {$read['fallback_for']}, still read when that one is blank." : '');

        return [
            'group' => $read['group'],
            'variable' => $name,
            'config_key' => $read['key'] ?? "{$read['group']} (not a config key)",
            'config_keys' => [$read['key'] ?? "{$read['group']} (not a config key)"],
            'resolved' => $resolved,
            'value' => ! $resolved ? null : ($shown['hidden'] ? $shown['text'] : ($empty ? null : $shown['text'])),
            'masked' => $shown['hidden'],
            'secret' => $secret,
            'default' => $defaultText,
            'default_kind' => $default['kind'],
            'state' => $state,
            'changed' => $changed && ! $unused,
            'unused' => $unused,
            'purpose' => $purpose,
            'checked' => $checked,
            'derived' => $read['derived'],
            'nested_in' => $read['nested_in'],
        ];
    }

    /**
     * What may be shown of a read's value. A key that several variables feed holds a value built
     * from all of them, so it is shown only when it is safe to show as the value of each: a
     * prefix built from a name that is hidden is hidden too.
     *
     * @param  array{kind: string, value?: mixed}  $default
     * @param  list<array<string, mixed>>  $siblings  Every read of the same config key.
     * @return array{text: string, hidden: bool}
     */
    private function shown(string $name, array $default, mixed $effective, array $siblings): array
    {
        $own = EnvMask::show($name, $effective, EnvTypes::of($name, $default));

        foreach ($siblings as $sibling) {
            if ($sibling['name'] === $name) {
                continue;
            }

            $other = EnvMask::show($sibling['name'], $effective, EnvTypes::of($sibling['name'], $sibling['default']));

            if ($other['hidden'] && $effective !== null && $effective !== '' && $effective !== []) {
                return ['text' => EnvMask::HIDDEN, 'hidden' => true];
            }
        }

        return $own;
    }

    /**
     * Whether a read configures a connection, store or mailer that is not the one this
     * deployment has selected, so its values are not in use whatever they say.
     *
     * @param  array<string, mixed>  $read
     */
    private function unused(array $read): bool
    {
        if (preg_match('/^(database\.connections|queue\.connections|cache\.stores|mail\.mailers)\.([a-z]+)\./', (string) $read['key'], $m) !== 1) {
            return false;
        }

        $standard = [
            'database.connections' => ['mysql', 'mariadb', 'pgsql', 'sqlsrv', 'sqlite'],
            'queue.connections' => ['database', 'beanstalkd', 'sqs', 'redis'],
            'cache.stores' => ['memcached', 'redis', 'dynamodb', 'database'],
            'mail.mailers' => ['smtp', 'ses', 'mailgun', 'postmark', 'sendmail', 'resend'],
        ];

        $selected = config(match ($m[1]) {
            'database.connections' => 'database.default',
            'queue.connections' => 'queue.default',
            'cache.stores' => 'cache.default',
            default => 'mail.default',
        });

        return in_array($m[2], $standard[$m[1]], true) && is_string($selected) && $selected !== '' && $selected !== $m[2];
    }

    /**
     * @param  array<string, mixed>  $read
     * @return array{0: string, 1: bool} the state, and whether the value is known to differ from the default
     */
    private function state(array $read, bool $resolved, mixed $effective, bool $empty): array
    {
        if (! $resolved || $read['derived'] === 'composed' || $read['nested_in'] !== null || $read['fallback_for'] !== null) {
            return [self::UNKNOWN, false];
        }

        $default = $read['default'];
        $written = $default['kind'] === 'literal' ? self::asEnvironment($default['value']) : null;

        if ($empty) {
            // Blank now: a change only when the default was something.
            $changed = $default['kind'] === 'literal' && ! self::blank($written);

            return [self::EMPTY, $changed];
        }

        if ($default['kind'] === 'computed') {
            return [self::UNKNOWN, false];
        }

        $equal = self::equal($effective, $written);

        return [$equal ? self::DEFAULT : self::SET, ! $equal];
    }

    /**
     * What a default shows as: held to the same rule as a value, so a default is shown only when
     * it is safe to show.
     *
     * @param  array{kind: string, value?: mixed}  $default
     */
    private function defaultText(string $name, array $default): string
    {
        $shown = EnvMask::show($name, $default['value'], EnvTypes::of($name, $default));

        if ($shown['hidden']) {
            return $shown['text'] === EnvMask::UNSET ? '(empty)' : '(hidden)';
        }

        return $shown['text'] === EnvMask::EMPTY ? '(empty)' : $shown['text'];
    }

    /**
     * The value a read feeds in the running configuration.
     *
     * @param  array<string, mixed>  $read
     * @return array{0: bool, 1: mixed} whether the key exists, and what it holds
     */
    private function resolve(array $read): array
    {
        if ($read['path'] === null) {
            return [false, null];
        }

        $node = config($read['group']);

        foreach ($read['path'] as $part) {
            $label = $part['label'];

            if (! $part['literal'] && defined($label)) {
                $label = constant($label);
            }

            if (! is_array($node) || ! array_key_exists($label, $node)) {
                return [false, null];
            }

            $node = $node[$label];
        }

        return [true, $node];
    }

    /**
     * A read of a variable whose value is built from more than the variable says nothing about
     * the variable, so it is listed under the variable's own row instead of as a row of its own:
     * a read inside another variable's default goes under the variable's main row wherever that
     * is, and any other built value goes under the main row in its own file. With no main row to
     * go under, it stays a row, flagged as unknown.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function fold(array $rows): array
    {
        $anywhere = [];
        $inFile = [];

        foreach ($rows as $index => $row) {
            if (! $this->secondary($row)) {
                $anywhere[$row['variable']] ??= $index;
                $inFile[$row['group'].'|'.$row['variable']] ??= $index;
            }
        }

        $folded = [];

        foreach ($rows as $index => $row) {
            $target = $row['nested_in'] !== null
                ? ($anywhere[$row['variable']] ?? null)
                : ($this->secondary($row) ? ($inFile[$row['group'].'|'.$row['variable']] ?? null) : null);

            if ($target !== null) {
                $rows[$target]['config_keys'][] = $row['config_key'];
                $folded[$index] = true;
            }
        }

        return array_values(array_filter($rows, fn (int $index) => ! isset($folded[$index]), ARRAY_FILTER_USE_KEY));
    }

    /** @param array<string, mixed> $row */
    private function secondary(array $row): bool
    {
        return $row['derived'] === 'composed' || $row['nested_in'] !== null;
    }

    /**
     * Rows for the same variable that say the same thing, as when one variable feeds the same
     * setting of several connections, become one row listing every key.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function merge(array $rows): array
    {
        $merged = [];

        foreach ($rows as $row) {
            $signature = json_encode([$row['group'], $row['variable'], $row['value'], $row['default'], $row['state'], $row['masked'], $row['unused']]);

            if (! isset($merged[$signature])) {
                $merged[$signature] = $row;

                continue;
            }

            $merged[$signature]['config_keys'] = array_values(array_unique(array_merge($merged[$signature]['config_keys'], $row['config_keys'])));
        }

        return array_values($merged);
    }

    // ------------------------------------------------------------------ rules

    /**
     * The names, from $names, that the readiness check has a rule for: those it names in a
     * message, which is every setting it judges. Read from the check's own source, so a rule
     * added there is marked here with no second list to keep.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function covered(array $names): array
    {
        $file = (new ReflectionClass(EnvReadiness::class))->getFileName();
        $text = '';

        // The pieces of a string are joined as written, so PHYRHOSE_{$upper}_JWT stays whole.
        foreach (token_get_all((string) file_get_contents((string) $file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_ENCAPSED_AND_WHITESPACE, T_CURLY_OPEN, T_VARIABLE], true)) {
                $text .= $token[1];
            } elseif (is_array($token)) {
                $text .= ' '.$token[1].' ';
            } else {
                $text .= $token === '}' || $token === '"' ? $token : ' '.$token.' ';
            }
        }

        $covered = [];

        foreach ($names as $name) {
            if (preg_match('/(?<![A-Z0-9_])'.preg_quote($name, '/').'(?![A-Z0-9_])/', $text) === 1) {
                $covered[] = $name;

                continue;
            }

            // A rule that builds a name from the network, such as PHYRHOSE_{$upper}_JWT.
            foreach (['PREPROD', 'PREVIEW', 'MAINNET'] as $network) {
                if (str_contains($name, $network) && str_contains($text, str_replace($network, '{$upper}', $name))) {
                    $covered[] = $name;

                    break;
                }
            }
        }

        return $covered;
    }

    // ------------------------------------------------------------------ comparison and text

    /**
     * A default as the environment would have given it: the word null, which a value in the
     * environment file becomes null for, is null.
     */
    private static function asEnvironment(mixed $default): mixed
    {
        return is_string($default) && in_array(strtolower($default), ['null', '(null)'], true) ? null : $default;
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /** Whether a configured value and a default written in the configuration are the same setting. */
    private static function equal(mixed $value, mixed $default): bool
    {
        if (is_bool($value) || is_bool($default)) {
            $a = self::truth($value);
            $b = self::truth($default);

            if ($a !== null && $b !== null) {
                return $a === $b;
            }
        }

        if (is_numeric($value) && is_numeric($default)) {
            return (float) $value === (float) $default;
        }

        return self::canon($value) === self::canon($default);
    }

    private static function truth(mixed $value): ?bool
    {
        return is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function canon(mixed $value): string
    {
        if (is_array($value)) {
            return implode(',', array_map(fn ($v) => self::canon($v), $value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value === null ? '' : trim((string) $value);
    }

    /**
     * Text safe to write to a terminal: a control character, such as an escape, becomes a space,
     * and bytes that are not valid UTF-8 become a replacement character.
     */
    private static function plain(?string $text): string
    {
        return (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', mb_scrub((string) $text));
    }

    private static function cut(string $text, int $width): string
    {
        return mb_strimwidth($text, 0, max(4, $width), '...');
    }

    private static function pad(string $text, int $width): string
    {
        return $text.str_repeat(' ', max(0, $width - mb_strwidth($text)));
    }
}

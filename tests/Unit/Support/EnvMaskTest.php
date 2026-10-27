<?php

namespace Tests\Unit\Support;

use App\Support\EnvMask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What may be shown of a value. The policy is to hide unless proven safe, so most of what is
 * tested here is that a value with the wrong shape for its type, or with no type at all, is not
 * shown, and that each of the three checks (type, name, shape) holds when the other two have
 * nothing to say.
 */
class EnvMaskTest extends TestCase
{
    private const HIDDEN = ['text' => 'set (hidden)', 'hidden' => true];

    private function shown(string $text): array
    {
        return ['text' => $text, 'hidden' => false];
    }

    // ------------------------------------------------------------------ nothing is shown without a type

    #[DataProvider('anyValues')]
    public function test_a_variable_with_no_type_never_shows_a_value(mixed $value): void
    {
        $this->assertSame(self::HIDDEN, EnvMask::show('ANY_FREE_TEXT_SETTING', $value, null));
    }

    public static function anyValues(): array
    {
        return array_map(fn ($v) => [$v], [
            'a short human password' => 'Sp4rrow!',
            'a plain word' => 'sparrow',
            'a number' => 8080,
            'a boolean' => true,
            'a host name' => 'db.example.test',
            'a path' => '/var/www/html/storage',
            'a list' => ['a', 'b'],
            'an address' => 'https://app.example.test',
        ]);
    }

    public function test_an_empty_value_is_said_to_be_empty_or_unset_and_needs_no_type(): void
    {
        foreach ([null, '', []] as $empty) {
            $this->assertSame(['text' => 'unset', 'hidden' => true], EnvMask::show('DB_PASSWORD', $empty, null));
            $this->assertSame(['text' => '(empty)', 'hidden' => false], EnvMask::show('APP_NOTICE', $empty, null));
            $this->assertSame(['text' => '(empty)', 'hidden' => false], EnvMask::show('APP_URL', $empty, 'url'));
        }
    }

    // ------------------------------------------------------------------ the types

    #[DataProvider('typedValues')]
    public function test_a_value_is_shown_only_when_it_has_the_shape_of_its_type(string $type, mixed $value, ?string $expected): void
    {
        $this->assertSame($expected === null ? self::HIDDEN : $this->shown($expected), EnvMask::show('SOME_SETTING', $value, $type), $type.' '.json_encode($value));
    }

    public static function typedValues(): array
    {
        $cases = [
            // switches
            ['bool', true, 'true'], ['bool', false, 'false'], ['bool', 'TRUE', 'true'], ['bool', '1', '1'], ['bool', 0, '0'], ['bool', 'off', 'off'],
            ['bool', 'maybe', null], ['bool', 'Sp4rrow!', null], ['bool', 2, null], ['bool', ['true'], null],
            // numbers
            ['int', 60, '60'], ['int', '60', '60'], ['int', -1, '-1'], ['int', 999999999999, '999999999999'],
            ['int', '1000000000000', null], ['int', '12abc', null], ['int', 'Sp4rrow!', null], ['int', 1.5, null], ['int', '1.5', null], ['int', 1.0, '1'],
            ['float', 1.5, '1.5'], ['float', '0.25', '0.25'], ['float', '1.5.5', null], ['float', 'abc', null],
            ['port', 3306, '3306'], ['port', '443', '443'], ['port', '65535', '65535'], ['port', '65536', null], ['port', '80a', null], ['port', -1, null],
            // words from a set
            ['enum:local|production', 'production', 'production'], ['enum:local|production', 'Production', null], ['enum:local|production', 'prod', null], ['enum:local|production', ['local'], null],
            // hosts
            ['host', 'db.example.test', 'db.example.test'], ['host', 'localhost', 'localhost'], ['host', 'mailpit', 'mailpit'], ['host', '127.0.0.1', '127.0.0.1'], ['host', '[::1]', '[::1]'],
            ['host', 'smtp.mailgun.org', 'smtp.mailgun.org'], ['host', 'us-east-1.example.test', 'us-east-1.example.test'],
            ['host', 'Sp4rrow!', null], ['host', 'DB.example.test', null], ['host', 'db.example.test:3306', null], ['host', '999.1.1.1', null], ['host', 'a..b', null],
            ['host', '-bad.example.test', null], ['host', 'bad-.example.test', null], ['host', 'under_score.example.test', null],
            // the names a managed service gives out are long and carry digits, and are shown
            ['host', 'prod-db.cluster-c9akciq32.us-east-1.db.example.test', 'prod-db.cluster-c9akciq32.us-east-1.db.example.test'],
            ['host', 'us1-merry-cat-32748.cache.example.test', 'us1-merry-cat-32748.cache.example.test'], ['host', 'xn--e1afmkfd.xn--p1ai', 'xn--e1afmkfd.xn--p1ai'],
            ['host', 'a1b2c3d4e5f6g7.example.test', 'a1b2c3d4e5f6g7.example.test'], ['host', 'k9x2m4q7w1.example.test', 'k9x2m4q7w1.example.test'],
            ['hostport', 'prod-db.cluster-c9akciq32.us-east-1.db.example.test:3306', 'prod-db.cluster-c9akciq32.us-east-1.db.example.test:3306'],
            ['cookiehost', '.us1-merry-cat-32748.cache.example.test', '.us1-merry-cat-32748.cache.example.test'],
            // and a label that is a key is not
            ['host', '9f8e7d6c5b4a3928.example.test', null], ['host', '9f8e7d6c5b4a39281706deadbeef0123.example.test', null],
            ['host', '8f14e45f-ceea-467a-9575-3b7e1ba4e5c9.example.test', null], ['host', str_repeat('a', 39).'k.example.test', null],
            ['host', 'Hunter2.example.test', null], ['hostport', '9f8e7d6c5b4a3928.example.test:3306', null],
            // an address is not a name when it only looks like one
            ['host', '1.2.3.4.Hunter2', null], ['host', '1.2.3.4.5', null], ['host', '256.1.1.1', null], ['host', '1.2.3.4.', null], ['host', '01.2.3.4', null],
            ['host', '[::1', null], ['host', '[zz::1]', null], ['host', '[dead:beef]', null], ['host', '[:::]', null], ['host', '[2001:db8::1]', '[2001:db8::1]'],
            ['hostport', 'dead:beef', null], ['hostport', '2001:db8::1', '2001:db8::1'], ['hostport', 'a:1:2', null],
            ['hostport', 'localhost:3000', 'localhost:3000'], ['hostport', 'db.example.test', 'db.example.test'], ['hostport', '::1', '::1'], ['hostport', '[::1]:8000', '[::1]:8000'],
            ['hostport', 'db.example.test:99999', null], ['hostport', 'db.example.test:abc', null], ['hostport', 'a:1:2', null],
            ['cookiehost', '.example.test', '.example.test'], ['cookiehost', 'example.test', 'example.test'], ['cookiehost', '..example.test', null],
            // short names
            ['id', 'onboard', 'onboard'], ['id', 'us-east-1', 'us-east-1'], ['id', 'qr_exports.v2', 'qr_exports.v2'], ['id', 5, '5'], ['id', str_repeat('z', 32), str_repeat('z', 32)],
            ['id', str_repeat('a', 33), null], ['id', 'Onboard', null], ['id', 'Sp4rrow!', null], ['id', 'a b', null], ['id', '/var/lib', null],
            // addresses
            ['email', 'admin@onboard.ninja', 'admin@onboard.ninja'], ['email', 'hello+claims@example.test', 'hello+claims@example.test'],
            ['email', 'Admin@onboard.ninja', null], ['email', 'admin@@x', null], ['email', 'admin', null], ['email', 'admin@UPPER.test', null],
            // lists
            ['list:enum:preprod|preview|mainnet', ['preprod', 'preview'], 'preprod,preview'], ['list:enum:preprod|preview|mainnet', 'preprod, mainnet', 'preprod,mainnet'],
            ['list:enum:preprod|preview|mainnet', ['preprod', 'Sp4rrow!'], null], ['list:enum:preprod|preview|mainnet', 'preprod,nope', null], ['list:enum:preprod|preview|mainnet', [['preprod']], null],
            ['list:hostport', ['localhost', 'localhost:3000', '::1'], 'localhost,localhost:3000,::1'], ['list:hostport', ['localhost', 'Sp4rrow!'], null],
            ['list:hostport', ',,', null],
        ];

        return array_combine(
            array_map(fn (array $c) => $c[0].' '.json_encode($c[1]), $cases),
            $cases,
        );
    }

    public function test_a_list_is_proven_one_element_at_a_time(): void
    {
        // Joined, these would read as one odd string; apart, one of them is not a host.
        $this->assertSame($this->shown('a.example.test,b.example.test'), EnvMask::show('X', ['a.example.test', 'b.example.test'], 'list:host'));
        $this->assertSame(self::HIDDEN, EnvMask::show('X', ['a.example.test', 'hunter2!'], 'list:host'));
        $this->assertSame(self::HIDDEN, EnvMask::show('X', ['a.example.test', 'b.example.test', 'Sp4rrow!'], 'list:host'));
        $this->assertSame($this->shown('a.example.test,b.example.test'), EnvMask::show('X', 'a.example.test, b.example.test', 'list:host'));
    }

    public function test_an_unknown_type_shows_nothing(): void
    {
        $this->assertSame(self::HIDDEN, EnvMask::show('X', 'plain', 'text'));
        $this->assertSame(self::HIDDEN, EnvMask::show('X', 'plain', 'nonsense'));
    }

    public function test_an_object_is_never_shown(): void
    {
        $this->assertSame(self::HIDDEN, EnvMask::show('X', new \stdClass, 'id'));
        $this->assertSame(self::HIDDEN, EnvMask::show('X', new \stdClass, 'list:id'));
    }

    // ------------------------------------------------------------------ by name

    #[DataProvider('secretNames')]
    public function test_a_name_that_says_credential_hides_whatever_the_type_would_allow(string $name): void
    {
        $this->assertTrue(EnvMask::isSecretName($name), $name);
        $this->assertSame(self::HIDDEN, EnvMask::show($name, 'plainvalue', 'id'));
        $this->assertSame(['text' => 'unset', 'hidden' => true], EnvMask::show($name, '', 'id'));
    }

    public static function secretNames(): array
    {
        $names = [
            'API_KEY', 'SERVICE_API_KEY', 'APIKEY', 'ACCESSKEY', 'AWS_ACCESS_KEY_ID', 'ENCRYPTION_KEYS',
            'WEBHOOK_SECRET', 'CLIENT_SECRET', 'SECRET', 'MY_SECRET_THING',
            'POSTMARK_TOKEN', 'AUTH_TOKEN', 'ACCESSTOKEN', 'SESSION_TOKENS',
            'DB_PASSWORD', 'ADMIN_PASSWORD', 'ROOT_PASSWD', 'KEY_PASSPHRASE', 'DB_PASS', 'SERVICE_PWD',
            'PHYRHOSE_FUTURE_JWT', 'SENTRY_DSN', 'WALLET_MNEMONIC', 'WALLET_SEED', 'SIGNING_KEY_PATH',
            'PRIVATE_KEY', 'BANK_PRIVATE_DETAILS', 'PASSWORD_SALT', 'CREDENTIALS_FILE', 'SERVICE_CREDENTIAL',
            'OAUTH_CLIENT', 'AUTH', 'BEARER_VALUE', 'SLACK_WEBHOOK_URL', 'PAYMENT_HOOK', 'DB_USERNAME',
            'ONE_TIME_OTP', 'DEVICE_PIN', 'LICENSE_CODE', 'REQUEST_SIGNATURE',
        ];

        foreach (EnvMask::SECRET_NAMES as $name) {
            $names[] = $name;
        }

        return array_combine($names, array_map(fn (string $name) => [$name], $names));
    }

    public function test_a_connection_string_is_hidden_by_its_name_though_it_has_no_word_for_a_credential(): void
    {
        foreach (['DATABASE_URL', 'DATABASE_PREPROD_URL', 'DATABASE_MAINNET_URL', 'DB_URL', 'REDIS_URL', 'MAIL_URL'] as $name) {
            $this->assertTrue(EnvMask::isSecretName($name), $name);
            $this->assertSame(self::HIDDEN, EnvMask::show($name, 'mysql://db.example.test/onboard', 'url'), $name);
        }
    }

    public function test_a_name_that_says_nothing_about_a_credential_is_not_hidden_for_its_name(): void
    {
        foreach (['APP_URL', 'QUEUE_CONNECTION', 'CLAIM_RATE_PER_IP', 'MAIL_HOST', 'LOG_LEVEL', 'SESSION_COOKIE', 'PHYRHOSE_MAINNET_ID', 'KOIOS_MAINNET_URL', 'BCRYPT_ROUNDS'] as $name) {
            $this->assertFalse(EnvMask::isSecretName($name), $name);
        }
    }

    public function test_the_case_of_a_name_makes_no_difference(): void
    {
        $this->assertTrue(EnvMask::isSecretName('api_key'));
        $this->assertTrue(EnvMask::isSecretName('Db_Password'));
    }

    public function test_a_name_that_only_counts_tokens_is_not_a_credential_and_is_held_to_its_type(): void
    {
        foreach (['CLAIM_RATE_PER_TOKEN', 'CODE_API_MAX_TOKEN_QUANTITY', 'CODE_API_MAX_TOKENS_PER_CODE', 'CLAIM_CHECK_MAX_PER_PASS', 'DB_FOREIGN_KEYS'] as $name) {
            $this->assertFalse(EnvMask::isSecretName($name), $name);
            $this->assertSame($this->shown('300'), EnvMask::show($name, 300, 'int'), $name);
            $this->assertSame(self::HIDDEN, EnvMask::show($name, 'hunter2', 'int'), $name);
            $this->assertSame(self::HIDDEN, EnvMask::show($name, 300, null), $name);
        }
    }

    // ------------------------------------------------------------------ by shape

    #[DataProvider('secretShapes')]
    public function test_a_value_that_looks_like_a_credential_is_a_credential_whatever_it_is_called(string $value): void
    {
        $this->assertTrue(EnvMask::isSecretValue($value), $value);
    }

    public static function secretShapes(): array
    {
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4ifQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';

        return array_map(fn (string $v) => [$v], [
            'a signed token' => $jwt,
            'a quoted signed token' => '"'.$jwt.'"',
            'a signed token in single quotes' => "'".$jwt."'",
            'a signed token with a trailing dot' => 'eyJhbGciOiJub25lIn0.eyJzdWIiOiIxIn0.',
            'a signed token with a dot after the signature' => $jwt.'.',
            'a signed token with a trailing comma' => $jwt.',',
            'three long encoded parts' => 'aaaaaaaaaaaaaaaaaa.bbbbbbbbbbbbbbbbbbb.cccccccccccccccccc',
            'an application key' => 'base64:uO9S0xk3m1QpV2yJ7wZc4bNfA6tHdEgL8rXoIqYnMsU=',
            'a key with the prefix and nothing else' => 'base64:aGk=',
            '64 hex characters' => str_repeat('0f', 32),
            '32 hex characters' => 'd41d8cd98f00b204e9800998ecf8427e',
            '20 hex characters' => '9f8e7d6c5b4a39281706',
            '16 hex characters' => '9f8e7d6c5b4a3928',
            'hex with a prefix' => '0x'.str_repeat('ab12', 12),
            'a uuid' => '8f14e45f-ceea-467a-9575-3b7e1ba4e5c9',
            'an upper case uuid' => '8F14E45F-CEEA-467A-9575-3B7E1BA4E5C9',
            'a mail provider key' => 'key-3ax6xnjp29jd6fds4gc373sgvjxteol0',
            'an API token with its id' => '12|AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcd',
            'long base64' => 'dGhpcyBpcyBhIGxvbmcgYmFzZTY0IGVuY29kZWQgc2VjcmV0IHZhbHVlIDEyMw',
            'long base64 with url characters' => 'Zm9vYmFyLWJhel9xdXV4LTEyMzQ1Njc4OTBBQkNERUZHSElK',
            'a cardano signing key' => 'ed25519_sk1k4ehvdlhwksx3vg3chrjnqcw5v0n6jyyn7j4gm9tdwvxc4ym4yhq5j2f9w',
            'an extended signing key' => 'ed25519e_sk1qpmuvdz8uk5ge7qs5xmxrqs2f4gpe0hqrmqxyqjxxkj5jklrvfkcj8hfazt4wcp3d4k7q2p4fvvqxgpf3nqhkvqz2e5kpe5v',
            'a root key' => 'root_xsk1hq9k5n0xdgd3z2ymf0c5zgr2xlpd2xqmwcsmrpyaqgrlx8m7rffmzs8y0d58h6s9l0va4rdwm2qgfxm7qaum7elm9lhzz0d9gvc3z6qj8y5j2t7',
            'an extended private key' => 'xprv1qzqrx7ys8c9v6m5k4n3j2h1g0f9d8s7a6p5o4i3u2y1t0r9e8w7q6z5x4c3v2b1n0m9l8k7j6h5g4f3d2s1a',
            'a text envelope' => '{"type":"PaymentSigningKeyShelley_ed25519","cborHex":"5820abcd"}',
            'a pem block' => "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkq\n-----END PRIVATE KEY-----",
            'a recovery phrase of 24 words' => 'abandon ability able about above absent absorb abstract absurd abuse access accident account accuse achieve acid acoustic acquire across act action actor actress actual',
            'a recovery phrase of 12 words' => 'legal winner thank year wave sausage worth useful legal winner thank yellow',
            'a capitalised recovery phrase' => 'Legal Winner Thank Year Wave Sausage Worth Useful Legal Winner Thank Yellow',
            'a recovery phrase joined by commas' => 'legal,winner,thank,year,wave,sausage,worth,useful,legal,winner,thank,yellow',
            'a recovery phrase joined by commas and spaces' => 'legal, winner, thank, year, wave, sausage, worth, useful, legal, winner, thank, yellow',
            'a secret key of a payment service' => 'sk_live_'.'51H8aBcDeFgHiJkLmNoPqRsTuVwXyZ',
            'a chat token' => 'xoxb-1234567890-0987654321-AbCdEfGhIjKlMnOpQrStUvWx',
            'a code host token' => 'ghp_aBcDeFgHiJkLmNoPqRsTuVwXyZ0123456789',
            'a cloud access key id' => 'AKIAIOSFODNN7EXAMPLE',
            'a mail service key' => 'SG.AbCdEfGhIjKlMnOpQrStUv.WxYz0123456789AbCdEfGhIjKlMnOpQrStUvWxYz',
            'user and password with no scheme' => 'deploy:hunter2@db.internal',
            'a header' => 'Authorization: Bearer abcdef0123456789',
            'a bearer value' => 'Bearer abcdef0123456789abcdef',
            'a setting with a password in it' => 'host=db.internal;user=app;password=hunter2',
            'a token in a sentence' => 'Maintenance tonight, token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.abcdefghijklmnop',
            'a key in a list' => 'localhost,0123456789abcdef0123456789abcdef0123,127.0.0.1',
        ]);
    }

    #[DataProvider('plainValues')]
    public function test_an_ordinary_value_is_not_mistaken_for_a_credential(string $value): void
    {
        $this->assertFalse(EnvMask::isSecretValue($value), $value);
    }

    public static function plainValues(): array
    {
        return array_map(fn (string $v) => [$v], [
            'https://app.example.test', 'preprod,preview,mainnet', 'localhost,localhost:3000,127.0.0.1', '/var/www/html/storage/framework/views',
            'storage/framework/views', 'Onboard.Ninja', 'camera=(), microphone=(), geolocation=()', 'smtp.mailgun.org', 'hello@example.com',
            'Monolog\\Handler\\SyslogUdpHandler', 'onboard_ninja_session', 'database-uuids', 'We are upgrading the claim service tonight, please come back at nine.',
            '/usr/sbin/sendmail -bs -i', '0123456789', 'abcdefghijklmnopqrstuvwxyzabcdef', 'preprod', 'a b c d e f g h i j k l', 'us-east-1', '2026-10-01',
        ]);
    }

    public function test_the_shape_checks_hide_a_value_the_type_allowed(): void
    {
        // Each of these is a legal short name, and each looks like a credential.
        foreach (['0123456789abcdef0123456789abcdef', 'key-3ax6xnjp29jd6fds4gc3', '9f8e7d6c5b4a3928', 'ghp_aBcDeFgHiJkLmNoPqRsTuVwX'] as $value) {
            $this->assertSame(self::HIDDEN, EnvMask::show('SOME_NAME', $value, 'id'), $value);
        }

        $this->assertSame(self::HIDDEN, EnvMask::show('SOME_NAME', ['localhost', '0123456789abcdef0123456789abcdef'], 'list:id'));
    }

    // ------------------------------------------------------------------ addresses

    #[DataProvider('addresses')]
    public function test_an_address_is_shown_without_what_could_carry_a_credential(string $address, ?string $expected, array $mustNotAppear): void
    {
        $shown = EnvMask::show('APP_NOTICE_LINK_URL', $address, 'url');

        $this->assertSame($expected === null ? self::HIDDEN : $this->shown($expected), $shown, $address);

        foreach ($mustNotAppear as $secret) {
            $this->assertStringNotContainsString($secret, $shown['text']);
        }
    }

    public static function addresses(): array
    {
        return [
            'a plain address' => ['https://app.example.test', 'https://app.example.test', []],
            'a port' => ['http://localhost:8000/storage', 'http://localhost:8000/storage', []],
            'plain path words' => ['https://api.example.test/api/v1/proxy', 'https://api.example.test/api/v1/proxy', []],
            'a file name' => ['https://app.example.test/assets/app.js', 'https://app.example.test/assets/app.js', []],
            'user only' => ['redis://admin@cache.example.test:6379', 'redis://cache.example.test:6379', ['admin']],
            'empty user and a password' => ['redis://:p4ssw0rd@cache.example.test', 'redis://cache.example.test', ['p4ssw0rd']],
            'a password that holds an @' => ['https://user:pa@ss@host.example.test/x', 'https://host.example.test/x', ['pa@ss', 'user']],
            'a password that holds a slash' => ['mysql://dbuser:Sp4rr/ow99@db.example.test:3306/onboard', 'mysql://db.example.test:3306/onboard', ['dbuser', 'Sp4rr', 'ow99']],
            'a password that holds a question mark' => ['mysql://dbuser:Sp4rr?ow99@db.example.test/onboard', 'mysql://db.example.test/onboard', ['dbuser', 'Sp4rr', 'ow99']],
            'a password that holds a hash' => ['mysql://dbuser:Sp4rr#ow99@db.example.test/onboard', 'mysql://db.example.test/onboard', ['dbuser', 'Sp4rr', 'ow99']],
            'a password that holds all three' => ['mysql://dbuser:p/a?s#s@db.example.test/onboard', 'mysql://db.example.test/onboard', ['dbuser', 'p/a', 's#s']],
            'every query value goes' => ['https://x.example.test/v1?token=AbCd1234EfGh5678&page=2&t=ab&pw=x', 'https://x.example.test/v1?token=***&page=***&t=***&pw=***', ['AbCd1234', 'ab', '=x']],
            'a query key with no value' => ['https://x.example.test/?flag&other=1', 'https://x.example.test/?flag&other=***', []],
            'a query key that is not a plain word' => ['https://x.example.test/?Sp4rr0w!', 'https://x.example.test/?***', ['Sp4rr0w']],
            'a fragment is dropped' => ['https://x.example.test/cb#access_token=abc123', 'https://x.example.test/cb', ['abc123']],
            'a path token' => ['https://hooks.example.test/services/T01ABCDEF/B02GHIJKL/Xy12Ab34Cd56Ef78Gh90Ij12', 'https://hooks.example.test/services/***/***/***', ['T01ABCDEF', 'Xy12Ab34']],
            'a short reset token in the path' => ['https://app.example.test/reset/a1b2c3d4e5f6', 'https://app.example.test/reset/***', ['a1b2c3d4e5f6']],
            'a 16 hex key in the path' => ['https://app.example.test/hook/9f8e7d6c5b4a3928', 'https://app.example.test/hook/***', ['9f8e7d6c5b4a3928']],
            'an account number in the path' => ['https://sqs.example.test/123456789012/default', 'https://sqs.example.test/***/default', ['123456789012']],
            'a mixed case segment' => ['https://app.example.test/Reset/Abc', 'https://app.example.test/***/***', ['Reset']],
            'a long segment' => ['https://app.example.test/this-is-a-very-long-path-segment-indeed', 'https://app.example.test/***', ['very-long']],
            'a trailing slash' => ['https://app.example.test/storage/', 'https://app.example.test/storage/', []],
            // addresses that cannot be cleaned with confidence are hidden whole
            'a key as a host label' => ['https://k9x2m4q7w1.ingest.example.test/collect', null, ['k9x2m4q7w1']],
            'a hex host label' => ['https://9f8e7d6c5b4a39281706.example.test/', null, ['9f8e7d6c5b4a']],
            'a managed host name inside an address is held to the strict rule' => ['https://prod-db.cluster-c9akciq32.us-east-1.db.example.test/', null, ['c9akciq32']],
            'a bare query key that is a token' => ['https://x.example.test/cb?ZqsklivexK7p2', 'https://x.example.test/cb?***', ['ZqsklivexK7p2']],
            'a bare query key that is a service key' => ['https://x.example.test/cb?sk_live_abcdefgh1234', 'https://x.example.test/cb?***', ['sk_live']],
            'a query key that is a service key with a value' => ['https://x.example.test/cb?sk_live_abcdefgh1234=1', 'https://x.example.test/cb?***=***', ['sk_live']],
            'a bare query key that is a service key with no digits in it' => ['https://x.example.test/cb?sk_live_abcdefghijkl', 'https://x.example.test/cb?***', ['sk_live']],
            'a query key that is a code host token with no digits in it' => ['https://x.example.test/cb?ghp_abcdefghijklmnopqrst=1', 'https://x.example.test/cb?***=***', ['ghp_']],
            'a query key with digits' => ['https://x.example.test/cb?page2=1', 'https://x.example.test/cb?***=***', ['page2']],
            'a path segment that is a service key' => ['https://x.example.test/sk_live_abcdefghijkl/ok', 'https://x.example.test/***/ok', ['sk_live']],
            'a capitalised host' => ['https://App.Example.test/', null, []],
            'a host with an odd character' => ['https://exa!mple.test/', null, []],
            'a port out of range' => ['https://app.example.test:99999/', null, []],
            'no scheme' => ['app.example.test/path', null, []],
            'a capitalised scheme' => ['HTTPS://app.example.test', null, []],
            'a space in it' => ['https://app.example.test/a b', null, []],
            'a line break in it' => ["https://app.example.test/a\nb", null, []],
            'an empty host' => ['https:///path', null, []],
            'a very long one' => ['https://app.example.test/'.str_repeat('a', 2100), null, []],
        ];
    }

    public function test_an_address_without_a_scheme_or_with_userinfo_only_is_never_shown_raw(): void
    {
        foreach (['svc:Sp4rrow!@db.example.test', 'https://:Sp4rrow!@', 'https://Sp4rrow!@'] as $value) {
            $shown = EnvMask::show('APP_URL', $value, 'url');

            $this->assertStringNotContainsString('Sp4rrow', $shown['text'], $value);
        }
    }
}

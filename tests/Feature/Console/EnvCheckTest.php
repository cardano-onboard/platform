<?php

namespace Tests\Feature\Console;

use App\Support\EnvReadiness;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * poo:env-check. Each profile starts from an environment on which every rule passes and each
 * case breaks exactly one thing, so a rule that stopped looking at what it names leaves its
 * case failing, and a case cannot pass because something else was already wrong.
 */
class EnvCheckTest extends TestCase
{
    private const SECRETS = [
        'SENTINEL-HEADER.SENTINEL-BODY.SENTINEL-SIG',
        'SENTINEL-POSTMARK',
        'SENTINEL-ADMIN-PW',
        'SENTINEL-DB-PW',
        'SENTINEL-PROXY',
        'SENTINEL-KOIOS',
        'SENTINEL-NETWORK-ID',
    ];

    private function ready(string $profile): void
    {
        $production = $profile === 'production';
        $staging = $profile === 'staging';
        $network = $production ? 'mainnet' : 'preprod';

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'app.env' => $profile === 'self-hosted' ? 'production' : $profile,
            'app.debug' => false,
            'app.url' => 'https://app.example.test',
            'session.secure' => true,
            'session.driver' => 'redis',
            'cache.default' => 'redis',
            'logging.channels.single.level' => 'warning',
            'admin.password' => 'SENTINEL-ADMIN-PW',
            'sanctum.stateful' => ['app.example.test'],
            'security.csp_override' => '',
            'database.default' => 'mysql',
            'database.connections.mysql.host' => 'db.example.test',
            'database.connections.mysql.password' => 'SENTINEL-DB-PW',
            'queue.default' => 'database',
            'queue.connections.database.retry_after' => 1200,
            'housekeeping.on_traffic' => true,
            'cardano.transaction_backend' => 'phyrhose',
            'cardano.allowed_networks' => $staging ? ['preprod', 'preview'] : [$network],
            'cardano.mainnet_app_url' => null,
            'cardano.phyrhose.mainnet_id' => $production ? 'SENTINEL-NETWORK-ID' : '',
            'cardano.phyrhose.mainnet_jwt' => $production ? 'SENTINEL-HEADER.SENTINEL-BODY.SENTINEL-SIG' : '',
            'cardano.phyrhose.mainnet_url' => 'https://api.phyrhose.io/',
            'cardano.phyrhose.preprod_id' => $production ? '' : 'SENTINEL-NETWORK-ID',
            'cardano.phyrhose.preprod_jwt' => $production ? '' : 'SENTINEL-HEADER.SENTINEL-BODY.SENTINEL-SIG',
            'cardano.phyrhose.preprod_url' => 'https://testnet.phyrhose.io/',
            'cardano.koios.token' => 'SENTINEL-KOIOS',
            'cardano.proxy_api_token' => 'SENTINEL-PROXY',
            'cardano.proxy_api_url' => 'https://relay.example.test/api/v1/proxy',
            'cardano.claim_domain' => 'claim.example.test',
            'cardano.ada.domain' => null,
            'cardano.qr_storage.disk' => null,
            'mail.default' => 'postmark',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.mailers.smtp.port' => 1,
            'mail.from.address' => 'hello@claims.example.test',
            'services.postmark.token' => 'SENTINEL-POSTMARK',
            'services.mailgun.domain' => 'mg.example.test',
            'services.mailgun.secret' => 'secret',
            'services.ses.key' => 'key',
            'services.ses.secret' => 'secret',
            'filesystems.default' => 'private',
            'filesystems.disks.private' => ['driver' => 's3', 'bucket' => 'bucket', 'region' => 'us-east-1'],
            'notice.message' => null,
            'notice.type' => 'info',
        ]);
    }

    private function findings(string $profile, ?array $raw = null): array
    {
        $check = new EnvReadiness($profile, fn (string $name) => $raw[$name] ?? null);
        $rows = [];

        foreach ($check->run() as $finding) {
            $rows[$finding['name']][] = $finding['status'];
        }

        return $rows;
    }

    /** Whether any row for $name has $status. */
    private function assertFinding(array $rows, string $name, string $status): void
    {
        $this->assertContains($status, $rows[$name] ?? [], "{$name} should be {$status}, got ".json_encode($rows[$name] ?? 'no row'));
    }

    #[DataProvider('profiles')]
    public function test_a_ready_environment_has_no_findings_but_ok(string $profile): void
    {
        $this->ready($profile);

        foreach ($this->findings($profile) as $name => $statuses) {
            $this->assertSame([EnvReadiness::OK], array_values(array_unique($statuses)), "{$name} on {$profile}: ".json_encode($statuses));
        }
    }

    public static function profiles(): array
    {
        return [['production'], ['staging'], ['self-hosted']];
    }

    /**
     * profile, config overrides, raw variables, expected row name and status.
     */
    #[DataProvider('breakages')]
    public function test_each_rule_reports_what_it_names(string $profile, array $config, array $raw, string $name, string $status): void
    {
        $this->ready($profile);
        config($config);

        $this->assertFinding($this->findings($profile, $raw), $name, $status);
    }

    public static function breakages(): array
    {
        $jwt = 'a.b.c';
        $cases = [
            // application
            ['production', ['app.key' => ''], [], 'APP_KEY', 'missing'],
            ['production', ['app.key' => 'base64:'.base64_encode('short')], [], 'APP_KEY', 'wrong'],
            ['production', ['app.env' => 'local'], [], 'APP_ENV', 'wrong'],
            ['staging', ['app.env' => 'local'], [], 'APP_ENV', 'warning'],
            ['production', ['app.debug' => true], [], 'APP_DEBUG', 'wrong'],
            ['self-hosted', ['app.debug' => true], [], 'APP_DEBUG', 'warning'],
            ['production', ['app.url' => 'http://app.example.test'], [], 'APP_URL', 'wrong'],
            ['staging', ['app.url' => 'https://localhost'], [], 'APP_URL', 'wrong'],
            ['self-hosted', ['app.url' => 'http://localhost'], [], 'APP_URL', 'warning'],
            ['production', ['session.secure' => false], [], 'SESSION_SECURE_COOKIE', 'warning'],
            ['production', ['logging.channels.single.level' => 'debug'], [], 'LOG_LEVEL', 'warning'],
            ['production', ['admin.password' => 'password'], [], 'ADMIN_PASSWORD', 'warning'],
            ['self-hosted', ['admin.password' => 'password'], [], 'ADMIN_PASSWORD', 'wrong'],
            ['self-hosted', ['admin.password' => 'changeme123'], [], 'ADMIN_PASSWORD', 'wrong'],
            ['staging', ['admin.password' => 'password'], [], 'ADMIN_PASSWORD', 'warning'],
            ['production', ['sanctum.stateful' => ['localhost']], [], 'SANCTUM_STATEFUL_DOMAINS', 'warning'],
            ['production', ['security.csp_override' => "default-src 'self'"], [], 'CONTENT_SECURITY_POLICY', 'warning'],
            // database, queue, stores
            ['production', ['database.default' => 'sqlite'], [], 'DB_CONNECTION', 'wrong'],
            ['production', ['database.connections.mysql.host' => '127.0.0.1'], [], 'DB_HOST', 'warning'],
            ['production', ['database.connections.mysql.password' => ''], [], 'DB_PASSWORD', 'warning'],
            ['production', ['queue.default' => 'sync'], [], 'QUEUE_CONNECTION', 'wrong'],
            ['staging', ['queue.default' => 'deferred'], [], 'QUEUE_CONNECTION', 'wrong'],
            ['self-hosted', ['queue.default' => 'sync'], [], 'QUEUE_CONNECTION', 'warning'],
            ['production', ['queue.connections.database.retry_after' => 600], [], 'QUEUE_RETRY_AFTER', 'wrong'],
            ['production', ['cardano.tasks.stale_after_seconds' => 600], [], 'QR_EXPORT_JOB_TIMEOUT', 'warning'],
            ['production', ['housekeeping.on_traffic' => false], [], 'HOUSEKEEPING_ON_TRAFFIC', 'warning'],
            ['production', ['cache.default' => 'file'], [], 'CACHE_DRIVER', 'warning'],
            ['staging', ['session.driver' => 'array'], [], 'SESSION_DRIVER', 'warning'],
            // transaction backend
            ['production', ['cardano.transaction_backend' => null], [], 'TRANSACTION_BACKEND', 'wrong'],
            ['production', ['cardano.transaction_backend' => 'null'], [], 'TRANSACTION_BACKEND', 'wrong'],
            ['staging', ['cardano.transaction_backend' => 'null'], [], 'TRANSACTION_BACKEND', 'warning'],
            ['self-hosted', ['cardano.transaction_backend' => 'null'], [], 'TRANSACTION_BACKEND', 'warning'],
            ['production', ['cardano.transaction_backend' => 'bogus'], [], 'TRANSACTION_BACKEND', 'wrong'],
            ['self-hosted', ['cardano.transaction_backend' => 'proxy', 'cardano.proxy_api_token' => ''], [], 'PROXY_API_TOKEN', 'missing'],
            ['self-hosted', ['cardano.transaction_backend' => 'proxy', 'cardano.proxy_api_url' => 'http://relay.example.test'], [], 'PROXY_API_URL', 'wrong'],
            ['production', ['cardano.phyrhose.mainnet_id' => ''], [], 'PHYRHOSE_MAINNET_ID', 'missing'],
            ['production', ['cardano.phyrhose.mainnet_id' => "abc\n"], [], 'PHYRHOSE_MAINNET_ID', 'wrong'],
            ['production', ['cardano.phyrhose.preprod_id' => 'SENTINEL-NETWORK-ID'], [], 'PHYRHOSE_MAINNET_ID', 'wrong'],
            ['production', ['cardano.phyrhose.mainnet_jwt' => ''], [], 'PHYRHOSE_MAINNET_JWT', 'missing'],
            ['production', ['cardano.phyrhose.mainnet_jwt' => 'notatoken'], [], 'PHYRHOSE_MAINNET_JWT', 'wrong'],
            ['production', ['cardano.phyrhose.mainnet_url' => 'http://api.phyrhose.io/'], [], 'PHYRHOSE_MAINNET_URL', 'wrong'],
            ['production', ['cardano.phyrhose.mainnet_url' => 'https://testnet.phyrhose.io/', 'cardano.phyrhose.preprod_url' => 'https://preprod.phyrhose.example/'], [], 'PHYRHOSE_MAINNET_URL', 'wrong'],
            ['staging', ['cardano.phyrhose.preprod_id' => ''], [], 'PHYRHOSE_PREPROD_ID', 'missing'],
            ['staging', ['cardano.phyrhose.preprod_jwt' => ''], [], 'PHYRHOSE_PREPROD_JWT', 'missing'],
            ['staging', ['cardano.phyrhose.mainnet_jwt' => $jwt], [], 'PHYRHOSE_MAINNET_JWT', 'warning'],
            ['production', ['cardano.allowed_networks' => ['mainnet', 'preprod']], [], 'PHYRHOSE_PREPROD_ID', 'warning'],
            ['production', ['cardano.phyrhose.preprod_jwt' => 'SENTINEL-HEADER.SENTINEL-BODY.SENTINEL-SIG'], [], 'PHYRHOSE_MAINNET_JWT', 'wrong'],
            ['production', ['cardano.phyrhose.preprod_url' => 'https://api.phyrhose.io/'], [], 'PHYRHOSE_MAINNET_URL', 'wrong'],
            ['self-hosted', ['cardano.allowed_networks' => ['mainnet'], 'cardano.phyrhose.mainnet_id' => ''], [], 'PHYRHOSE_MAINNET_ID', 'missing'],
            // networks
            ['production', ['cardano.allowed_networks' => []], [], 'ALLOWED_NETWORKS', 'wrong'],
            ['production', ['cardano.allowed_networks' => ['mainnet', 'mainet']], [], 'ALLOWED_NETWORKS', 'wrong'],
            ['production', ['cardano.allowed_networks' => ['preprod']], [], 'ALLOWED_NETWORKS', 'wrong'],
            ['staging', ['cardano.allowed_networks' => ['preprod', 'mainnet']], [], 'ALLOWED_NETWORKS', 'wrong'],
            ['staging', ['cardano.mainnet_app_url' => 'http://app.example.test'], [], 'MAINNET_APP_URL', 'wrong'],
            // mail
            ['production', ['mail.default' => 'log'], [], 'MAIL_MAILER', 'wrong'],
            ['self-hosted', ['mail.default' => 'array'], [], 'MAIL_MAILER', 'warning'],
            ['production', ['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit'], [], 'MAIL_HOST', 'wrong'],
            ['production', ['services.postmark.token' => ''], [], 'POSTMARK_TOKEN', 'missing'],
            ['production', ['mail.default' => 'mailgun', 'services.mailgun.secret' => ''], [], 'MAILGUN_SECRET', 'missing'],
            ['production', ['mail.default' => 'ses', 'services.ses.key' => ''], [], 'AWS_ACCESS_KEY_ID', 'missing'],
            ['production', ['mail.from.address' => 'hello@example.com'], [], 'MAIL_FROM_ADDRESS', 'wrong'],
            // storage
            ['production', ['filesystems.disks.private.bucket' => ''], [], 'AWS_BUCKET', 'missing'],
            ['production', ['filesystems.disks.private.region' => ''], [], 'AWS_DEFAULT_REGION', 'missing'],
            ['production', ['filesystems.default' => 'local'], [], 'FILESYSTEM_DISK', 'warning'],
            ['production', ['cardano.qr_storage.disk' => 'nowhere'], [], 'QR_STORAGE_DISK', 'wrong'],
            // hosts and notice
            ['production', ['cardano.claim_domain' => 'https://claim.example.test'], [], 'CLAIM_DOMAIN', 'wrong'],
            ['production', ['cardano.claim_domain' => null], [], 'CLAIM_DOMAIN', 'warning'],
            ['production', ['cardano.ada.domain' => 'ada.example.test/path'], [], 'ADA_DOMAIN', 'wrong'],
            ['production', ['notice.message' => 'Preprod only'], [], 'APP_NOTICE', 'warning'],
            ['staging', ['notice.message' => 'Preprod only', 'notice.type' => 'loud'], [], 'APP_NOTICE_TYPE', 'warning'],
            // claim timing and retired names
            ['production', ['cardano.push_delay_seconds' => 300], ['POO_PUSH_DELAY' => '5'], 'POO_PUSH_DELAY_SECONDS', 'warning'],
            ['production', ['cardano.phyrhose.submit_timeout_seconds' => 5], [], 'PHYRHOSE_SUBMIT_TIMEOUT_SECONDS', 'warning'],
            ['production', [], ['MIN_UTXO_HEADROOM_LOVELACE' => '500000'], 'MIN_UTXO_HEADROOM_LOVELACE', 'warning'],
            ['production', [], ['PASSPORT_API_MAX_TOKENS_PER_CODE' => '5'], 'PASSPORT_API_MAX_TOKENS_PER_CODE', 'warning'],
            ['production', [], ['PASSPORT_API_CREATE_RATE_PER_TOKEN' => '5'], 'PASSPORT_API_CREATE_RATE_PER_TOKEN', 'warning'],
            // numbers
            ['production', ['cardano.min_utxo.address_bytes' => 10], [], 'MIN_UTXO_ADDRESS_BYTES', 'wrong'],
            ['production', ['cardano.min_utxo.address_bytes' => 100], [], 'MIN_UTXO_ADDRESS_BYTES', 'wrong'],
            ['production', ['cardano.koios.max_body_bytes' => 100], [], 'KOIOS_MAX_BODY_BYTES', 'warning'],
            ['production', ['cardano.qr_storage.retention_days' => 1], [], 'QR_EXPORT_RETENTION_DAYS', 'warning'],
        ];

        foreach (self::positiveLimits() as $name => $key) {
            $cases[] = ['production', [$key => 0], [], $name, 'wrong'];
        }

        $keyed = [];

        foreach ($cases as $i => $case) {
            $keyed["{$i} {$case[0]} {$case[3]} {$case[4]}"] = $case;
        }

        return $keyed;
    }

    public static function positiveLimits(): array
    {
        return [
            'NETWORK_FEE_LOVELACE' => 'cardano.network_fee_lovelace',
            'COINS_PER_UTXO_BYTE' => 'cardano.min_utxo.coins_per_utxo_byte',
            'UPLOAD_MAX_FILE_SIZE' => 'cardano.max_file_size',
            'UPLOAD_MAX_CODES' => 'cardano.max_codes',
            'CLAIM_RATE_PER_IP' => 'cardano.claim_rate_per_ip',
            'CLAIM_RATE_PER_CAMPAIGN' => 'cardano.claim_rate_per_campaign',
            'CLAIM_RATE_PER_TOKEN' => 'cardano.claim_rate_per_token',
            'CODE_API_CREATE_RATE_PER_TOKEN' => 'cardano.code_api.create_rate_per_token',
            'CODE_API_STATUS_RATE_PER_TOKEN' => 'cardano.code_api.status_rate_per_token',
            'CODE_API_MAX_TOKENS_PER_CODE' => 'cardano.code_api.max_tokens_per_code',
            'CODE_API_MAX_TOKEN_QUANTITY' => 'cardano.code_api.max_token_quantity',
            'PHYRHOSE_TIMEOUT_SECONDS' => 'cardano.phyrhose.timeout_seconds',
            'PHYRHOSE_CONNECT_TIMEOUT_SECONDS' => 'cardano.phyrhose.connect_timeout_seconds',
            'PHYRHOSE_SUBMIT_TIMEOUT_SECONDS' => 'cardano.phyrhose.submit_timeout_seconds',
            'KOIOS_TIMEOUT' => 'cardano.koios.timeout',
            'KOIOS_RETRIES' => 'cardano.koios.retries',
            'TASK_STALE_AFTER_SECONDS' => 'cardano.tasks.stale_after_seconds',
            'QR_STORAGE_TTL_DAYS' => 'cardano.qr_storage.ttl_days',
            'QR_EXPORT_CHUNK_SIZE' => 'cardano.qr_storage.chunk_size',
            'QR_EXPORT_RESUME_WINDOW' => 'cardano.qr_storage.resume_window_minutes',
            'HOUSEKEEPING_CHECK_EVERY_SECONDS' => 'housekeeping.check_every_seconds',
            'HOUSEKEEPING_ASSET_SYNC_EVERY_MINUTES' => 'housekeeping.tasks.sync-known-assets.every_minutes',
            'HOUSEKEEPING_QR_PRUNE_EVERY_MINUTES' => 'housekeeping.tasks.prune-qr-exports.every_minutes',
        ];
    }

    public function test_an_earlier_name_with_its_new_name_set_is_not_reported(): void
    {
        $this->ready('production');

        $rows = $this->findings('production', ['PASSPORT_API_MAX_TOKENS_PER_CODE' => '5', 'CODE_API_MAX_TOKENS_PER_CODE' => '5']);

        $this->assertArrayNotHasKey('PASSPORT_API_MAX_TOKENS_PER_CODE', $rows);
    }

    public function test_the_earlier_push_delay_is_only_a_hosted_concern(): void
    {
        $this->ready('self-hosted');

        $rows = $this->findings('self-hosted', ['POO_PUSH_DELAY' => '5']);

        $this->assertFinding($rows, 'POO_PUSH_DELAY_SECONDS', 'ok');
    }

    public function test_a_cached_configuration_that_hides_the_legacy_names_is_unknown_not_ok(): void
    {
        $this->ready('production');

        $cached = new EnvReadiness('production', fn () => null, true);
        $rows = [];

        foreach ($cached->run() as $finding) {
            $rows[$finding['name']][] = $finding;
        }

        foreach (['POO_PUSH_DELAY_SECONDS', 'MIN_UTXO_HEADROOM_LOVELACE', 'PASSPORT_API_MAX_TOKENS_PER_CODE'] as $name) {
            $this->assertSame('warning', $rows[$name][0]['status'], $name);
            $this->assertStringContainsString('Unknown', $rows[$name][0]['reason']);
            $this->assertStringContainsString("host's variables", $rows[$name][0]['reason']);
        }

        // A value the process can see is judged as usual even when the configuration is cached.
        $seen = new EnvReadiness('production', fn (string $n) => $n === 'MIN_UTXO_HEADROOM_LOVELACE' ? '1' : null, true);
        $reasons = array_column(array_filter($seen->run(), fn ($f) => $f['name'] === 'MIN_UTXO_HEADROOM_LOVELACE'), 'reason');
        $this->assertStringNotContainsString('Unknown', $reasons[0]);
    }

    public function test_a_current_push_delay_setting_clears_the_earlier_one_warning(): void
    {
        $this->ready('production');

        $rows = $this->findings('production', ['POO_PUSH_DELAY' => '5', 'POO_PUSH_DELAY_SECONDS' => '30']);

        $this->assertFinding($rows, 'POO_PUSH_DELAY_SECONDS', 'ok');
    }

    public function test_every_reason_that_is_not_ok_names_its_variable_and_leads_with_redeploy(): void
    {
        $this->ready('production');
        config(['app.debug' => true, 'queue.default' => 'sync', 'cardano.phyrhose.mainnet_id' => '']);

        $failing = array_filter(
            (new EnvReadiness('production'))->run(),
            fn ($f) => $f['status'] !== EnvReadiness::OK,
        );

        $this->assertNotEmpty($failing);

        foreach ($failing as $finding) {
            $this->assertStringStartsWith('Redeploy with ', $finding['reason']);
            $this->assertMatchesRegularExpression('/[A-Z][A-Z0-9_]{3,}/', $finding['reason']);
        }
    }

    public function test_the_command_fails_on_a_wrong_value_and_passes_on_a_ready_environment(): void
    {
        $this->ready('production');

        $this->assertSame(0, Artisan::call('poo:env-check'));

        config(['app.debug' => true]);

        $this->assertSame(1, Artisan::call('poo:env-check'));
        $this->assertStringContainsString('APP_DEBUG', Artisan::output());
    }

    public function test_a_warning_fails_the_command_only_under_strict(): void
    {
        $this->ready('production');
        config(['session.secure' => false]);

        $this->assertSame(0, Artisan::call('poo:env-check'));
        $this->assertSame(1, Artisan::call('poo:env-check', ['--strict' => true]));
    }

    public function test_an_unknown_profile_is_refused(): void
    {
        $this->ready('production');
        $this->assertSame(1, Artisan::call('poo:env-check', ['--profile' => 'prod']));
    }

    #[DataProvider('profiles')]
    public function test_no_secret_value_is_ever_printed(string $profile): void
    {
        $outputs = [];
        Http::fake(['*' => Http::response('SENTINEL-NETWORK-ID', 401)]);

        // Valid secrets: the rows that say "set" must not say what.
        $this->ready($profile);
        Artisan::call('poo:env-check', ['--profile' => $profile, '--live' => true]);
        $outputs[] = Artisan::output();

        // Secrets of the wrong shape, and a proxy backend with its token.
        $this->ready($profile);
        config([
            'cardano.phyrhose.mainnet_jwt' => 'SENTINEL-HEADER.SENTINEL-BODY.SENTINEL-SIG with space',
            'cardano.phyrhose.preprod_jwt' => 'SENTINEL-HEADER.SENTINEL-BODY.SENTINEL-SIG with space',
            'cardano.phyrhose.mainnet_id' => "SENTINEL-NETWORK-ID\n",
            'cardano.phyrhose.preprod_id' => "SENTINEL-NETWORK-ID\n",
            'admin.password' => 'password',
            'database.connections.mysql.host' => '127.0.0.1',
            'app.key' => 'SENTINEL-NETWORK-ID',
        ]);
        Artisan::call('poo:env-check', ['--profile' => $profile, '--live' => true]);
        $outputs[] = Artisan::output();

        $this->ready($profile);
        config(['cardano.transaction_backend' => 'proxy', 'cardano.proxy_api_url' => 'http://relay.example.test']);
        Artisan::call('poo:env-check', ['--profile' => $profile, '--live' => true]);
        $outputs[] = Artisan::output();

        foreach ($outputs as $output) {
            foreach (self::SECRETS as $secret) {
                $this->assertStringNotContainsString($secret, $output);
            }
        }

        $this->assertStringContainsString('WRONG', $outputs[1]);
    }

    #[DataProvider('profiles')]
    public function test_a_message_never_states_a_value_that_is_not_one_the_setting_allows(string $profile): void
    {
        $this->ready($profile);

        $hostile = 'Sp4rrow!NotAnEnvironment';

        config([
            'app.env' => $hostile,
            'database.default' => $hostile,
            'queue.default' => $hostile,
            'cache.default' => $hostile,
            'session.driver' => $hostile,
            'cardano.transaction_backend' => $hostile,
            'mail.default' => $hostile,
            'filesystems.default' => $hostile,
            'cardano.qr_storage.disk' => $hostile,
            'cardano.claim_domain' => $hostile,
            'cardano.min_utxo.address_bytes' => $hostile,
            'cardano.claim_rate_per_ip' => $hostile,
        ]);

        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake();

        $check = new EnvReadiness($profile);
        $text = json_encode(array_merge($check->run(), $check->live(2)));

        $this->assertStringNotContainsString('Sp4rrow', $text);
        $this->assertStringNotContainsString('NotAnEnvironment', $text);

        // And the command's own output says the same.
        Artisan::call('poo:env-check', ['--profile' => $profile, '--live' => true]);
        $this->assertStringNotContainsString('Sp4rrow', Artisan::output());
    }

    public function test_a_message_still_states_a_value_the_setting_allows(): void
    {
        $this->ready('staging');
        config(['app.env' => 'local', 'queue.default' => 'sync']);

        $text = json_encode((new EnvReadiness('staging'))->run());

        $this->assertStringContainsString('It is \"local\", which is a development value.', $text);
        $this->assertStringContainsString('It is \"sync\"', $text);
    }

    /**
     * A host on the native backend with every native setting usable on the network the profile
     * serves.
     */
    private function native(string $profile): void
    {
        $this->ready($profile);

        config([
            'cardano.transaction_backend' => 'native',
            'campaign_script.owner_key_hashes' => [str_repeat('01', 28), str_repeat('02', 28)],
            'campaign_script.wrapping_key_version' => 1,
            'campaign_script.wrapping_keys' => [1 => base64_encode(str_repeat('w', 32))],
            'campaign_script.stake_addresses' => [
                'mainnet' => 'stake1uyehkck0lajq8gr28t9uxnuvgcqrc6070x3k9r8048z8y5gh6ffgw',
                'preprod' => 'stake_test1uqehkck0lajq8gr28t9uxnuvgcqrc6070x3k9r8048z8y5gssrtvn',
                'preview' => 'stake_test1uqehkck0lajq8gr28t9uxnuvgcqrc6070x3k9r8048z8y5gssrtvn',
            ],
        ]);
    }

    #[DataProvider('profiles')]
    public function test_a_ready_native_environment_has_nothing_but_ok(string $profile): void
    {
        $this->native($profile);

        foreach ($this->findings($profile) as $name => $statuses) {
            $this->assertSame([EnvReadiness::OK], array_values(array_unique($statuses)), "{$name} on {$profile}: ".json_encode($statuses));
        }
    }

    /**
     * The backend is named for what it is, not described as a value the setting does not take.
     */
    public function test_the_native_backend_is_a_recognised_value(): void
    {
        $this->native('self-hosted');

        $row = collect((new EnvReadiness('self-hosted'))->run())->firstWhere('name', 'TRANSACTION_BACKEND');

        $this->assertSame(EnvReadiness::OK, $row['status']);
        $this->assertStringNotContainsString('unrecognised', $row['reason']);
        $this->assertStringContainsString('native', $row['reason']);
    }

    /**
     * Each native setting with each way it can be wrong, one at a time on a host that is otherwise
     * ready. The same refusals campaign creation makes, so a host that passes this can create one.
     */
    #[DataProvider('nativeBreakages')]
    public function test_each_native_setting_is_checked(string $profile, array $config, string $name): void
    {
        $this->native($profile);
        config($config);

        $rows = $this->findings($profile);

        $this->assertFinding($rows, $name, 'missing');

        foreach ($rows as $other => $statuses) {
            if ($other !== $name) {
                $this->assertNotContains('missing', $statuses, "{$other} was reported too: ".json_encode($statuses));
            }
        }
    }

    /**
     * A host serving several networks is ready when one stake address is usable. The networks
     * without one are a warning, since creation refuses only a campaign on those.
     */
    public function test_a_host_serving_several_networks_needs_one_usable_stake_address(): void
    {
        $this->native('self-hosted');
        config([
            'cardano.allowed_networks' => ['mainnet', 'preprod'],
            'campaign_script.stake_addresses.mainnet' => null,
        ]);

        $rows = $this->findings('self-hosted');
        $this->assertFinding($rows, 'CAMPAIGN_STAKE_ADDRESS_MAINNET', 'warning');
        $this->assertFinding($rows, 'CAMPAIGN_STAKE_ADDRESS_PREPROD', 'ok');

        config(['campaign_script.stake_addresses.preprod' => null]);

        $rows = $this->findings('self-hosted');
        $this->assertFinding($rows, 'CAMPAIGN_STAKE_ADDRESS_MAINNET', 'missing');
        $this->assertFinding($rows, 'CAMPAIGN_STAKE_ADDRESS_PREPROD', 'missing');
    }

    public static function nativeBreakages(): array
    {
        $hash = str_repeat('01', 28);

        return [
            'first hash unset' => ['self-hosted', ['campaign_script.owner_key_hashes' => [null, str_repeat('02', 28)]], 'CAMPAIGN_OWNER_KEY_HASH_1'],
            'second hash unset' => ['self-hosted', ['campaign_script.owner_key_hashes' => [$hash]], 'CAMPAIGN_OWNER_KEY_HASH_2'],
            'hash in capitals' => ['self-hosted', ['campaign_script.owner_key_hashes' => [strtoupper(str_repeat('ab', 28)), str_repeat('02', 28)]], 'CAMPAIGN_OWNER_KEY_HASH_1'],
            'hash too short' => ['self-hosted', ['campaign_script.owner_key_hashes' => [$hash, '0102']], 'CAMPAIGN_OWNER_KEY_HASH_2'],
            'both hashes the same' => ['self-hosted', ['campaign_script.owner_key_hashes' => [$hash, $hash]], 'CAMPAIGN_OWNER_KEY_HASH_2'],
            'wrapping key unset' => ['self-hosted', ['campaign_script.wrapping_keys' => []], 'CAMPAIGN_WRAPPING_KEY_1'],
            'wrapping key of the wrong length' => ['self-hosted', ['campaign_script.wrapping_keys' => [1 => base64_encode('short')]], 'CAMPAIGN_WRAPPING_KEY_1'],
            'wrapping key not base64' => ['self-hosted', ['campaign_script.wrapping_keys' => [1 => '!!!']], 'CAMPAIGN_WRAPPING_KEY_1'],
            'stake address unset' => ['self-hosted', ['campaign_script.stake_addresses.preprod' => null], 'CAMPAIGN_STAKE_ADDRESS_PREPROD'],
            'stake address not a stake address' => ['self-hosted', ['campaign_script.stake_addresses.preprod' => 'addr_test1qz2fxv2umyhttkxyxp8x0dlpdt3k6cwng5pxj3jhsydzer'], 'CAMPAIGN_STAKE_ADDRESS_PREPROD'],
            'stake address of the other network' => ['self-hosted', ['campaign_script.stake_addresses.preprod' => 'stake1uyehkck0lajq8gr28t9uxnuvgcqrc6070x3k9r8048z8y5gh6ffgw'], 'CAMPAIGN_STAKE_ADDRESS_PREPROD'],
            'mainnet stake address unset on production' => ['production', ['campaign_script.stake_addresses.mainnet' => null], 'CAMPAIGN_STAKE_ADDRESS_MAINNET'],
            'chain data endpoint unset' => ['staging', ['cardano.koios.preprod_url' => ''], 'KOIOS_PREPROD_URL'],
        ];
    }

    public function test_a_transaction_backend_that_is_not_one_is_refused_without_being_quoted(): void
    {
        $this->ready('production');
        config(['cardano.transaction_backend' => 'Sp4rrow!']);

        $rows = $this->findings('production');

        $this->assertFinding($rows, 'TRANSACTION_BACKEND', 'wrong');
    }

    /**
     * setting, the config key that holds it, and a name that is not one this deployment has.
     */
    public static function namedThings(): array
    {
        $cases = [];

        foreach (EnvReadiness::PROFILES as $profile) {
            foreach ([
                'DB_CONNECTION' => ['database.default', 'nosuchconnection'],
                'QUEUE_CONNECTION' => ['queue.default', 'nosuchqueue'],
                'CACHE_DRIVER' => ['cache.default', 'nosuchstore'],
                'SESSION_DRIVER' => ['session.driver', 'nosuchdriver'],
                'MAIL_MAILER' => ['mail.default', 'nosuchmailer'],
                'FILESYSTEM_DISK' => ['filesystems.default', 'nosuchdisk'],
            ] as $name => [$key, $unknown]) {
                $cases["{$name} on {$profile}"] = [$profile, $name, $key, $unknown];
            }
        }

        return $cases;
    }

    #[DataProvider('namedThings')]
    public function test_a_connection_store_mailer_disk_or_driver_that_does_not_exist_is_wrong_on_every_profile(string $profile, string $name, string $key, string $unknown): void
    {
        $this->ready($profile);
        config([$key => $unknown]);

        $rows = $this->findings($profile);

        $this->assertFinding($rows, $name, 'wrong');
        $this->assertNotContains('ok', $rows[$name], "{$name} must not pass with a value that throws at first use");

        $text = json_encode((new EnvReadiness($profile))->run());
        $this->assertStringNotContainsString($unknown, $text, 'the unknown name is not echoed');
    }

    public function test_a_name_is_judged_by_its_existence_and_not_by_a_dotted_config_lookup(): void
    {
        $this->ready('production');

        // config('queue.connections.database.driver') exists, so a lookup by the dotted name
        // would call this a queue.
        config(['queue.default' => 'database.driver', 'database.default' => 'mysql.host', 'cache.default' => 'redis.client']);

        $rows = $this->findings('production');

        $this->assertFinding($rows, 'QUEUE_CONNECTION', 'wrong');
        $this->assertFinding($rows, 'DB_CONNECTION', 'wrong');
        $this->assertFinding($rows, 'CACHE_DRIVER', 'wrong');
    }

    public function test_a_connection_store_mailer_disk_or_driver_that_exists_passes(): void
    {
        $this->ready('self-hosted');
        config([
            'database.default' => 'pgsql', 'queue.default' => 'redis', 'cache.default' => 'memcached', 'session.driver' => 'cookie',
            'mail.default' => 'smtp', 'filesystems.default' => 'public',
        ]);

        $rows = $this->findings('self-hosted');

        foreach (['DB_CONNECTION', 'QUEUE_CONNECTION', 'CACHE_DRIVER', 'SESSION_DRIVER', 'MAIL_MAILER', 'FILESYSTEM_DISK'] as $name) {
            $this->assertNotContains('wrong', $rows[$name] ?? [], $name);
        }
    }

    public function test_every_session_driver_the_framework_has_passes_and_a_lookalike_does_not(): void
    {
        $this->ready('self-hosted');

        foreach (['file', 'cookie', 'database', 'apc', 'memcached', 'redis', 'dynamodb', 'array', 'null'] as $driver) {
            config(['session.driver' => $driver]);
            $this->assertNotContains('wrong', $this->findings('self-hosted')['SESSION_DRIVER'], $driver);
        }

        foreach (['', 'File2', 'Sp4rrow!', 'redis.x', 'file.php'] as $driver) {
            config(['session.driver' => $driver]);
            $this->assertFinding($this->findings('self-hosted'), 'SESSION_DRIVER', 'wrong');
        }
    }

    public function test_an_environment_name_the_application_does_not_recognise_is_a_warning_except_in_production_where_it_is_wrong(): void
    {
        foreach (['staging', 'self-hosted'] as $profile) {
            $this->ready($profile);
            config(['app.env' => 'Sp4rrow-env']);

            $this->assertFinding($this->findings($profile), 'APP_ENV', 'warning');
            $this->assertStringNotContainsString('Sp4rrow', json_encode((new EnvReadiness($profile))->run()));
        }

        $this->ready('production');
        config(['app.env' => 'Sp4rrow-env']);
        $this->assertFinding($this->findings('production'), 'APP_ENV', 'wrong');

        // The names it does recognise pass on the profiles that expect them.
        $this->ready('staging');
        config(['app.env' => 'staging']);
        $this->assertSame(['ok'], $this->findings('staging')['APP_ENV']);
        $this->ready('self-hosted');
        config(['app.env' => 'production']);
        $this->assertSame(['ok'], $this->findings('self-hosted')['APP_ENV']);
    }

    public function test_an_application_address_that_is_not_http_or_https_is_wrong_on_a_self_hosted_installation_and_is_not_echoed(): void
    {
        $this->ready('self-hosted');

        foreach (['ftp://app.example.test', 'Sp4rrow!://app.example.test', 'app.example.test', 'file:///etc'] as $url) {
            config(['app.url' => $url]);

            $rows = $this->findings('self-hosted');

            $this->assertFinding($rows, 'APP_URL', 'wrong');
            $this->assertStringNotContainsString('Sp4rrow', json_encode((new EnvReadiness('self-hosted'))->run()));
            $this->assertStringNotContainsString('ftp', json_encode((new EnvReadiness('self-hosted'))->run()));
        }

        config(['app.url' => 'http://app.example.test']);
        $this->assertSame(['ok'], $this->findings('self-hosted')['APP_URL']);
    }

    public function test_live_probes_run_only_when_asked_for(): void
    {
        $this->ready('production');
        Http::fake(['*' => Http::response('', 200)]);

        Artisan::call('poo:env-check');
        $this->assertStringNotContainsString('(live)', Artisan::output());

        Artisan::call('poo:env-check', ['--live' => true]);
        $this->assertStringContainsString('KOIOS_MAINNET_URL (live)', Artisan::output());
    }

    // ------------------------------------------------------------------ live probes

    private function live(string $profile = 'production'): array
    {
        $rows = [];

        foreach ((new EnvReadiness($profile))->live(3) as $finding) {
            $rows[$finding['name']][] = $finding['status'];
        }

        return $rows;
    }

    public function test_live_probes_pass_against_healthy_services(): void
    {
        $this->ready('production');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        Queue::fake();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $rows = $this->live();

        foreach (['DB_CONNECTION (live)', 'QUEUE_CONNECTION (live)', 'PHYRHOSE_MAINNET_URL (live)', 'KOIOS_MAINNET_URL (live)', 'POSTMARK_TOKEN (live)'] as $name) {
            $this->assertFinding($rows, $name, 'ok');
        }
    }

    public function test_live_reports_an_unreachable_backend_and_a_koios_error(): void
    {
        $this->ready('production');
        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), 'phyrhose')) {
                throw new ConnectionException('refused');
            }

            return Http::response('', 500);
        });

        $rows = $this->live();

        $this->assertFinding($rows, 'PHYRHOSE_MAINNET_URL (live)', 'wrong');
        $this->assertFinding($rows, 'KOIOS_MAINNET_URL (live)', 'wrong');
    }

    public function test_live_reports_koios_and_the_mail_provider_not_answering_at_all(): void
    {
        $this->ready('production');
        Http::fake(function ($request) {
            if (str_contains((string) $request->url(), 'koios') || str_contains((string) $request->url(), 'postmarkapp')) {
                throw new ConnectionException('refused');
            }

            return Http::response('', 200);
        });
        config(['cardano.koios.mainnet_url' => 'https://api.koios.example/api/v1/']);

        $rows = $this->live();

        $this->assertFinding($rows, 'KOIOS_MAINNET_URL (live)', 'wrong');
        $this->assertFinding($rows, 'POSTMARK_TOKEN (live)', 'warning');
    }

    public function test_live_reports_koios_rate_limiting_as_a_warning(): void
    {
        $this->ready('production');
        Http::fake(['*' => Http::response('', 429)]);

        $this->assertFinding($this->live(), 'KOIOS_MAINNET_URL (live)', 'warning');
    }

    public function test_live_reports_a_refused_mail_token_and_a_dead_queue(): void
    {
        $this->ready('production');
        Http::fake(['*postmarkapp*' => Http::response('', 401), '*' => Http::response('', 200)]);
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('down'));

        $rows = $this->live();

        $this->assertFinding($rows, 'POSTMARK_TOKEN (live)', 'wrong');
        $this->assertFinding($rows, 'QUEUE_CONNECTION (live)', 'wrong');
    }

    public function test_a_queue_that_will_not_report_its_size_is_a_warning_not_a_failure(): void
    {
        $this->ready('production');
        Http::fake(['*' => Http::response('', 200)]);
        Queue::shouldReceive('connection->size')->andThrow(new RuntimeException('denied'));

        $this->assertFinding($this->live(), 'QUEUE_CONNECTION (live)', 'warning');
    }

    public function test_the_backend_probe_sends_the_network_token_and_reports_only_a_status(): void
    {
        $this->ready('production');
        $sent = [];
        Http::fake(function ($request, $options) use (&$sent) {
            if (str_contains((string) $request->url(), 'phyrhose')) {
                $sent[] = [$request, $options];
            }

            return Http::response('SENTINEL-NETWORK-ID', 200);
        });

        $this->live();

        $this->assertCount(1, $sent);
        [$request, $options] = $sent[0];
        $this->assertStringContainsString('/firehose/queryLiveUtxos', (string) $request->url());
        $this->assertSame(['Bearer SENTINEL-HEADER.SENTINEL-BODY.SENTINEL-SIG'], $request->header('Authorization'));
        $this->assertSame(3, $options['timeout']);
        $this->assertSame(3, $options['connect_timeout']);
    }

    #[DataProvider('refusals')]
    public function test_the_backend_probe_fails_on_a_refusal_or_an_error(int $status, string $row): void
    {
        $this->ready('production');
        Http::fake(['*phyrhose*' => Http::response('SENTINEL-NETWORK-ID', $status), '*' => Http::response('', 200)]);

        $rows = $this->live();

        $this->assertFinding($rows, $row, 'wrong');
        $this->assertArrayNotHasKey('PHYRHOSE_MAINNET_URL (live)', $status === 401 ? $rows : []);
    }

    public static function refusals(): array
    {
        return [
            'revoked token' => [401, 'PHYRHOSE_MAINNET_JWT (live)'],
            'forbidden' => [403, 'PHYRHOSE_MAINNET_JWT (live)'],
            'bad request' => [400, 'PHYRHOSE_MAINNET_URL (live)'],
            'server error' => [503, 'PHYRHOSE_MAINNET_URL (live)'],
        ];
    }

    public function test_the_database_probe_is_given_the_timeout(): void
    {
        $this->ready('production');
        config(['database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 1]);
        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake();

        $rows = $this->live();

        $this->assertFinding($rows, 'DB_CONNECTION (live)', 'wrong');
        $this->assertSame(3, config('database.connections.envcheck-live.options')[\PDO::ATTR_TIMEOUT]);
        $this->assertSame('mysql', config('database.default'));
    }

    public function test_the_command_says_which_probes_the_timeout_does_not_bound(): void
    {
        $this->ready('production');
        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        Artisan::call('poo:env-check', ['--live' => true, '--timeout' => 1]);

        $output = Artisan::output();

        $this->assertStringContainsString('queue size read is not bounded by --timeout', $output);
        $this->assertStringContainsString('mail host name lookup is not bounded', $output);
    }

    public function test_koios_probe_carries_the_token_and_the_timeout_only_when_set(): void
    {
        $this->ready('production');
        $sent = [];
        Http::fake(function ($request, $options) use (&$sent) {
            if (str_contains((string) $request->url(), 'koios')) {
                $sent[] = [$request, $options];
            }

            return Http::response('', 200);
        });

        $this->live();
        config(['cardano.koios.token' => '']);
        $this->live();

        $this->assertCount(2, $sent);
        $this->assertSame(['Bearer SENTINEL-KOIOS'], $sent[0][0]->header('Authorization'));
        $this->assertSame([], $sent[1][0]->header('Authorization'));
        $this->assertSame(3, $sent[0][1]['timeout']);
        $this->assertSame(3, $sent[0][1]['connect_timeout']);
    }

    public function test_live_reports_an_unreachable_database_and_mail_server(): void
    {
        $this->ready('self-hosted');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => '/nonexistent/envchk.sqlite',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
        ]);
        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake();

        $rows = $this->live('self-hosted');

        $this->assertFinding($rows, 'DB_CONNECTION (live)', 'wrong');
        $this->assertFinding($rows, 'MAIL_HOST (live)', 'wrong');
    }

    public function test_live_probes_only_the_network_the_profile_uses(): void
    {
        $this->ready('staging');
        Http::fake(['*' => Http::response('', 200)]);

        $rows = $this->live('staging');

        $this->assertArrayHasKey('PHYRHOSE_PREPROD_URL (live)', $rows);
        $this->assertArrayNotHasKey('PHYRHOSE_MAINNET_URL (live)', $rows);
    }

    public function test_an_inline_queue_is_not_probed(): void
    {
        $this->ready('self-hosted');
        config(['queue.default' => 'sync']);
        Http::fake(['*' => Http::response('', 200)]);

        $this->assertArrayNotHasKey('QUEUE_CONNECTION (live)', $this->live('self-hosted'));
    }
}

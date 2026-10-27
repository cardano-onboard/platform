<?php

namespace App\Support;

use App\Cardano\NativeWalletFactory;
use CardanoPhp\Bech32\Bech32;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Judges whether the environment this process is running with is fit for a kind of
 * deployment, before anything is deployed on it or after it has been changed.
 *
 * Every rule reads configuration through config(), so a cached configuration is judged
 * the same as a fresh one. The exception is the rules about names the application no longer
 * reads or reads only as a fallback: those have no config key, so they read the process
 * environment, which does not hold values from a .env file once the configuration is
 * cached. When the configuration is cached and such a name is not visible, the finding is
 * a warning that says it is unknown, never ok. Every finding names the exact variable to change and leads
 * with the redeploy that makes the change take effect. A finding never carries a secret
 * or any part of one: a reason may name a variable and say what is wrong with it, and
 * the few values it states are ones that are not secret (a driver name, a scheme).
 *
 * Profiles:
 *   production   a hosted deployment that moves mainnet funds
 *   staging      a hosted deployment that must never touch mainnet
 *   self-hosted  one operator's own installation
 */
class EnvReadiness
{
    public const PROFILES = ['production', 'staging', 'self-hosted'];

    public const OK = 'ok';

    public const WARNING = 'warning';

    public const MISSING = 'missing';

    public const WRONG = 'wrong';

    /** Queue drivers that run a job inline or ignore its delay. */
    private const INLINE_QUEUES = ['sync', 'deferred', 'background'];

    private const NETWORKS = ['preprod', 'preview', 'mainnet'];

    /** @var array<int, array{name: string, status: string, reason: string}> */
    private array $findings = [];

    /** @var Closure(string): ?string */
    private Closure $raw;

    /**
     * @param  Closure(string): ?string|null  $raw  Reads a variable as the process received it.
     *                                              Only the retired-name rules need this, because a
     *                                              retired name no longer has a config key to read.
     */
    public function __construct(private string $profile, ?Closure $raw = null, private ?bool $configCached = null)
    {
        $this->raw = $raw ?? fn (string $name) => Env::get($name);
        $this->configCached ??= app()->configurationIsCached();
    }

    /**
     * Whether a name's value cannot be seen from here: the configuration is cached, so a
     * value that lives only in a .env file reads as absent whether or not it is set.
     */
    private function unseen(string $name): bool
    {
        return $this->configCached === true && $this->raw($name) === null;
    }

    private function hosted(): bool
    {
        return $this->profile !== 'self-hosted';
    }

    /**
     * @return array<int, array{name: string, status: string, reason: string}>
     */
    public function run(): array
    {
        $this->findings = [];

        $this->app();
        $this->database();
        $this->queue();
        $this->stores();
        $this->backend();
        $this->networks();
        $this->mail();
        $this->storage();
        $this->hostsAndNotice();
        $this->claimTiming();
        $this->retiredNames();
        $this->numbers();

        return $this->findings;
    }

    /** The failing statuses: what stops a deploy. */
    public static function fails(array $findings, bool $strict = false): bool
    {
        foreach ($findings as $finding) {
            if (in_array($finding['status'], [self::MISSING, self::WRONG], true)
                || ($strict && $finding['status'] === self::WARNING)) {
                return true;
            }
        }

        return false;
    }

    private function add(string $name, string $status, string $reason): void
    {
        $this->findings[] = ['name' => $name, 'status' => $status, 'reason' => $reason];
    }

    private function ok(string $name, string $reason): void
    {
        $this->add($name, self::OK, $reason);
    }

    private function missing(string $name, string $fix, string $why): void
    {
        $this->add($name, self::MISSING, "Redeploy with {$fix}. {$why}");
    }

    private function wrong(string $name, string $fix, string $why): void
    {
        $this->add($name, self::WRONG, "Redeploy with {$fix}. {$why}");
    }

    private function warn(string $name, string $fix, string $why): void
    {
        $this->add($name, self::WARNING, "Redeploy with {$fix}. {$why}");
    }

    /**
     * A value as a message may state it: in quotes when it is one of the words the setting
     * allows, and otherwise described without being shown, since a setting that holds free text
     * may hold a credential that was pasted into the wrong variable.
     */
    private function say(string $name, mixed $value): string
    {
        $shown = EnvMask::show($name, $value, EnvTypes::of($name, ['kind' => 'none']));

        return $shown['hidden'] ? 'an unrecognised value' : '"'.$shown['text'].'"';
    }

    /**
     * Whether $name is the name of an entry in the configuration array at $key: a connection,
     * a store, a mailer, a disk. The names are written in the configuration files, so a value
     * that is one of them is a word from this application's own source, and is safe to state.
     */
    private function configured(string $key, string $name): bool
    {
        $entries = config($key);

        return $name !== '' && is_array($entries) && array_key_exists($name, $entries);
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function raw(string $name): ?string
    {
        $value = ($this->raw)($name);

        return $value === null ? null : (string) $value;
    }

    // ------------------------------------------------------------------ application

    private function app(): void
    {
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($key === '') {
            $this->missing('APP_KEY', 'APP_KEY set (php artisan key:generate --show)', 'Sessions and encrypted values cannot work without it.');
        } elseif ($decoded === false || ! in_array(strlen($decoded), [16, 32], true)) {
            $this->wrong('APP_KEY', 'APP_KEY regenerated (php artisan key:generate --show)', 'It is not a valid 16 or 32 byte key, so every encrypt and decrypt will fail.');
        } else {
            $this->ok('APP_KEY', 'set and a valid length');
        }

        $env = (string) config('app.env');

        if ($this->profile === 'production' && $env !== 'production') {
            $this->wrong('APP_ENV', 'APP_ENV=production', "It is {$this->say('APP_ENV', $env)}, and several protections only apply in production.");
        } elseif ($this->profile !== 'production' && $this->unrecognisedEnvironment($env)) {
            $this->warn('APP_ENV', 'APP_ENV set to a name the application recognises, such as staging or production', 'The value is not one the application recognises, so protections that depend on the environment name may not apply.');
        } elseif ($this->profile === 'staging' && in_array($env, ['local', 'testing'], true)) {
            $this->warn('APP_ENV', 'APP_ENV=staging', "It is {$this->say('APP_ENV', $env)}, which is a development value.");
        } else {
            $this->ok('APP_ENV', $this->say('APP_ENV', $env));
        }

        if (config('app.debug') === true) {
            $fix = 'APP_DEBUG=false';
            $why = 'Debug pages show configuration and stack traces to any visitor who triggers an error.';
            $this->hosted() && $this->profile === 'production'
                ? $this->wrong('APP_DEBUG', $fix, $why)
                : $this->warn('APP_DEBUG', $fix, $why);
        } else {
            $this->ok('APP_DEBUG', 'false');
        }

        $url = (string) config('app.url');
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $local = $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);

        if ($this->hosted()) {
            if ($scheme !== 'https' || $local) {
                $this->wrong('APP_URL', 'APP_URL set to the public https address', 'Generated links, signed URLs and cookies are built from it, and it is not a public https address.');
            } else {
                $this->ok('APP_URL', 'https');
            }
        } elseif (! in_array($scheme, ['http', 'https'], true)) {
            $this->wrong('APP_URL', 'APP_URL set to an http or https address', 'Generated links, signed URLs and cookies are built from it, and it is not an http or https address.');
        } elseif ($local) {
            $this->warn('APP_URL', 'APP_URL set to the address people reach this installation at', 'It points at this machine, so every generated link and QR code will too.');
        } else {
            $this->ok('APP_URL', (string) $scheme);
        }

        if ($this->profile === 'production' && config('session.secure') !== true) {
            $this->warn('SESSION_SECURE_COOKIE', 'SESSION_SECURE_COOKIE=true', 'The session cookie is not marked secure.');
        }

        if ($this->profile === 'production' && config('logging.channels.single.level') === 'debug') {
            $this->warn('LOG_LEVEL', 'LOG_LEVEL=warning or error', 'Debug logging writes request detail to the log on every request.');
        }

        $adminPassword = (string) config('admin.password');

        // The two values the example environment files ship.
        $adminDefault = in_array($adminPassword, ['password', 'changeme123'], true);

        if ($adminDefault && $this->profile === 'self-hosted') {
            $this->wrong('ADMIN_PASSWORD', 'ADMIN_PASSWORD set to a password of your own', 'The administrator seeder would create the account with the published default password.');
        } elseif ($adminDefault) {
            $this->warn('ADMIN_PASSWORD', 'ADMIN_PASSWORD set to a password of your own', 'The administrator seeder would create the account with the published default password.');
        }

        $stateful = array_map('trim', (array) config('sanctum.stateful'));

        if (! $local && ! $this->blank($host)
            && ! in_array($host, $stateful, true)
            && ! in_array($host.(parse_url($url, PHP_URL_PORT) ? ':'.parse_url($url, PHP_URL_PORT) : ''), $stateful, true)) {
            $this->warn('SANCTUM_STATEFUL_DOMAINS', 'SANCTUM_STATEFUL_DOMAINS including the APP_URL host', 'The application host is not listed, so cookie authentication from the browser app is not treated as stateful.');
        }

        if ($this->hosted() && ! $this->blank(config('security.csp_override'))) {
            $this->warn('CONTENT_SECURITY_POLICY', 'CONTENT_SECURITY_POLICY removed unless the override is intended', 'It replaces the built-in policy verbatim, including its protections.');
        }
    }

    /** Whether an environment name is not one of the words the application recognises. */
    private function unrecognisedEnvironment(string $env): bool
    {
        return ! in_array($env, explode('|', substr(EnvTypes::BY_NAME['APP_ENV'], 5)), true);
    }

    private function database(): void
    {
        $connection = (string) config('database.default');

        if (! $this->configured('database.connections', $connection)) {
            $this->wrong('DB_CONNECTION', 'DB_CONNECTION set to a database connection this deployment configures', 'The value is not the name of one, so the application fails the first time it uses the database.');

            return;
        }

        if ($this->hosted() && $connection === 'sqlite') {
            $this->wrong('DB_CONNECTION', 'DB_CONNECTION=mysql with the host database attached', 'A SQLite file does not survive a deploy or serve more than one instance.');

            return;
        }

        $this->ok('DB_CONNECTION', "\"{$connection}\"");

        if ($this->hosted() && $connection !== 'sqlite') {
            $host = (string) config("database.connections.{$connection}.host");

            if (in_array($host, ['127.0.0.1', 'localhost', ''], true)) {
                $this->warn('DB_HOST', 'DB_HOST set to the database server', 'It points at this machine, which is not where a hosted database lives.');
            }

            if ($this->blank(config("database.connections.{$connection}.password"))) {
                $this->warn('DB_PASSWORD', 'DB_PASSWORD set', 'The database password is blank.');
            }
        }
    }

    // ------------------------------------------------------------------ queue and stores

    private function queue(): void
    {
        $default = (string) config('queue.default');
        $inline = in_array($default, self::INLINE_QUEUES, true);

        if (! $this->configured('queue.connections', $default)) {
            $this->wrong('QUEUE_CONNECTION', 'QUEUE_CONNECTION set to a queue connection this deployment configures', 'The value is not the name of one, so no job can be queued and every claim fails to go out.');

            return;
        }

        if ($inline && $this->hosted()) {
            $this->wrong('QUEUE_CONNECTION', 'QUEUE_CONNECTION set to a real queue such as database or redis', "It is \"{$default}\", which runs every job inside the request and ignores delays, so claims are neither sent in a batch nor confirmed later.");
        } elseif ($inline) {
            $this->warn('QUEUE_CONNECTION', 'QUEUE_CONNECTION=database or redis with a worker, or `php artisan schedule:run` from cron every minute', "It is \"{$default}\", so claims are confirmed and retried only by status requests and the scheduler.");
        } else {
            $this->ok('QUEUE_CONNECTION', "\"{$default}\"");
        }

        $retry = config("queue.connections.{$default}.retry_after");
        $jobTimeout = (int) config('cardano.qr_storage.job_timeout_seconds');

        if ($retry !== null && (int) $retry <= $jobTimeout) {
            $this->wrong('QUEUE_RETRY_AFTER', 'QUEUE_RETRY_AFTER longer than QR_EXPORT_JOB_TIMEOUT', 'A job still running when the reservation lapses is handed to a second worker and runs twice.');
        } elseif ($retry !== null) {
            $this->ok('QUEUE_RETRY_AFTER', 'longer than the longest job');
        }

        if ($jobTimeout > (int) config('cardano.tasks.stale_after_seconds')) {
            $this->warn('QR_EXPORT_JOB_TIMEOUT', 'QR_EXPORT_JOB_TIMEOUT at or below TASK_STALE_AFTER_SECONDS', 'A run silent for the stale window can be started again while the first is still rendering.');
        }

        if ($this->hosted() && config('housekeeping.on_traffic') !== true) {
            $this->warn('HOUSEKEEPING_ON_TRAFFIC', 'HOUSEKEEPING_ON_TRAFFIC=true', 'A hosted deployment that runs no scheduler never refreshes the token registry or prunes QR exports without it.');
        }
    }

    private function stores(): void
    {
        foreach ([['CACHE_DRIVER', 'cache.default', 'Claim check debounce and chain locks'], ['SESSION_DRIVER', 'session.driver', 'Sessions']] as [$name, $key, $what]) {
            $driver = (string) config($key);

            // The cache is one of the stores the configuration defines. A session driver is one
            // the framework has, which it names by a method for each.
            $exists = $name === 'CACHE_DRIVER'
                ? $this->configured('cache.stores', $driver)
                : $driver !== '' && method_exists(SessionManager::class, 'create'.Str::studly($driver).'Driver');

            if (! $exists) {
                $this->wrong($name, $name === 'CACHE_DRIVER' ? 'CACHE_DRIVER set to a cache store this deployment configures' : 'SESSION_DRIVER set to a session driver the framework has', 'The value is not one, so the application fails the first time it uses it.');
            } elseif ($this->hosted() && in_array($driver, ['file', 'array'], true)) {
                $this->warn($name, "{$name}=redis or database", "\"{$driver}\" is per instance and can come back empty after a cold start. {$what} need a store every instance shares.");
            } else {
                $this->ok($name, "\"{$driver}\"");
            }
        }
    }

    // ------------------------------------------------------------------ transaction backend

    private function backend(): void
    {
        $backend = config('cardano.transaction_backend');
        $backend = $this->blank($backend) ? 'null' : (string) $backend;
        $allowed = (array) config('cardano.allowed_networks');

        if (! in_array($backend, ['null', 'phyrhose', 'proxy', 'native'], true)) {
            $this->wrong('TRANSACTION_BACKEND', 'TRANSACTION_BACKEND=native, phyrhose, proxy or null', 'The value is not a backend, and the application refuses to boot a transaction on it.');

            return;
        }

        if ($backend === 'null') {
            $fix = 'TRANSACTION_BACKEND=phyrhose';
            $why = 'The null backend records claims and sends nothing, so no attendee is ever paid.';
            $this->profile === 'production'
                ? $this->wrong('TRANSACTION_BACKEND', $fix, $why)
                : $this->warn('TRANSACTION_BACKEND', $fix, $why);

            return;
        }

        $this->ok('TRANSACTION_BACKEND', $this->say('TRANSACTION_BACKEND', $backend));

        if ($backend === 'proxy') {
            $this->blank(config('cardano.proxy_api_token'))
                ? $this->missing('PROXY_API_TOKEN', 'PROXY_API_TOKEN set to the relay API token', 'The relay refuses requests without it.')
                : $this->ok('PROXY_API_TOKEN', 'set');

            if (! str_starts_with((string) config('cardano.proxy_api_url'), 'https://')) {
                $this->wrong('PROXY_API_URL', 'PROXY_API_URL set to an https address', 'The relay token would travel unencrypted.');
            }

            return;
        }

        $networks = match ($this->profile) {
            'production' => ['mainnet'],
            'staging' => ['preprod'],
            default => array_values(array_intersect(self::NETWORKS, $allowed)),
        };

        if ($backend === 'native') {
            $this->native($networks);

            // Campaigns made before a host switched to native stay on Phyrhose and still need it.
            foreach ($this->hosted() ? $networks : [] as $network) {
                $this->phyrhose($network, required: false);
            }

            return;
        }

        foreach ($networks as $network) {
            $this->phyrhose($network, required: true);
        }

        // The other network's credentials are needed only when the deployment accepts it.
        foreach (['mainnet', 'preprod'] as $network) {
            if (in_array($network, $networks, true)) {
                continue;
            }

            $present = ! $this->blank(config("cardano.phyrhose.{$network}_jwt")) || ! $this->blank(config("cardano.phyrhose.{$network}_id"));

            if ($this->profile === 'staging' && $network === 'mainnet' && $present) {
                $this->warn('PHYRHOSE_MAINNET_JWT', 'the PHYRHOSE_MAINNET_* values removed', 'Mainnet credentials on a preprod deployment let a misconfigured campaign move real funds.');
            } elseif ($this->profile === 'production' && $network === 'preprod' && in_array('preprod', $allowed, true)) {
                $this->phyrhose('preprod', required: false);
            }
        }

        $mainnet = (string) config('cardano.phyrhose.mainnet_id');
        $preprod = (string) config('cardano.phyrhose.preprod_id');

        if ($mainnet !== '' && $mainnet === $preprod) {
            $this->wrong('PHYRHOSE_MAINNET_ID', 'PHYRHOSE_MAINNET_ID set to the mainnet network id, different from PHYRHOSE_PREPROD_ID', 'Both networks carry the same id, so one of them is a copy of the other.');
        }

        $mainnetJwt = (string) config('cardano.phyrhose.mainnet_jwt');

        if ($mainnetJwt !== '' && $mainnetJwt === (string) config('cardano.phyrhose.preprod_jwt')) {
            $this->wrong('PHYRHOSE_MAINNET_JWT', 'PHYRHOSE_MAINNET_JWT set to the mainnet token, different from PHYRHOSE_PREPROD_JWT', 'Both networks carry the same token, so one of them is a copy of the other.');
        }

        $mainnetUrl = strtolower((string) config('cardano.phyrhose.mainnet_url'));

        if ($mainnetUrl !== '' && $mainnetUrl === strtolower((string) config('cardano.phyrhose.preprod_url'))) {
            $this->wrong('PHYRHOSE_MAINNET_URL', 'PHYRHOSE_MAINNET_URL set to the mainnet address, different from PHYRHOSE_PREPROD_URL', 'Both networks use the same address, so one of them is a copy of the other.');
        }

        if (preg_match('/testnet|preprod|preview/', $mainnetUrl) === 1) {
            $this->wrong('PHYRHOSE_MAINNET_URL', 'PHYRHOSE_MAINNET_URL set to the mainnet address', 'It names a test network, so mainnet claims would be sent there.');
        }
    }

    /**
     * What a host that submits its own transactions needs: the two recovery key hashes, the wrapping
     * key, and for each network it serves a stake address and a chain-data endpoint. The factory
     * that refuses to make a wallet without them is asked, so this cannot disagree with it.
     *
     * @param  list<string>  $networks
     */
    private function native(array $networks): void
    {
        $wallets = app(NativeWalletFactory::class);
        $wanted = ['CAMPAIGN_OWNER_KEY_HASH_1', 'CAMPAIGN_OWNER_KEY_HASH_2', 'CAMPAIGN_WRAPPING_KEY_'.max(1, (int) config('campaign_script.wrapping_key_version', 1))];
        $unusable = [];

        foreach ($networks as $network) {
            $wanted[] = NativeWalletFactory::stakeAddressVariable($network);
            $unusable = [...$unusable, ...$wallets->missingSettings($network)];

            if ($this->blank(config("cardano.koios.{$network}_url"))) {
                $wanted[] = $unusable[] = 'KOIOS_'.strtoupper($network).'_URL';
            }
        }

        // Creation checks the network the campaign is on, so a host that serves one of several
        // networks is ready as long as one stake address is usable, and the others are a warning.
        $stakes = array_map(static fn (string $network): string => NativeWalletFactory::stakeAddressVariable($network), $networks);
        $anyStake = array_diff($stakes, $unusable) !== [];

        foreach (array_unique($wanted) as $name) {
            if (! in_array($name, $unusable, true)) {
                $this->ok($name, 'set and usable');
            } elseif ($anyStake && in_array($name, $stakes, true)) {
                $this->warn($name, "{$name} set to a usable value", 'Campaigns on this network are refused until it is.');
            } else {
                $this->missing($name, "{$name} set to a usable value", 'No campaign can be created on this deployment without it, and what it protects cannot be added afterwards.');
            }
        }
    }

    private function phyrhose(string $network, bool $required): void
    {
        $upper = strtoupper($network);
        $id = (string) config("cardano.phyrhose.{$network}_id");
        $jwt = (string) config("cardano.phyrhose.{$network}_jwt");
        $url = (string) config("cardano.phyrhose.{$network}_url");
        $report = $required ? 'missing' : 'warn';

        if ($this->blank($id)) {
            $this->{$report}("PHYRHOSE_{$upper}_ID", "PHYRHOSE_{$upper}_ID set to the {$network} network id", "Campaign wallets and claims on {$network} cannot be sent without it.");
        } elseif (preg_match('/\s/', $id) === 1) {
            $this->wrong("PHYRHOSE_{$upper}_ID", "PHYRHOSE_{$upper}_ID set without spaces or line breaks", 'It contains whitespace, which usually means it was pasted with a trailing newline.');
        } else {
            $this->ok("PHYRHOSE_{$upper}_ID", 'set');
        }

        if ($this->blank($jwt)) {
            $this->{$report}("PHYRHOSE_{$upper}_JWT", "PHYRHOSE_{$upper}_JWT set to the {$network} token", "Every {$network} request to the backend is refused without it.");
        } elseif (count(explode('.', trim($jwt))) !== 3 || preg_match('/\s/', $jwt) === 1) {
            $this->wrong("PHYRHOSE_{$upper}_JWT", "PHYRHOSE_{$upper}_JWT set to the token as issued", 'It is not three dot separated parts, or it carries whitespace.');
        } else {
            $this->ok("PHYRHOSE_{$upper}_JWT", 'set, shaped like a token');
        }

        if (! str_starts_with($url, 'https://')) {
            $this->wrong("PHYRHOSE_{$upper}_URL", "PHYRHOSE_{$upper}_URL set to an https address", 'The token would travel unencrypted, or there is no address at all.');
        }
    }

    private function networks(): void
    {
        $allowed = (array) config('cardano.allowed_networks');
        $unknown = array_diff($allowed, self::NETWORKS);

        if ($allowed === [] || $unknown !== []) {
            $this->wrong('ALLOWED_NETWORKS', 'ALLOWED_NETWORKS listing only preprod, preview and mainnet', $allowed === [] ? 'No network is allowed, so no campaign can be created.' : 'It names a network that does not exist, so that entry never matches.');
        } elseif ($this->profile === 'production' && ! in_array('mainnet', $allowed, true)) {
            $this->wrong('ALLOWED_NETWORKS', 'ALLOWED_NETWORKS including mainnet', 'Mainnet is not allowed, so no mainnet campaign can be created.');
        } elseif ($this->profile === 'staging' && in_array('mainnet', $allowed, true)) {
            $this->wrong('ALLOWED_NETWORKS', 'ALLOWED_NETWORKS=preprod,preview', 'Mainnet is allowed on a staging deployment, so real funds could pass through it.');
        } else {
            $this->ok('ALLOWED_NETWORKS', implode(',', $allowed));
        }

        $hint = config('cardano.mainnet_app_url');

        if (! $this->blank($hint) && ! str_starts_with((string) $hint, 'https://')) {
            $this->wrong('MAINNET_APP_URL', 'MAINNET_APP_URL set to an https address or removed', 'Operators are sent to it to create mainnet campaigns.');
        }
    }

    // ------------------------------------------------------------------ mail and storage

    private function mail(): void
    {
        $mailer = (string) config('mail.default');

        if (! $this->configured('mail.mailers', $mailer)) {
            $this->wrong('MAIL_MAILER', 'MAIL_MAILER set to a mailer this deployment configures', 'The value is not the name of one, so every send fails.');
        } elseif (in_array($mailer, ['log', 'array'], true)) {
            $fix = 'MAIL_MAILER set to a real transport such as postmark or smtp';
            $why = "\"{$mailer}\" never delivers, so password resets and verification mail go nowhere.";
            $this->hosted() ? $this->wrong('MAIL_MAILER', $fix, $why) : $this->warn('MAIL_MAILER', $fix, $why);
        } elseif ($mailer === 'smtp' && $this->hosted()
            && in_array((string) config('mail.mailers.smtp.host'), ['mailpit', 'localhost', '127.0.0.1', ''], true)) {
            $this->wrong('MAIL_HOST', 'MAIL_HOST set to the mail server', 'It points at a local development mail catcher.');
        } elseif ($mailer === 'postmark' && $this->blank(config('services.postmark.token'))) {
            $this->missing('POSTMARK_TOKEN', 'POSTMARK_TOKEN set to the server API token', 'MAIL_MAILER is postmark and every send will fail without it.');
        } elseif ($mailer === 'mailgun' && ($this->blank(config('services.mailgun.domain')) || $this->blank(config('services.mailgun.secret')))) {
            $this->missing('MAILGUN_SECRET', 'MAILGUN_DOMAIN and MAILGUN_SECRET set', 'MAIL_MAILER is mailgun and every send will fail without them.');
        } elseif ($mailer === 'ses' && ($this->blank(config('services.ses.key')) || $this->blank(config('services.ses.secret')))) {
            $this->missing('AWS_ACCESS_KEY_ID', 'AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY set', 'MAIL_MAILER is ses and every send will fail without them.');
        } else {
            $this->ok('MAIL_MAILER', "\"{$mailer}\"");
        }

        $from = strtolower((string) config('mail.from.address'));

        if ($this->hosted() && ($from === '' || str_ends_with($from, '@example.com'))) {
            $this->wrong('MAIL_FROM_ADDRESS', 'MAIL_FROM_ADDRESS set to an address on your own domain', 'It is the placeholder address, which mail providers refuse or file as spam.');
        } else {
            $this->ok('MAIL_FROM_ADDRESS', 'set');
        }
    }

    private function storage(): void
    {
        $default = (string) config('filesystems.default');
        $driver = config("filesystems.disks.{$default}.driver");

        if (! $this->configured('filesystems.disks', $default)) {
            $this->wrong('FILESYSTEM_DISK', 'FILESYSTEM_DISK set to a disk this deployment configures', 'The value is not the name of one, so every file the application stores or reads fails.');
        } elseif ($driver === 's3') {
            foreach (['AWS_BUCKET' => 'bucket', 'AWS_DEFAULT_REGION' => 'region'] as $name => $key) {
                if ($this->blank(config("filesystems.disks.{$default}.{$key}"))) {
                    $this->missing($name, "{$name} set", "FILESYSTEM_DISK is \"{$default}\", an object store disk, and the application falls back to the local disk without it.");
                }
            }
        } elseif ($this->hosted() && $driver === 'local') {
            $this->warn('FILESYSTEM_DISK', 'FILESYSTEM_DISK set to the attached object storage disk', 'It is the local disk, which does not survive a deploy or reach other instances.');
        } else {
            $this->ok('FILESYSTEM_DISK', "\"{$default}\"");
        }

        $qr = config('cardano.qr_storage.disk');

        if (! $this->blank($qr) && config("filesystems.disks.{$qr}") === null) {
            $this->wrong('QR_STORAGE_DISK', 'QR_STORAGE_DISK removed or set to a configured disk', 'The disk QR_STORAGE_DISK names is not one this deployment configures, so QR exports fail.');
        }
    }

    // ------------------------------------------------------------------ hosts and the banner

    private function hostsAndNotice(): void
    {
        foreach (['CLAIM_DOMAIN' => 'cardano.claim_domain', 'ADA_DOMAIN' => 'cardano.ada.domain'] as $name => $key) {
            $value = config($key);

            if (! $this->blank($value) && preg_match('#^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$#i', (string) $value) !== 1) {
                $this->wrong($name, "{$name} set to a bare host name such as claim.example.com", 'It carries a scheme, a path or a port, so it never matches a request host.');
            } elseif ($name === 'CLAIM_DOMAIN' && $this->blank($value) && $this->profile === 'production') {
                $this->warn($name, 'CLAIM_DOMAIN set to the claim host', 'Without it claim links and QR codes use the longer path on the main host.');
            }
        }

        $message = config('notice.message');

        if ($this->profile === 'production' && ! $this->blank($message)) {
            $this->warn('APP_NOTICE', 'APP_NOTICE removed', 'A deployment notice is set, so operators see the banner on production.');
        }

        $type = (string) config('notice.type');

        if (! $this->blank($message) && ! in_array($type, ['info', 'warning'], true)) {
            $this->warn('APP_NOTICE_TYPE', 'APP_NOTICE_TYPE=info or warning', 'Any other value is shown as info.');
        }
    }

    // ------------------------------------------------------------------ claim timing

    private function claimTiming(): void
    {
        $seconds = (int) config('cardano.push_delay_seconds');
        $legacy = $this->raw('POO_PUSH_DELAY');
        $current = $this->raw('POO_PUSH_DELAY_SECONDS');

        if ($this->hosted() && $this->unseen('POO_PUSH_DELAY')) {
            $this->warn('POO_PUSH_DELAY_SECONDS', 'POO_PUSH_DELAY removed or POO_PUSH_DELAY_SECONDS=30 set on the host', "Unknown: the configuration is cached, so this check cannot see POO_PUSH_DELAY, and claims wait {$seconds} seconds. Check the host's variables.");
        } elseif ($this->hosted() && ! $this->blank($legacy) && $this->blank($current)) {
            $this->warn('POO_PUSH_DELAY_SECONDS', 'POO_PUSH_DELAY_SECONDS=30', "The earlier POO_PUSH_DELAY, in minutes, is still in force and makes claims wait {$seconds} seconds.");
        } else {
            $this->ok('POO_PUSH_DELAY_SECONDS', "claims wait {$seconds} seconds");
        }

        $submit = (int) config('cardano.phyrhose.submit_timeout_seconds');
        $timeout = (int) config('cardano.phyrhose.timeout_seconds');

        if ($submit < $timeout) {
            $this->warn('PHYRHOSE_SUBMIT_TIMEOUT_SECONDS', 'PHYRHOSE_SUBMIT_TIMEOUT_SECONDS at least PHYRHOSE_TIMEOUT_SECONDS', 'Sending a batch is given less time than a status request, so slow sends are cut off and then paid twice.');
        }
    }

    /** Names a deployment may still carry that the application no longer reads, or reads only as a fallback. */
    private function retiredNames(): void
    {
        $unknown = fn (string $name, string $fix) => $this->warn($name, $fix, "Unknown: the configuration is cached, so this check cannot see {$name}. Check the host's variables.");

        if ($this->unseen('MIN_UTXO_HEADROOM_LOVELACE')) {
            $unknown('MIN_UTXO_HEADROOM_LOVELACE', 'MIN_UTXO_HEADROOM_LOVELACE removed if the host sets it');
        } elseif (! $this->blank($this->raw('MIN_UTXO_HEADROOM_LOVELACE'))) {
            $this->warn('MIN_UTXO_HEADROOM_LOVELACE', 'MIN_UTXO_HEADROOM_LOVELACE removed', 'The application no longer reads it. Codes are priced at the exact ledger minimum.');
        }

        foreach (['PASSPORT_API_MAX_TOKENS_PER_CODE' => 'CODE_API_MAX_TOKENS_PER_CODE', 'PASSPORT_API_MAX_TOKEN_QUANTITY' => 'CODE_API_MAX_TOKEN_QUANTITY', 'PASSPORT_API_CREATE_RATE_PER_TOKEN' => 'CODE_API_CREATE_RATE_PER_TOKEN', 'PASSPORT_API_STATUS_RATE_PER_TOKEN' => 'CODE_API_STATUS_RATE_PER_TOKEN'] as $old => $new) {
            if ($this->unseen($old)) {
                $unknown($old, "{$new} set and {$old} removed if the host sets the earlier name");
            } elseif (! $this->blank($this->raw($old)) && $this->blank($this->raw($new))) {
                $this->warn($old, "{$new} set to the same value and {$old} removed", 'The earlier name is no longer documented and the fallback to it can be dropped.');
            }
        }
    }

    // ------------------------------------------------------------------ numeric limits

    private function numbers(): void
    {
        $positive = [
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

        foreach ($positive as $name => $key) {
            $value = config($key);

            // A key this edition does not have is not a problem with the environment.
            if ($value === null) {
                continue;
            }

            if ((int) $value < 1) {
                $this->wrong($name, "{$name} set to a whole number above zero", 'It is '.(int) $value.', which turns the limit off or blocks the thing it limits.');
            }
        }

        $bytes = (int) config('cardano.min_utxo.address_bytes');

        if ($bytes < 29 || $bytes > 57) {
            $this->wrong('MIN_UTXO_ADDRESS_BYTES', 'MIN_UTXO_ADDRESS_BYTES=57', "It is {$bytes}. A Cardano address is 29 bytes (enterprise) to 57 bytes (base), and codes are priced from this length.");
        }

        if ((int) config('cardano.koios.max_body_bytes') < 256) {
            $this->warn('KOIOS_MAX_BODY_BYTES', 'KOIOS_MAX_BODY_BYTES=5120', 'A value below 256 is ignored and the default is used.');
        }

        if ((int) config('cardano.qr_storage.retention_days') < (int) config('cardano.qr_storage.ttl_days')) {
            $this->warn('QR_EXPORT_RETENTION_DAYS', 'QR_EXPORT_RETENTION_DAYS at least QR_STORAGE_TTL_DAYS', 'Export records are deleted before the bundles they describe.');
        }
    }

    // ------------------------------------------------------------------ live probes

    /**
     * Read-only probes with short timeouts. Nothing is created, sent or paid.
     *
     * @return array<int, array{name: string, status: string, reason: string}>
     */
    public function live(int $timeout = 5): array
    {
        $this->findings = [];
        $timeout = max(1, $timeout);

        $this->probeDatabase($timeout);
        $this->probeQueue();

        $networks = match ($this->profile) {
            'production' => ['mainnet'],
            'staging' => ['preprod'],
            default => array_values(array_intersect(self::NETWORKS, (array) config('cardano.allowed_networks'))),
        };

        if (config('cardano.transaction_backend') === 'phyrhose') {
            foreach (array_intersect($networks, ['mainnet', 'preprod']) as $network) {
                $this->probePhyrhose($network, $timeout);
            }
        }

        foreach ($networks as $network) {
            $this->probeKoios($network, $timeout);
        }

        $this->probeMail($timeout);

        return $this->findings;
    }

    /**
     * Probes that cannot be held to --timeout, said out loud rather than implied.
     *
     * @return array<int, string>
     */
    public function liveNotes(): array
    {
        $notes = [];

        if (! in_array((string) config('queue.default'), self::INLINE_QUEUES, true)) {
            $notes[] = 'The queue size read is not bounded by --timeout; it ends when the queue client gives up.';
        }

        if ((string) config('mail.default') === 'smtp') {
            $notes[] = 'The mail host name lookup is not bounded by --timeout; only the connection that follows it is.';
        }

        return $notes;
    }

    private function probeDatabase(int $timeout): void
    {
        $name = (string) config('database.default');
        $connection = (array) config("database.connections.{$name}");

        // A copy of the connection with a connect timeout, so an unreachable server ends the
        // probe at --timeout and the application's own connection settings are untouched.
        if (in_array($connection['driver'] ?? null, ['mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
            $connection['options'] = [\PDO::ATTR_TIMEOUT => $timeout] + (array) ($connection['options'] ?? []);
        }

        config(['database.connections.envcheck-live' => $connection]);

        try {
            DB::connection('envcheck-live')->getPdo();
            $this->ok('DB_CONNECTION (live)', 'the database answered');
        } catch (Throwable) {
            $this->wrong('DB_CONNECTION (live)', 'the DB_* values corrected', 'The database did not accept a connection.');
        } finally {
            DB::purge('envcheck-live');
        }
    }

    private function probeQueue(): void
    {
        $default = (string) config('queue.default');

        if (in_array($default, self::INLINE_QUEUES, true)) {
            return;
        }

        try {
            $queue = Queue::connection();
        } catch (Throwable) {
            $this->wrong('QUEUE_CONNECTION (live)', 'the queue settings for QUEUE_CONNECTION corrected', "The {$this->say('QUEUE_CONNECTION', $default)} queue connection could not be opened.");

            return;
        }

        // A size read is refused for lack of permission as readily as for a dead queue, so a
        // failure here is not proof the queue is down.
        try {
            $queue->size();
            $this->ok('QUEUE_CONNECTION (live)', "the {$this->say('QUEUE_CONNECTION', $default)} queue answered");
        } catch (Throwable) {
            $this->warn('QUEUE_CONNECTION (live)', 'the queue credentials for QUEUE_CONNECTION allowed to read its size', "Could not read the size of the {$this->say('QUEUE_CONNECTION', $default)} queue, which may be a permission limit rather than a dead queue.");
        }
    }

    /**
     * An authenticated read of an address nobody holds, through the same client the
     * application sends with. Only the status code is reported: a revoked or wrong-network
     * token is refused here, which a request with no credential cannot show.
     */
    private function probePhyrhose(string $network, int $timeout): void
    {
        $upper = strtoupper($network);
        $name = "PHYRHOSE_{$upper}_URL (live)";
        $zero = str_repeat('0', 56);
        $address = Bech32::encodeCardanoAddress(0, $network === 'mainnet' ? 1 : 0, $zero, $zero)['address'];

        try {
            $client = $network === 'mainnet' ? Http::mainnet_phyrhose() : Http::preprod_phyrhose();
            $response = $client->timeout($timeout)->connectTimeout($timeout)->withoutRedirecting()
                ->get('/firehose/queryLiveUtxos', ['address' => $address, 'era' => 'ALONZO']);
        } catch (Throwable) {
            $this->wrong($name, "PHYRHOSE_{$upper}_URL corrected or the backend restored", 'The backend did not answer, so claims cannot be sent.');

            return;
        }

        if ($response->successful()) {
            $this->ok($name, 'the backend accepted the token');
        } elseif ($response->status() === 401 || $response->status() === 403) {
            $this->wrong("PHYRHOSE_{$upper}_JWT (live)", "PHYRHOSE_{$upper}_JWT set to a current {$network} token", "The backend answered {$response->status()} to the token, so it is revoked, expired or for another network.");
        } else {
            $this->wrong($name, "PHYRHOSE_{$upper}_URL and PHYRHOSE_{$upper}_JWT corrected or the backend restored", "The backend answered {$response->status()} to a read.");
        }
    }

    private function probeKoios(string $network, int $timeout): void
    {
        $upper = strtoupper($network);
        $url = rtrim((string) config("cardano.koios.{$network}_url"), '/').'/tip';
        $name = "KOIOS_{$upper}_URL (live)";

        try {
            $request = Http::timeout($timeout)->connectTimeout($timeout)->acceptJson();
            $token = (string) config('cardano.koios.token');

            if ($token !== '') {
                $request = $request->withToken($token);
            }

            /** @var Response $response */
            $response = $request->get($url);
        } catch (Throwable) {
            $this->wrong($name, "KOIOS_{$upper}_URL corrected", 'Koios did not answer, so token lookups and protocol parameters fail.');

            return;
        }

        if ($response->successful()) {
            $this->ok($name, 'Koios answered with a tip');
        } elseif ($response->status() === 429) {
            $this->warn($name, 'KOIOS_API_TOKEN set to raise the rate limit', 'Koios answered 429, so it is rate limiting this deployment.');
        } else {
            $this->wrong($name, "KOIOS_{$upper}_URL and KOIOS_API_TOKEN corrected", "Koios answered {$response->status()} instead of a tip.");
        }
    }

    private function probeMail(int $timeout): void
    {
        $mailer = (string) config('mail.default');

        if ($mailer === 'postmark' && ! $this->blank(config('services.postmark.token'))) {
            try {
                $response = Http::timeout($timeout)->acceptJson()
                    ->withHeaders(['X-Postmark-Server-Token' => (string) config('services.postmark.token')])
                    ->get('https://api.postmarkapp.com/server');
            } catch (Throwable) {
                $this->warn('POSTMARK_TOKEN (live)', 'the network path to the mail provider checked', 'The mail provider did not answer.');

                return;
            }

            $response->successful()
                ? $this->ok('POSTMARK_TOKEN (live)', 'the mail provider accepted the token')
                : $this->wrong('POSTMARK_TOKEN (live)', 'POSTMARK_TOKEN set to the server API token', "The mail provider answered {$response->status()} to the token.");

            return;
        }

        if ($mailer === 'smtp') {
            $host = (string) config('mail.mailers.smtp.host');
            $port = (int) config('mail.mailers.smtp.port');
            $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

            if ($socket === false) {
                $this->wrong('MAIL_HOST (live)', 'MAIL_HOST and MAIL_PORT corrected', 'The mail server did not accept a connection.');
            } else {
                fclose($socket);
                $this->ok('MAIL_HOST (live)', 'the mail server accepted a connection');
            }
        }
    }
}

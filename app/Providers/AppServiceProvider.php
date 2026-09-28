<?php

namespace App\Providers;

use App\Cardano\ChainData;
use App\Contracts\TransactionBackend;
use App\Models\User;
use App\Services\NullBackend;
use App\Services\PhyrhoseBackend;
use App\Services\ProxyBackend;
use App\Support\ClaimToken;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use InvalidArgumentException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Default binding — used for new wallet creation and when no wallet context exists
        $this->app->bind(TransactionBackend::class, fn () => self::resolveBackend(config('cardano.transaction_backend', 'null')));

        // Read-only chain data: the epoch, the protocol parameters, what an address holds.
        // The reading itself lives in cardano-php/data-client, which takes a PSR-18 client
        // rather than reaching for one. Bound here so that the timeout is this deployment's
        // decision and so a test can hand the package a recorded transport without the
        // package knowing anything about either.
        $this->app->singleton(ClientInterface::class, fn () => new GuzzleClient([
            // A queued job reading chain state should fail rather than hold a worker open on
            // a provider that has stopped answering.
            'timeout' => max(1, (int) config('cardano.koios.timeout', 10)),
            'http_errors' => false,
        ]));

        $this->app->singleton(RequestFactoryInterface::class, fn () => new HttpFactory);
        $this->app->singleton(StreamFactoryInterface::class, fn () => new HttpFactory);

        $this->app->singleton(ChainData::class, fn ($app) => new ChainData(
            $app->make(ClientInterface::class),
            $app->make(RequestFactoryInterface::class),
            $app->make(StreamFactoryInterface::class),
            $app->make(CacheRepository::class),
            $app->make(LoggerInterface::class),
        ));
    }

    /**
     * Resolve a TransactionBackend by name. Used by the container binding
     * and by wallet-scoped operations that need the original backend.
     */
    public static function resolveBackend(?string $name): TransactionBackend
    {
        return match ($name) {
            'phyrhose' => new PhyrhoseBackend,
            'proxy' => new ProxyBackend,
            'null', null, '' => new NullBackend,
            // An unrecognised name used to fall through to the custodial backend, which
            // makes every misconfiguration silent and one of them dangerous: a deployment
            // set to a backend that does not exist yet would custody funds through the one
            // it was trying to stop using, and Wallet::resolveBackend() stamps that choice
            // onto each wallet for its whole life. Refusing at boot is the only point where
            // the mistake is still cheap.
            default => throw new InvalidArgumentException(
                "Unknown transaction backend [{$name}]. Set TRANSACTION_BACKEND to phyrhose, proxy or null."
            ),
        };
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Generate links with the scheme APP_URL declares. trustProxies already honours
        // X-Forwarded-Proto, but a TLS-terminating proxy is not obliged to send it, and
        // several do not. Without this the request reads as plain HTTP behind such a
        // proxy, so Ziggy publishes http:// route URLs into a page the browser loaded
        // over https. Every route() call then targets a foreign origin, which CSP
        // connect-src 'self' blocks and the browser also treats as mixed content.
        // Keyed on APP_URL rather than forced, so a self-hosted instance genuinely
        // served over plain HTTP is unaffected.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // Who may see the operator's view of the whole deployment. A gate rather than a
        // policy, because the subject is the platform and there is no model to authorise
        // against. The flag is a column so that promoting somebody is a deliberate act
        // rather than a change to an environment variable.
        Gate::define('platform-metrics', static fn (User $user) => $user->is_admin === true);

        Http::macro('mainnet_phyrhose', function () {
            return Http::withToken(config('cardano.phyrhose.mainnet_jwt'))
                ->acceptJson()
                ->baseUrl(config('cardano.phyrhose.mainnet_url'));
        });

        Http::macro('preprod_phyrhose', function () {
            return Http::withToken(config('cardano.phyrhose.preprod_jwt'))
                ->acceptJson()
                ->baseUrl(config('cardano.phyrhose.preprod_url'));
        });

        Http::macro('preprod_nmkr', function () {
            return Http::withToken(config('cardano.nmkr.preprod_api_key'))
                ->acceptJson()
                ->baseUrl(config('cardano.nmkr.preprod_url'));
        });

        Http::macro('mainnet_nmkr', function () {
            return Http::withToken(config('cardano.nmkr.mainnet_api_key'))
                ->acceptJson()
                ->baseUrl(config('cardano.nmkr.mainnet_url'));
        });

        Http::macro('koios', function (string $network = 'mainnet') {
            $url = config('cardano.koios.'.$network.'_url') ?? config('cardano.koios.mainnet_url');
            $request = Http::acceptJson()->baseUrl($url);
            $token = config('cardano.koios.token');

            return $token ? $request->withToken($token) : $request;
        });

        // Asset metadata lookups reach Koios for whatever is not cached. A campaign page asks
        // in batches of fifty, one at a time, so a tab showing 5,000 new assets needs 100;
        // anything past this in a minute is not a page loading.
        RateLimiter::for('known-assets', static function (Request $request) {
            return Limit::perMinute(max(1, (int) config('cardano.asset_metadata.lookups_per_minute', 120)))
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });

        RateLimiter::for('ManuallyProcessClaims', static function (object $job) {
            return Limit::perHour(1)->by($job->campaign_id);
        });

        // The analysis is a few hundred third-party queries per run and its answer only
        // moves as the chain does, so re-running it more than a few times an hour buys
        // nothing and spends someone else's rate limit.
        RateLimiter::for('AnalyzeCampaignOnboarding', static function (object $job) {
            return Limit::perHour(4)->by($job->campaign_id);
        });

        // The campaign page asks this while it is waiting on background work: every three
        // seconds for the first minute, then every ten. That is twenty requests a minute
        // from one open tab, so the limit leaves room for a handful of tabs on one campaign
        // and no more. Keyed on the account as well as the campaign, so one operator
        // hammering it cannot lock their colleague out of the same page.
        RateLimiter::for('campaign-tasks', static function (Request $request) {
            $campaign = $request->route('campaign');
            $campaignKey = $campaign instanceof \App\Models\Campaign ? $campaign->id : $campaign;

            return Limit::perMinute(60)
                ->by(($request->user()?->id ?? $request->ip()).'|campaign:'.$campaignKey);
        });

        // Every distinct combination of format, size, DPI and captions is a different archive
        // and a full render of every code in the campaign, so a dialog being cycled through
        // its options is a real amount of compute. A handful a minute is more than an
        // operator preparing a print run needs, and far less than a loop can spend. Keyed on
        // the account as well as the campaign, so one person cannot use up another's.
        RateLimiter::for('qr-exports', static function (Request $request) {
            $campaign = $request->route('campaign');
            $campaignKey = $campaign instanceof \App\Models\Campaign ? $campaign->id : $campaign;

            return Limit::perMinute(6)
                ->by(($request->user()?->id ?? $request->ip()).'|campaign:'.$campaignKey);
        });

        RateLimiter::for('claim-api', static function (Request $request) {
            $campaign = $request->route('campaign');
            $campaignKey = $campaign instanceof \App\Models\Campaign ? $campaign->id : $campaign;

            // Every claim submitted on an attendee's behalf arrives from the same external
            // caller's own backend, so a claim carrying a Sanctum token scoped to codes:claim
            // for this campaign is keyed on the token's own account instead: the IP and
            // campaign limits below exist to bound what one attendee's device can do, and a
            // server making every attendee's claim is not that. A token that is missing,
            // invalid, lacks the ability, or belongs to an account that cannot see this
            // campaign resolves to null and falls straight through to the same two limits an
            // unauthenticated claim gets today.
            //
            // Keyed on the account (tokenable_id) rather than the token's own id, so
            // rotating tokens — minting a second one instead of reusing the first — cannot
            // multiply the budget. One account gets one claim budget no matter how many
            // codes:claim tokens it holds at once.
            if ($campaign !== null) {
                $token = ClaimToken::resolve($request, $campaign);

                if ($token !== null) {
                    return Limit::perMinute(config('cardano.claim_rate_per_token', 300))
                        ->by('claim-user:'.$token->tokenable_id);
                }
            }

            return [
                Limit::perMinute(config('cardano.claim_rate_per_ip', 60))->by($request->ip()),
                Limit::perMinute(config('cardano.claim_rate_per_campaign', 120))->by('campaign:'.$campaignKey),
            ];
        });

        // The code API's caller has no dashboard and no IP an operator would recognise as
        // theirs — an integrator's own backend, a shared egress, whatever the request
        // happens to arrive behind — so the limit follows the token itself rather than the
        // address it arrives from. Falls back to the IP only for a request that somehow
        // reached here unauthenticated, which the abilities middleware refuses before this
        // is ever asked to act on it.
        //
        // Create and status are separate buckets, each keyed the same way. A caller
        // polling status while it waits on a claim must never be able to starve its own
        // ability to create the next code, and creating codes must never ration how often
        // it can be asked about one it already made.
        RateLimiter::for('code-api-create', static function (Request $request) {
            $tokenId = $request->user()?->currentAccessToken()?->id;

            return Limit::perMinute(config('cardano.code_api.create_rate_per_token', 60))
                ->by($tokenId !== null ? 'token:'.$tokenId : $request->ip());
        });

        RateLimiter::for('code-api-status', static function (Request $request) {
            $tokenId = $request->user()?->currentAccessToken()?->id;

            return Limit::perMinute(config('cardano.code_api.status_rate_per_token', 60))
                ->by($tokenId !== null ? 'token:'.$tokenId : $request->ip());
        });

        Inertia::share([
            'errors' => static function () {
                if (Session::get('errors')) {
                    return Session::get('errors')
                        ->getBag('default')
                        ->getMessages();
                }

                return (object) [];
            },
        ]);
    }
}

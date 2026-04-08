<?php

namespace App\Providers;

use App\Contracts\TransactionBackend;
use App\Services\NullBackend;
use App\Services\PhyrhoseBackend;
use App\Services\ProxyBackend;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Default binding — used for new wallet creation and when no wallet context exists
        $this->app->bind(TransactionBackend::class, fn () => self::resolveBackend(config('cardano.transaction_backend', 'null')));
    }

    /**
     * Resolve a TransactionBackend by name. Used by the container binding
     * and by wallet-scoped operations that need the original backend.
     */
    public static function resolveBackend(?string $name): TransactionBackend
    {
        return match ($name) {
            'proxy'          => new ProxyBackend(),
            'null', null, '' => new NullBackend(),
            default          => new PhyrhoseBackend(),
        };
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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

        RateLimiter::for('ManuallyProcessClaims', static function (object $job) {
            return Limit::perHour(1)->by($job->campaign_id);
        });

        RateLimiter::for('claim-api', static function (Request $request) {
            $campaign = $request->route('campaign');
            $campaignKey = $campaign instanceof \App\Models\Campaign ? $campaign->id : $campaign;

            return [
                Limit::perMinute(config('cardano.claim_rate_per_ip', 60))->by($request->ip()),
                Limit::perMinute(config('cardano.claim_rate_per_campaign', 120))->by('campaign:' . $campaignKey),
            ];
        });

        Inertia::share([
            'errors' => static function () {
                if (Session::get('errors')) {
                    return Session::get('errors')
                        ->getBag('default')
                        ->getMessages();
                }

                return (object)[];
            },
        ]);
    }
}

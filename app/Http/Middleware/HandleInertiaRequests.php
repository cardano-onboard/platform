<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'flash' => [
                // Read the 'message' session key — the convention used by every
                // redirect()->with('message', ...) across the app. (Previously read
                // 'flash', which only ProfileController set, so all CampaignController
                // messages — refunds, claim checks, the ended-campaign QR notice —
                // were silently dropped before reaching the frontend.)
                'message' => fn () => $request->session()
                    ->get('message'),
            ],
            'auth' => [
                'user' => $request->user(),
            ],
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'beta_banner' => config('cardano.beta_banner', true),
            'transaction_backend' => config('cardano.transaction_backend') ?? 'null',
        ];
    }
}

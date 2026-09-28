<?php

namespace App\Http\Middleware;

use App\Support\DeploymentNotice;
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
                // What asking for a QR export actually did, as something a dialog can branch
                // on rather than a sentence it would have to read. The distinction that
                // matters is ready against queued: an archive already on the disk has to
                // offer its download immediately, and cannot be routed through a progress
                // panel for work that nobody is doing.
                'qr_export' => fn () => $request->session()
                    ->get('qr_export'),
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
            ...$this->deploymentNoticeProp($request),
        ];
    }

    /**
     * ['deployment_notice' => [...]] when one is configured and the current page is one
     * an operator lands on, otherwise an empty array so the key is absent from the
     * response rather than present and null. Absent, because an attendee who scans a QR
     * code printed months ago and lands on /ada/now or a claim link must never be told
     * the platform they are on has moved — the pages that can say that are the ones an
     * operator actually visits, never the ones a claim recipient is sent to.
     *
     * @return array{deployment_notice?: array}
     */
    private function deploymentNoticeProp(Request $request): array
    {
        if ($this->isAttendeeFacing($request)) {
            return [];
        }

        $notice = DeploymentNotice::share();

        return $notice ? ['deployment_notice' => $notice] : [];
    }

    /**
     * A page a claim recipient can land on: the /ada/now onboarding page (and its
     * subdomain mirror) and the claim endpoints. The claim endpoints are registered on
     * the 'api' middleware group and never reach this middleware at all; the check is
     * kept here anyway as the one place this rule is stated, so it still holds if a
     * claim-facing route is ever added to the 'web' group.
     */
    private function isAttendeeFacing(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        return str_starts_with($name, 'ada.') || str_starts_with($name, 'claim.');
    }
}

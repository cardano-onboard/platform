<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The env-configured banner an operator sees on their own pages, and must never see on
 * a page a claim recipient can land on. See App\Support\DeploymentNotice and
 * App\Http\Middleware\HandleInertiaRequests.
 */
class DeploymentNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_prop_is_absent_by_default(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('deployment_notice')
            );
    }

    public function test_the_prop_carries_the_configured_message_and_link(): void
    {
        config([
            'notice.message' => 'This platform is now preprod only. Create mainnet campaigns on the main app.',
            'notice.type' => 'warning',
            'notice.link.url' => 'https://example.com',
            'notice.link.text' => 'Go to the main app',
        ]);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deployment_notice', [
                    'message' => 'This platform is now preprod only. Create mainnet campaigns on the main app.',
                    'type' => 'warning',
                    'link' => [
                        'url' => 'https://example.com',
                        'text' => 'Go to the main app',
                    ],
                ])
            );
    }

    public function test_the_type_falls_back_to_info_for_an_unrecognised_value(): void
    {
        config([
            'notice.message' => 'Notice',
            'notice.type' => 'danger',
        ]);

        $this->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deployment_notice.type', 'info')
            );
    }

    public function test_the_type_defaults_to_info_when_unset(): void
    {
        config(['notice.message' => 'Notice']);

        $this->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deployment_notice.type', 'info')
            );
    }

    /**
     * javascript://alert(1) is syntactically a valid URL (filter_var alone would accept
     * it), so this is the case that actually exercises the scheme allowlist rather than
     * general URL syntax checking. Deliberately not the more familiar javascript:alert(1)
     * — that one has no authority component and filter_var rejects it on syntax alone,
     * which would pass this test even with the scheme check deleted.
     */
    public function test_the_link_is_dropped_when_the_scheme_is_not_http_or_https(): void
    {
        config([
            'notice.message' => 'Notice',
            'notice.link.url' => 'javascript://alert(1)',
            'notice.link.text' => 'Click me',
        ]);

        $this->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deployment_notice.message', 'Notice')
                ->where('deployment_notice.link', null)
            );
    }

    public function test_the_link_is_dropped_when_the_url_is_malformed(): void
    {
        config([
            'notice.message' => 'Notice',
            'notice.link.url' => 'not a url',
        ]);

        $this->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deployment_notice.link', null)
            );
    }

    public function test_the_link_text_falls_back_to_the_url(): void
    {
        config([
            'notice.message' => 'Notice',
            'notice.link.url' => 'https://example.com',
            'notice.link.text' => '',
        ]);

        $this->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deployment_notice.link', [
                    'url' => 'https://example.com',
                    'text' => 'https://example.com',
                ])
            );
    }

    public function test_the_link_is_absent_when_nothing_is_configured_for_it(): void
    {
        config(['notice.message' => 'Notice only, no link']);

        $this->get(route('login'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deployment_notice.link', null)
            );
    }

    public function test_authenticated_operator_pages_receive_the_notice_too(): void
    {
        config(['notice.message' => 'Notice']);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('deployment_notice.message', 'Notice')
            );
    }

    /**
     * The claim endpoint is a JSON API, not an Inertia page, and is registered on the
     * 'api' middleware group, which never runs HandleInertiaRequests. This is the
     * belt on top of that braces: whatever answers a claim, it carries no such key.
     */
    public function test_the_claim_endpoint_never_carries_the_notice(): void
    {
        config(['notice.message' => 'This platform is now preprod only.']);

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create([
            'network' => 'preprod',
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
        Wallet::factory()->for($campaign)->create();
        $code = Code::factory()->for($campaign)->create([
            'uses' => 10,
            'perWallet' => 1,
            'lovelace' => 2000000,
        ]);

        $response = $this->postJson(route('claim.v1', $campaign), [
            'code' => $code->code,
            'address' => 'addr_test1qz'.str_repeat('a', 50),
        ]);

        $response->assertJsonMissing(['deployment_notice']);
        $this->assertArrayNotHasKey('deployment_notice', $response->json());
    }
}

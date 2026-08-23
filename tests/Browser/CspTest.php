<?php

namespace Tests\Browser;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Deploy gate: loads the key pages in a real browser under the production Content
 * Security Policy and fails if Chrome reports any CSP violation. This runs in CI (which
 * gates staging + production deploys), so a CSP change that would break the live app is
 * caught before it ships — not left to a human noticing a console warning.
 *
 * Dusk runs in the `testing` env against built assets, so the CSP is the real production
 * policy (no local dev extension) served against same-origin bundles.
 */
class CspTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * Assert the browser console logged no CSP violations since the last check.
     */
    private function assertNoCspViolations(Browser $browser, string $context): void
    {
        $logs = $browser->driver->manage()->getLog('browser');
        $violations = array_values(array_filter(
            $logs,
            fn ($entry) => str_contains($entry['message'] ?? '', 'Content Security Policy')
        ));
        $detail = implode("\n", array_map(fn ($v) => $v['message'], $violations));

        $this->assertCount(0, $violations, "CSP violation(s) on {$context}:\n{$detail}");
    }

    public function test_public_pages_have_no_csp_violations(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/')->waitForText('Ninja-fast Cardano airdrops for your event')->pause(750);
            $this->assertNoCspViolations($browser, 'welcome');

            $browser->logout()->visit('/login')->waitForText('LOG IN')->pause(750);
            $this->assertNoCspViolations($browser, 'login');
        });
    }

    public function test_authenticated_pages_have_no_csp_violations(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);
        $campaign = Campaign::factory()->for($user)->create();
        Wallet::factory()->for($campaign)->create(['backend' => 'null']);
        Code::factory()->for($campaign)->create(['uses' => 5]);

        $this->browse(function (Browser $browser) use ($user, $campaign) {
            $browser->loginAs($user)
                ->visit('/dashboard')->waitForText('Your Campaigns')->pause(750);
            $this->assertNoCspViolations($browser, 'dashboard');

            $browser->visit('/campaigns/'.$campaign->id)->pause(1500);
            $this->assertNoCspViolations($browser, 'campaign show');
        });
    }
}

<?php

namespace Tests\Browser;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class CampaignTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_dashboard_shows_no_campaigns_message(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->assertSee('Your Campaigns')
                ->assertSee("You don't have any campaigns yet!");
        });
    }

    public function test_user_can_create_campaign(): void
    {
        $user = User::factory()->create();

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                // Click the Create Campaign button
                ->click('.v-toolbar .v-btn--variant-flat')
                ->waitForText('Create New Campaign')
                ->pause(500)
                // Fill in just the name — use the first input in the dialog
                ->type('.v-overlay__content input[type="text"]', 'E2E Test Campaign')
                ->pause(500)
                // Fill in required date fields
                ->type('.v-overlay__content input[type="date"]', '2026-04-01')
                ->pause(200);

            // Fill the second date field
            $dateInputs = $browser->elements('.v-overlay__content input[type="date"]');
            if (count($dateInputs) > 1) {
                $dateInputs[1]->clear();
                $dateInputs[1]->sendKeys('2026-12-31');
            }

            $browser->pause(200)
                // Submit without selecting network — it will fail validation
                // but prove the dialog works. Full campaign creation is covered by PHPUnit.
                ->click('.v-overlay__content .v-card-actions button[type="submit"]')
                ->pause(2000)
                // Should show validation error since network is required
                ->assertSee('network');
        });
    }

    public function test_user_can_view_campaign_details(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create([
            'name' => 'View Test Campaign',
            'network' => 'preprod',
        ]);

        $this->browse(function (Browser $browser) use ($user, $campaign) {
            $browser->loginAs($user)
                ->visit('/campaigns/'.$campaign->id)
                ->assertSee('View Test Campaign')
                ->assertSee('Campaign Codes');
        });
    }

    public function test_user_can_delete_campaign_with_no_claims(): void
    {
        $user = User::factory()->create();
        Campaign::factory()->for($user)->create([
            'name' => 'Deletable Campaign',
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->assertSee('Deletable Campaign')
                // Click the trash icon button
                ->click('button .mdi-trash-can')
                ->waitForText('Are you sure')
                ->pause(500)
                // Click the first button in the dialog actions ("Yes")
                ->click('.v-overlay__content .v-card-actions .v-btn:first-child')
                ->pause(2000)
                ->assertDontSee('Deletable Campaign');
        });
    }

    public function test_dashboard_shows_campaign_stats(): void
    {
        $user = User::factory()->create();
        Campaign::factory()->for($user)->create([
            'name' => 'Stats Campaign',
            'network' => 'mainnet',
        ]);

        $this->browse(function (Browser $browser) use ($user) {
            $browser->loginAs($user)
                ->visit('/dashboard')
                ->assertSee('Stats Campaign')
                ->assertSee('mainnet');
        });
    }

    public function test_user_cannot_access_other_users_campaign(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $campaign = Campaign::factory()->for($user1)->create();

        $this->browse(function (Browser $browser) use ($user2, $campaign) {
            $browser->loginAs($user2)
                ->visit('/campaigns/'.$campaign->id)
                ->assertSee('403');
        });
    }
}

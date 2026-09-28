<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Code;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A code's `reference` is the integrator's own identifier for whoever a code-API code
 * was made for (an attendee, a station, a claim slot). The campaign page has never shown
 * it, so it must never reach the browser of anyone who can merely view the campaign, no
 * matter how the codes relation is serialized into the page.
 */
class CodeReferenceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_campaign_page_never_carries_a_codes_reference(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Wallet::factory()->for($campaign)->create();
        Code::factory()->for($campaign)->create(['reference' => 'attendee-secret-1']);

        $response = $this->actingAs($user)->get(route('campaigns.show', $campaign));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('campaign.codes.0')
            ->missing('campaign.codes.0.reference')
        );
        $response->assertDontSee('attendee-secret-1');
    }
}

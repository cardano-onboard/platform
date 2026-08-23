<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\Reward;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CodeDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function campaignFor(User $user): Campaign
    {
        return Campaign::factory()->create(['user_id' => $user->id]);
    }

    public function test_owner_can_delete_an_unclaimed_code(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignFor($user);
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        $this->actingAs($user)
            ->delete(route('codes.destroy', $code))
            ->assertRedirect();

        $this->assertDatabaseMissing('codes', ['id' => $code->id]);
    }

    /**
     * The claims are the record that someone was paid. Deleting the code they hang off
     * would either orphan that history or erase it.
     */
    public function test_a_claimed_code_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignFor($user);
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);
        Claim::factory()->completed()->create(['code_id' => $code->id]);

        $this->actingAs($user)
            ->delete(route('codes.destroy', $code))
            ->assertRedirect();

        $this->assertDatabaseHas('codes', ['id' => $code->id]);
    }

    public function test_deleting_a_code_removes_its_rewards(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignFor($user);
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);
        $reward = Reward::factory()->create(['code_id' => $code->id]);

        $this->actingAs($user)->delete(route('codes.destroy', $code));

        $this->assertDatabaseMissing('codes', ['id' => $code->id]);
        $this->assertDatabaseMissing('rewards', ['id' => $reward->id]);
    }

    public function test_another_user_cannot_delete_a_code(): void
    {
        $campaign = $this->campaignFor(User::factory()->create());
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        $this->actingAs(User::factory()->create())
            ->delete(route('codes.destroy', $code))
            ->assertForbidden();

        $this->assertDatabaseHas('codes', ['id' => $code->id]);
    }

    public function test_a_guest_cannot_delete_a_code(): void
    {
        $campaign = $this->campaignFor(User::factory()->create());
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        $this->delete(route('codes.destroy', $code))->assertRedirect(route('login'));
        $this->assertDatabaseHas('codes', ['id' => $code->id]);
    }

    public function test_owner_can_delete_a_selected_batch(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignFor($user);
        $codes = Code::factory()->count(3)->create(['campaign_id' => $campaign->id]);
        $keep = Code::factory()->create(['campaign_id' => $campaign->id]);

        $this->actingAs($user)
            ->delete(route('campaigns.codes.bulk-destroy', $campaign), [
                'codes' => $codes->pluck('id')->all(),
            ])
            ->assertRedirect();

        $this->assertSame(1, $campaign->codes()->count());
        $this->assertDatabaseHas('codes', ['id' => $keep->id]);
    }

    /**
     * The case the feature exists for: a bulk import of several hundred codes that were
     * all generated with the wrong reward, which cannot realistically be selected by hand
     * through a paginated table.
     */
    public function test_owner_can_clear_every_unclaimed_code_in_one_go(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignFor($user);
        Code::factory()->count(5)->create(['campaign_id' => $campaign->id]);

        $claimed = Code::factory()->create(['campaign_id' => $campaign->id]);
        Claim::factory()->completed()->create(['code_id' => $claimed->id]);

        $this->actingAs($user)
            ->delete(route('campaigns.codes.bulk-destroy', $campaign), ['all_unclaimed' => true])
            ->assertRedirect();

        $this->assertSame(1, $campaign->codes()->count());
        $this->assertDatabaseHas('codes', ['id' => $claimed->id]);
    }

    /**
     * A batch that saw a couple of early claims should still be clearable; the claimed
     * codes are kept and reported rather than failing the whole request.
     */
    public function test_claimed_codes_in_a_selection_are_kept_and_the_rest_are_deleted(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignFor($user);
        $unclaimed = Code::factory()->count(2)->create(['campaign_id' => $campaign->id]);
        $claimed = Code::factory()->create(['campaign_id' => $campaign->id]);
        Claim::factory()->completed()->create(['code_id' => $claimed->id]);

        $this->actingAs($user)
            ->delete(route('campaigns.codes.bulk-destroy', $campaign), [
                'codes' => $unclaimed->pluck('id')->push($claimed->id)->all(),
            ])
            ->assertRedirect()
            ->assertSessionHas('message', fn ($m) => str_contains($m, '2 code(s) deleted')
                && str_contains($m, '1 already claimed'));

        $this->assertDatabaseHas('codes', ['id' => $claimed->id]);
    }

    /**
     * Scoping the delete to the campaign in the route is what stops a crafted request
     * from reaching into someone else's campaign by ID.
     */
    public function test_bulk_delete_cannot_reach_codes_in_another_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = $this->campaignFor($user);
        $mine = Code::factory()->create(['campaign_id' => $campaign->id]);

        $otherCampaign = $this->campaignFor(User::factory()->create());
        $theirs = Code::factory()->create(['campaign_id' => $otherCampaign->id]);

        $this->actingAs($user)
            ->delete(route('campaigns.codes.bulk-destroy', $campaign), [
                'codes' => [$mine->id, $theirs->id],
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('codes', ['id' => $mine->id]);
        $this->assertDatabaseHas('codes', ['id' => $theirs->id]);
    }

    public function test_another_user_cannot_bulk_delete(): void
    {
        $campaign = $this->campaignFor(User::factory()->create());
        $code = Code::factory()->create(['campaign_id' => $campaign->id]);

        $this->actingAs(User::factory()->create())
            ->delete(route('campaigns.codes.bulk-destroy', $campaign), ['all_unclaimed' => true])
            ->assertForbidden();

        $this->assertDatabaseHas('codes', ['id' => $code->id]);
    }
}

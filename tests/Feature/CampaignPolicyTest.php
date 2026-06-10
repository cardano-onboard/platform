<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Wallet::factory()->for($campaign)->create();

        $this->assertTrue($user->can('view', $campaign));
    }

    public function test_non_owner_cannot_view_campaign(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $campaign = Campaign::factory()->for($owner)->create();

        $this->assertFalse($other->can('view', $campaign));
    }

    public function test_owner_can_update_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->assertTrue($user->can('update', $campaign));
    }

    public function test_non_owner_cannot_update_campaign(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $campaign = Campaign::factory()->for($owner)->create();

        $this->assertFalse($other->can('update', $campaign));
    }

    public function test_owner_can_delete_campaign(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $this->assertTrue($user->can('delete', $campaign));
    }

    public function test_non_owner_cannot_delete_campaign(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $campaign = Campaign::factory()->for($owner)->create();

        $this->assertFalse($other->can('delete', $campaign));
    }

    public function test_any_user_can_create_campaign(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->can('create', Campaign::class));
    }
}

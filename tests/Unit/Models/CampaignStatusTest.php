<?php

namespace Tests\Unit\Models;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_campaign(): void
    {
        $campaign = Campaign::factory()->for(User::factory())->create([
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $this->assertEquals('active', $campaign->status);
    }

    public function test_upcoming_campaign(): void
    {
        $campaign = Campaign::factory()->for(User::factory())->create([
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $this->assertEquals('upcoming', $campaign->status);
    }

    public function test_ended_campaign(): void
    {
        $campaign = Campaign::factory()->for(User::factory())->create([
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ]);

        $this->assertEquals('ended', $campaign->status);
    }

    public function test_campaign_starting_today_is_active(): void
    {
        $campaign = Campaign::factory()->for(User::factory())->create([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $this->assertEquals('active', $campaign->status);
    }

    public function test_campaign_ending_today_is_active(): void
    {
        $campaign = Campaign::factory()->for(User::factory())->create([
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->toDateString(),
        ]);

        $this->assertEquals('active', $campaign->status);
    }

    public function test_status_is_appended_to_json(): void
    {
        $campaign = Campaign::factory()->for(User::factory())->create([
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);

        $json = $campaign->toArray();

        $this->assertArrayHasKey('status', $json);
        $this->assertEquals('active', $json['status']);
    }
}

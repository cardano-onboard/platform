<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignTask>
 */
class CampaignTaskFactory extends Factory
{
    protected $model = CampaignTask::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'type' => 'codes-import',
            'dedupe_key' => CampaignTask::DEFAULT_KEY,
            'status' => CampaignTask::STATUS_QUEUED,
            'progress_done' => 0,
            'progress_total' => null,
            'heartbeat_at' => now(),
        ];
    }

    public function running(): static
    {
        return $this->state(fn () => [
            'status' => CampaignTask::STATUS_RUNNING,
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    public function complete(array $result = []): static
    {
        return $this->state(fn () => [
            'status' => CampaignTask::STATUS_COMPLETE,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'result' => $result === [] ? null : $result,
        ]);
    }

    public function failed(string $error = 'It did not work.'): static
    {
        return $this->state(fn () => [
            'status' => CampaignTask::STATUS_FAILED,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'error' => $error,
        ]);
    }

    /** A run whose worker stopped reporting long enough ago to be reclaimed. */
    public function stale(): static
    {
        return $this->state(fn () => [
            'status' => CampaignTask::STATUS_RUNNING,
            'started_at' => now()->subSeconds(CampaignTask::staleAfterSeconds() * 2),
            'heartbeat_at' => now()->subSeconds(CampaignTask::staleAfterSeconds() + 60),
        ]);
    }
}

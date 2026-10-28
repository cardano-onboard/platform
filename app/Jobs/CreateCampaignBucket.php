<?php

namespace App\Jobs;

use App\Contracts\TransactionBackend;
use App\Models\Campaign;
use App\Models\Wallet;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateCampaignBucket implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(public string $campaign_id) {}

    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->campaign_id),
        ];
    }

    public function handle(TransactionBackend $backend): void
    {
        $campaign = Campaign::find($this->campaign_id);

        if (! $campaign) {
            Log::warning('CreateCampaignBucket: campaign not found.', ['campaign_id' => $this->campaign_id]);

            return;
        }

        if ($campaign->wallet()->exists()) {
            Log::info('CreateCampaignBucket: wallet already exists, skipping.', ['campaign_id' => $this->campaign_id]);

            return;
        }

        $bucket = $backend->createBucket($campaign, $campaign->network);

        // A backend that holds the key itself stores the wallet as part of making it, in the same
        // database transaction as the key, and a second insert here would be refused by the one
        // wallet per campaign constraint. The hosted backends only answer with an address, and
        // the wallet is recorded here as it always was.
        if (! $campaign->wallet()->exists()) {
            Wallet::create([
                'campaign_id' => $campaign->id,
                'address' => $bucket['address'],
                'key' => $bucket['campaignId'],
                'backend' => config('cardano.transaction_backend') ?? 'null',
            ]);
        }

        // The campaign id, and not the address. An address in a log line is an address in
        // whatever aggregates the logs, held by whoever can read that, for as long as it is
        // retained, and none of those are places a campaign's address was meant to reach. It
        // also says nothing here the campaign id does not: the address is one lookup away for
        // anybody entitled to it, and out of reach for anybody who is not.
        Log::info('CreateCampaignBucket: wallet created.', [
            'campaign_id' => $campaign->id,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CreateCampaignBucket: job failed after all retries.', [
            'campaign_id' => $this->campaign_id,
            'error' => $exception->getMessage(),
        ]);
    }
}

<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CampaignClaimClient;
use App\Models\ClaimUserAgent;
use App\Support\ClaimClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records what a claim arrived from, once the claim has been accepted.
 *
 * Called only where a claim is created. A rejected request says what somebody pointed at
 * the endpoint, and rejections are dominated by scanners and retries, so counting them
 * would measure traffic rather than wallets.
 *
 * Nothing here may break a claim. The tally is analytics and the claim is the product, so
 * every failure is swallowed and logged.
 */
class ClaimClientRecorder
{
    public function record(Campaign $campaign, ?string $userAgent): void
    {
        try {
            $client = ClaimClient::from($userAgent);

            CampaignClaimClient::tally($campaign->id, $client->client);

            if ($client->hasUserAgent()) {
                $this->catalogue($client);
            }
        } catch (Throwable $e) {
            Log::warning('Could not record the claim client.', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Keep one row per distinct string, scoped to no campaign and no claim.
     *
     * The client and wallet columns are refreshed on every sighting so a rule change is
     * reflected in the catalogue without a backfill. The string itself never changes,
     * because it is what the row is keyed on.
     */
    private function catalogue(ClaimClient $client): void
    {
        ClaimUserAgent::updateOrCreate(
            ['fingerprint' => $client->fingerprint()],
            [
                'user_agent' => $client->userAgent,
                'client' => $client->client,
                'wallet' => $client->wallet,
            ],
        );
    }
}

<?php

namespace App\Jobs;

use App\Models\Campaign;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessClaims implements ShouldQueue, ShouldBeUnique
{

    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $campaign_id)
    {
        Log::debug("Building ProcessClaims job...", ['id' => $campaign_id]);
    }

    public function handle(): void
    {
        Log::debug("Handling ProcessClaims request...", ['campaign_id' => $this->campaign_id]);
        $campaign = Campaign::with('wallet')
            ->find($this->campaign_id);

        $unfinished_claims = $campaign->claims()
            ->with(['code.rewards'])
            ->whereNull('transaction_id')
            ->get();

        if ($unfinished_claims->isEmpty()) {
            return;
        }

        $backend = $campaign->wallet->resolveBackend();

        $recipients = [];
        foreach ($unfinished_claims as $claim) {
            $claimed_code = [
                'pooCode' => $claim->code->code,
                'address' => $claim->address,
                'lovelace' => $claim->code->lovelace,
                'tokens' => [],
            ];

            foreach ($claim->code->rewards as $token) {
                $claimed_code['tokens'][] = [
                    'policy' => $token->policy_hex,
                    'name' => $token->asset_hex,
                    'amount' => $token->quantity,
                ];
            }

            $recipients[] = $claimed_code;
        }

        $result = $backend->submitPayment(
            $campaign->wallet->key,
            $recipients,
            $campaign->network,
            $campaign->txn_msg
        );

        $ids = $result['purchaseIds'];

        if (!empty($ids)) {
            Log::debug("Payment submitted.", ['campaign_id' => $this->campaign_id]);

            foreach ($unfinished_claims as $claim) {
                $purchaseId = $ids[$claim->code->code][$claim->address] ?? null;
                if ($purchaseId) {
                    $claim->transaction_id = $purchaseId;
                }
                $claim->save();
            }
        }
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->campaign_id))->releaseAfter(config('cardano.push_delay', 5) * 60),
        ];
    }

    public function uniqueId(): string
    {
        return $this->campaign_id;
    }
}

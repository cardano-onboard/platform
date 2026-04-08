<?php

namespace App\Jobs;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class CheckClaims implements ShouldQueue, ShouldBeUnique {

    use Dispatchable, InteractsWithQueue, Queueable;

    public const MAX_RETRIES = 5;

    public function __construct(public string $campaign_id) {
        //
    }

    public function handle(): void {
        Log::debug("Handling checkClaims request...", ['campaign_id' => $this->campaign_id]);

        $campaign = Campaign::with('wallet')
                            ->find($this->campaign_id);

        $check_claims = $campaign->claims()
                                 ->with(['code.rewards'])
                                 ->whereNotNull('transaction_id')
                                 ->whereNull('transaction_hash')
                                 ->where('status', '!=', 'failed')
                                 ->get();

        $backend = $campaign->wallet->resolveBackend();

        foreach ($check_claims as $claim) {
            if (!$claim->transaction_id) {
                continue;
            }

            $result = $backend->checkStatus($claim->transaction_id, $campaign->network);

            switch ($result['status']) {
                case 'completed':
                    $claim->transaction_hash = $result['txHash'];
                    $claim->status = 'completed';
                    $claim->save();
                    break;

                case 'timeout':
                    $claim->retry_count++;
                    if ($claim->retry_count >= self::MAX_RETRIES) {
                        $claim->status = 'failed';
                        $claim->save();
                        Log::warning("Claim exceeded max retries, marked as failed.", [
                            'claim_id'    => $claim->id,
                            'retry_count' => $claim->retry_count,
                        ]);
                    } else {
                        $claim->transaction_id = null;
                        $claim->status = 'pending';
                        $claim->save();
                        ProcessClaims::dispatch($this->campaign_id)
                                     ->delay(now()->addMinutes(config('cardano.push_delay', 5)));
                    }
                    break;
            }
        }
    }

    public function middleware(): array {
        return [
            new RateLimited('ManuallyProcessClaims'),
            (new WithoutOverlapping($this->campaign_id))->dontRelease()
                                                        ->expireAfter(180),
        ];
    }
}

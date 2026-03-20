<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ManuallyProcessClaims implements ShouldQueue {

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(public string $campaign_id) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void {
        //
        ProcessClaims::dispatch($this->campaign_id)
                     ->delay(now()->addMinutes(config('cardano.push_delay')));
    }

    public function middleware(): array {
        return [
            new RateLimited('ManuallyProcessClaims'),
            (new WithoutOverlapping($this->campaign_id))->dontRelease()
                                                        ->expireAfter(180),
        ];
    }
}

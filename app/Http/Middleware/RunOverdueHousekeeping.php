<?php

namespace App\Http\Middleware;

use App\Support\Housekeeping;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Let traffic stand in for the scheduler on a deployment that does not run one.
 *
 * Nothing happens while the request is handled. The work is in terminate(), which runs
 * after the response has been sent to the client, and even there it is one cache read per
 * request for the time the next task falls due, a query and a cache write when that entry
 * has expired, and, only once a task is due and at most once per check interval, one job
 * pushed onto the queue. The tasks themselves run on a queue worker, never in the web
 * process.
 */
class RunOverdueHousekeeping
{
    public function __construct(private Housekeeping $housekeeping) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->housekeeping->checkFromTraffic();
        } catch (Throwable $e) {
            // Housekeeping is never a reason for a request to fail.
            Log::warning('Housekeeping: could not queue the overdue-task check', ['error' => $e->getMessage()]);
        }
    }
}

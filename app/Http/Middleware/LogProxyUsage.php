<?php

namespace App\Http\Middleware;

use App\Models\ProxyUsage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogProxyUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->isSuccessful()) {
            ProxyUsage::create([
                'user_id'  => $request->user()->id,
                'endpoint' => $request->path(),
            ]);
        }

        return $response;
    }
}

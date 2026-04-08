<?php

namespace App\Http\Middleware;

use App\Models\ProxyUsage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckProxyQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $limit = config('cardano.proxy.monthly_limit', 1000);

        $usage = ProxyUsage::where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        if ($usage >= $limit) {
            return response()->json([
                'error' => 'Monthly proxy quota exceeded.',
                'usage' => $usage,
                'limit' => $limit,
            ], 429);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            ProxyUsage::create([
                'user_id'  => $user->id,
                'endpoint' => $request->path(),
            ]);
        }

        return $response;
    }
}

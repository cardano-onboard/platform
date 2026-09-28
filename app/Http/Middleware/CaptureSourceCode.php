<?php

namespace App\Http\Middleware;

use App\Models\SourceCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Captures a source code off an incoming link, then removes it from the URL.
 *
 * The redirect is the point of the middleware as much as the capture is. A parameter left
 * visible in the address bar gets copied into shares and pasted into chats, and every visit
 * that follows is then credited to whichever code happened to travel, which is worse than
 * no attribution because it looks like data.
 *
 * An unknown or malformed code is stripped just the same and recorded nowhere. Four hex
 * characters against a few hundred in use means a typo lands on nothing rather than on
 * somebody else's campaign.
 */
class CaptureSourceCode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('analytics.source_codes.enabled') || ! $this->shouldCapture($request)) {
            return $next($request);
        }

        try {
            $code = SourceCode::normalise($request->query(SourceCode::PARAM));

            if ($code !== null && SourceCode::whereKey($code)->exists()) {
                $request->session()->put(SourceCode::SESSION_KEY, $code);
                SourceCode::recordVisit($code);
            }
        } catch (Throwable $e) {
            // Attribution is never worth a failed page load.
            Log::warning('Could not capture the source code on a visit.', ['error' => $e->getMessage()]);
        }

        return redirect()->to($request->fullUrlWithoutQuery(SourceCode::PARAM));
    }

    /**
     * Only a plain page load is redirected. A form post carrying the parameter would lose
     * its body to the redirect, and an XHR would follow it without the address bar ever
     * changing, so neither is worth the risk of interfering with.
     */
    private function shouldCapture(Request $request): bool
    {
        return $request->isMethod('GET')
            && ! $request->ajax()
            && $request->query->has(SourceCode::PARAM);
    }
}

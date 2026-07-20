<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ClaimException extends Exception
{
    public function render($request): JsonResponse
    {
        $payload = config('errorcodes.'.$this->getMessage());

        Log::info('Claim Response (error)', [
            'campaign_id' => $request->route('campaign')?->id,
            'error_code' => $this->getMessage(),
            'response' => $payload,
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json($payload);
    }
}

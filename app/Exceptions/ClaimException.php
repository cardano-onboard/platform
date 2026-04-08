<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class ClaimException extends Exception {

    public function render($request): JsonResponse {
        return response()->json(config('errorcodes.' . $this->getMessage()));
    }
}

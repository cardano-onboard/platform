<?php

namespace App\Http\Controllers;

use App\Jobs\CheckClaims;
use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class JobsController extends Controller
{

    public function checkClaims(Request $request, Campaign $campaign) {
        Log::debug("Received request to check the claims for {$campaign->id}");
        CheckClaims::dispatch($campaign->id);
        return "OK";
    }


}

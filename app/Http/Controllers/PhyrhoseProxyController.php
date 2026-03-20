<?php

namespace App\Http\Controllers;

use App\Contracts\TransactionBackend;
use App\Models\Campaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhyrhoseProxyController extends Controller
{
    public function __construct(private TransactionBackend $backend) {}

    public function createBucket(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'network' => 'required|in:preprod,preview,mainnet',
        ]);

        $campaign = new Campaign();
        $campaign->name = $validated['name'];
        $campaign->user_id = $request->user()->id;

        $result = $this->backend->createBucket($campaign, $validated['network']);

        return response()->json($result);
    }

    public function submitPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaignId' => 'required|string',
            'recipients' => 'required|array',
            'network'    => 'required|in:preprod,preview,mainnet',
            'txnMsg'     => 'nullable|string|max:64',
        ]);

        $result = $this->backend->submitPayment(
            $validated['campaignId'],
            $validated['recipients'],
            $validated['network'],
            $validated['txnMsg'] ?? null
        );

        return response()->json($result);
    }

    public function checkStatus(Request $request, string $purchaseId): JsonResponse
    {
        $validated = $request->validate([
            'network' => 'required|in:preprod,preview,mainnet',
        ]);

        $result = $this->backend->checkStatus($purchaseId, $validated['network']);

        return response()->json($result);
    }

    public function refund(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'campaignId' => 'required|string',
            'address'    => 'required|string',
            'network'    => 'required|in:preprod,preview,mainnet',
        ]);

        $success = $this->backend->refund(
            $validated['campaignId'],
            $validated['address'],
            $validated['network']
        );

        return response()->json(['success' => $success]);
    }

    public function getBalance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'address' => 'required|string',
            'network' => 'required|in:preprod,preview,mainnet',
        ]);

        $result = $this->backend->getBalance($validated['address'], $validated['network']);

        return response()->json($result);
    }
}

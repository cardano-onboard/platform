<?php

namespace App\Services;

use App\Contracts\TransactionBackend;
use App\Models\Campaign;
use Illuminate\Support\Facades\Http;

class ProxyBackend implements TransactionBackend
{
    private function client()
    {
        return Http::withToken(config('cardano.proxy_api_token'))
            ->acceptJson()
            ->baseUrl(config('cardano.proxy_api_url'));
    }

    public function createBucket(Campaign $campaign, string $network): array
    {
        $response = $this->client()->post('/bucket', [
            'name'    => $campaign->user_id . '-' . $campaign->name,
            'network' => $network,
        ]);

        return $response->json();
    }

    public function submitPayment(string $campaignId, array $recipients, string $network, ?string $txnMsg = null): array
    {
        $payload = [
            'campaignId' => $campaignId,
            'recipients' => $recipients,
            'network'    => $network,
        ];

        if ($txnMsg) {
            $payload['txnMsg'] = $txnMsg;
        }

        $response = $this->client()->post('/payment', $payload);

        return $response->json();
    }

    public function checkStatus(string $purchaseId, string $network): array
    {
        $response = $this->client()->get("/status/{$purchaseId}", [
            'network' => $network,
        ]);

        return $response->json();
    }

    public function refund(string $campaignId, string $address, string $network): bool
    {
        $response = $this->client()->post('/refund', [
            'campaignId' => $campaignId,
            'address'    => $address,
            'network'    => $network,
        ]);

        return $response->successful() && ($response->json('success') ?? false);
    }

    public function getBalance(string $address, string $network): array
    {
        $response = $this->client()->get('/balance', [
            'address' => $address,
            'network' => $network,
        ]);

        return $response->json() ?? [];
    }
}

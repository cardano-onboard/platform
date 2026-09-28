<?php

namespace App\Services;

use App\Contracts\TransactionBackend;
use App\Models\Campaign;
use App\Support\MinUtxo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            'name' => $campaign->user_id.'-'.$campaign->name,
            'network' => $network,
        ]);

        return $response->json();
    }

    public function submitPayment(string $campaignId, array $recipients, string $network, ?string $txnMsg = null): array
    {
        $payload = [
            'campaignId' => $campaignId,
            'recipients' => $recipients,
            'network' => $network,
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
            'address' => $address,
            'network' => $network,
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

    /**
     * Asked of the proxy, which is the only thing this backend can see. Anything other
     * than a positive coefficient, including a failed request, falls back to the
     * configured default and says so, because a minimum computed from a zero would read
     * as free.
     */
    public function protocolParameters(string $network): array
    {
        $fallback = [
            'coins_per_utxo_byte' => MinUtxo::defaultCoinsPerUtxoByte(),
            'source' => 'default',
        ];

        try {
            $response = $this->client()->get('/protocol-parameters', ['network' => $network]);
        } catch (\Throwable $e) {
            Log::error('ProxyBackend protocol parameters error: '.$e->getMessage());

            return $fallback;
        }

        if (! $response->successful()) {
            return $fallback;
        }

        $coinsPerByte = (int) ($response->json('coins_per_utxo_byte') ?? 0);

        return $coinsPerByte > 0
            ? ['coins_per_utxo_byte' => $coinsPerByte, 'source' => 'proxy']
            : $fallback;
    }
}

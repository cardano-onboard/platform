<?php

namespace App\Services;

use App\Contracts\TransactionBackend;
use App\Models\Campaign;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PhyrhoseBackend implements TransactionBackend
{
    public function createBucket(Campaign $campaign, string $network): array
    {
        $phyrhose = $this->client($network);
        $networkId = $this->networkId($network);

        $response = $phyrhose->post('dripdropz/v1/pooCampaign', [
            'projectId' => $networkId,
            'name' => $campaign->user_id.'-'.$campaign->name,
        ]);

        $responseData = $response->json('data');

        if (($response->json('status') ?? null) !== 'ok' || ! isset($responseData[1])) {
            Log::error('Failed to create Phyrhose bucket.', [
                'campaign_id' => $campaign->id,
                'response' => $response->json(),
            ]);
            throw new \RuntimeException('Failed to create campaign bucket.');
        }

        return [
            'address' => $responseData[1]['bucketAddress'],
            'campaignId' => $responseData[1]['campaignId'],
        ];
    }

    public function submitPayment(string $campaignId, array $recipients, string $network, ?string $txnMsg = null): array
    {
        $phyrhose = $this->client($network);
        $networkId = $this->networkId($network);

        $payload = [
            'projectId' => $networkId,
            'campaignId' => $campaignId,
            'recipients' => $recipients,
        ];

        if ($txnMsg) {
            $payload['transactionMessage'] = substr($txnMsg, 0, 64);
        }

        Log::info('PhyrhoseBackend: POST pooPurchaseOrder', [
            'network' => $network,
            'campaign_id' => $campaignId,
            'project_id' => $networkId,
            'recipient_count' => count($recipients),
            'payload' => $payload,
        ]);

        $response = $phyrhose->post('dripdropz/v1/pooPurchaseOrder', $payload);
        $json = $response->json();

        Log::info('PhyrhoseBackend: pooPurchaseOrder response', [
            'campaign_id' => $campaignId,
            'http_status' => $response->status(),
            'response_status' => $json['status'] ?? null,
            'response' => $json,
        ]);

        if (($json['status'] ?? null) !== 'ok' || ! isset($json['data'][1]['items'])) {
            Log::error('Phyrhose purchase order failed.', [
                'campaign_id' => $campaignId,
                'http_status' => $response->status(),
                'response' => $json,
                'sent_payload' => $payload,
            ]);

            return ['purchaseIds' => []];
        }

        $items = $json['data'][1]['items'];
        $ids = [];
        foreach ($items as $item) {
            $ids[$item['pooCode']][$item['recipientAddress']] = $item['purchaseId'];
        }

        Log::info('PhyrhoseBackend: parsed purchase IDs', [
            'campaign_id' => $campaignId,
            'item_count' => count($items),
            'unique_codes' => count($ids),
        ]);

        return ['purchaseIds' => $ids];
    }

    public function checkStatus(string $purchaseId, string $network): array
    {
        $phyrhose = $this->client($network);

        Log::info('PhyrhoseBackend: GET purchaseStatus', [
            'network' => $network,
            'purchase_id' => $purchaseId,
            'resolved_base_url' => $network === 'mainnet'
                ? config('cardano.phyrhose.mainnet_url')
                : config('cardano.phyrhose.preprod_url'),
            'preprod_jwt_prefix' => substr((string) config('cardano.phyrhose.preprod_jwt'), 0, 20),
            'mainnet_jwt_prefix' => substr((string) config('cardano.phyrhose.mainnet_jwt'), 0, 20),
            'jwt_to_be_used_prefix' => $network === 'mainnet'
                ? substr((string) config('cardano.phyrhose.mainnet_jwt'), 0, 20)
                : substr((string) config('cardano.phyrhose.preprod_jwt'), 0, 20),
        ]);

        $response = $phyrhose->get("firehose/purchaseStatus?purchaseId={$purchaseId}");
        $json = $response->json();

        Log::info('PhyrhoseBackend: purchaseStatus response', [
            'purchase_id' => $purchaseId,
            'http_status' => $response->status(),
            'response_status' => $json['status'] ?? null,
            'response' => $json,
        ]);

        if (($json['status'] ?? null) !== 'ok' || ! isset($json['data'][1])) {
            Log::warning('PhyrhoseBackend: purchaseStatus malformed or not ok', [
                'purchase_id' => $purchaseId,
                'response' => $json,
            ]);

            return ['status' => 'unknown', 'txHash' => null];
        }

        $data = $json['data'][1];

        return [
            'status' => $data['status'] ?? 'unknown',
            'txHash' => $data['txId'] ?? null,
        ];
    }

    public function refund(string $campaignId, string $address, string $network): bool
    {
        $phyrhose = $this->client($network);
        $networkId = $this->networkId($network);
        $baseUrl = $network === 'mainnet'
            ? config('cardano.phyrhose.mainnet_url')
            : config('cardano.phyrhose.preprod_url');
        $path = 'dripdropz/v1/pooCampaignRefund';

        $payload = [
            'projectId' => $networkId,
            'campaignId' => $campaignId,
            'refundAddress' => $address,
        ];

        Log::info('PhyrhoseBackend: POST pooCampaignRefund', [
            'network' => $network,
            'campaign_id' => $campaignId,
            'project_id' => $networkId,
            'refund_address' => $address,
            'base_url' => $baseUrl,
            'path' => $path,
            'full_url' => rtrim($baseUrl, '/').'/'.$path,
            'payload' => $payload,
        ]);

        $response = $phyrhose->post($path, $payload);
        $json = $response->json();

        $success = $response->successful() && ($json['status'] ?? null) === 'ok';

        Log::info('PhyrhoseBackend: pooCampaignRefund response', [
            'campaign_id' => $campaignId,
            'http_status' => $response->status(),
            'response_status' => $json['status'] ?? null,
            'success' => $success,
            'response' => $json,
        ]);

        if (! $success) {
            Log::error('PhyrhoseBackend: refund failed', [
                'campaign_id' => $campaignId,
                'http_status' => $response->status(),
                'response' => $json,
                'sent_payload' => $payload,
            ]);
        }

        return $success;
    }

    public function getBalance(string $address, string $network): array
    {
        $phyrhose = $this->client($network);

        $response = $phyrhose->get('/firehose/queryLiveUtxos', [
            'address' => $address,
            'era' => 'ALONZO',
        ])->json();

        if (($response['status'] ?? null) === 'ok' && isset($response['data'][1]['liveUtxos'])) {
            return $response['data'][1]['liveUtxos'];
        }

        return [];
    }

    private function client(string $network)
    {
        return match ($network) {
            'mainnet' => Http::mainnet_phyrhose(),
            default => Http::preprod_phyrhose(),
        };
    }

    private function networkId(string $network): string
    {
        return match ($network) {
            'mainnet' => config('cardano.phyrhose.mainnet_id'),
            default => config('cardano.phyrhose.preprod_id'),
        };
    }
}

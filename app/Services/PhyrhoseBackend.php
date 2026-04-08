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
            'name'      => $campaign->user_id . '-' . $campaign->name,
        ]);

        $responseData = $response->json('data');

        if (($response->json('status') ?? null) !== 'ok' || !isset($responseData[1])) {
            Log::error('Failed to create Phyrhose bucket.', [
                'campaign_id' => $campaign->id,
                'response'    => $response->json(),
            ]);
            throw new \RuntimeException('Failed to create campaign bucket.');
        }

        return [
            'address'    => $responseData[1]['bucketAddress'],
            'campaignId' => $responseData[1]['campaignId'],
        ];
    }

    public function submitPayment(string $campaignId, array $recipients, string $network, ?string $txnMsg = null): array
    {
        $phyrhose = $this->client($network);
        $networkId = $this->networkId($network);

        $payload = [
            'projectId'  => $networkId,
            'campaignId' => $campaignId,
            'recipients' => $recipients,
        ];

        if ($txnMsg) {
            $payload['transactionMessage'] = substr($txnMsg, 0, 64);
        }

        $response = $phyrhose->post('dripdropz/v1/pooPurchaseOrder', $payload);
        $json = $response->json();

        if (($json['status'] ?? null) !== 'ok' || !isset($json['data'][1]['items'])) {
            Log::error('Phyrhose purchase order failed.', ['response' => $json]);
            return ['purchaseIds' => []];
        }

        $items = $json['data'][1]['items'];
        $ids = [];
        foreach ($items as $item) {
            $ids[$item['pooCode']][$item['recipientAddress']] = $item['purchaseId'];
        }

        return ['purchaseIds' => $ids];
    }

    public function checkStatus(string $purchaseId, string $network): array
    {
        $phyrhose = $this->client($network);

        $response = $phyrhose->get("firehose/purchaseStatus?purchaseId={$purchaseId}")->json();

        if (($response['status'] ?? null) !== 'ok' || !isset($response['data'][1])) {
            return ['status' => 'unknown', 'txHash' => null];
        }

        $data = $response['data'][1];

        return [
            'status' => $data['status'] ?? 'unknown',
            'txHash' => $data['txId'] ?? null,
        ];
    }

    public function refund(string $campaignId, string $address, string $network): bool
    {
        $phyrhose = $this->client($network);
        $networkId = $this->networkId($network);

        $response = $phyrhose->post('dripdropz/v1/pooCampaignRefund', [
            'projectId'     => $networkId,
            'campaignId'    => $campaignId,
            'refundAddress' => $address,
        ]);

        $json = $response->json();

        return $response->successful() && ($json['status'] ?? null) === 'ok';
    }

    public function getBalance(string $address, string $network): array
    {
        $phyrhose = $this->client($network);

        $response = $phyrhose->get('/firehose/queryLiveUtxos', [
            'address' => $address,
            'era'     => 'ALONZO',
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
            default   => Http::preprod_phyrhose(),
        };
    }

    private function networkId(string $network): string
    {
        return match ($network) {
            'mainnet' => config('cardano.phyrhose.mainnet_id'),
            default   => config('cardano.phyrhose.preprod_id'),
        };
    }
}

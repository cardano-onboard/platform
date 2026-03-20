<?php

namespace App\Services;

use App\Contracts\TransactionBackend;
use App\Models\Campaign;
use Illuminate\Support\Str;

class NullBackend implements TransactionBackend
{
    public function createBucket(Campaign $campaign, string $network): array
    {
        return [
            'address'    => 'addr_null1' . Str::random(40),
            'campaignId' => 'null-' . Str::ulid(),
        ];
    }

    public function submitPayment(string $campaignId, array $recipients, string $network, ?string $txnMsg = null): array
    {
        $ids = [];
        foreach ($recipients as $recipient) {
            $ids[$recipient['pooCode']][$recipient['address']] = 'null-purchase-' . Str::ulid();
        }

        return ['purchaseIds' => $ids];
    }

    public function checkStatus(string $purchaseId, string $network): array
    {
        return [
            'status' => 'completed',
            'txHash' => 'null-tx-' . Str::random(64),
        ];
    }

    public function refund(string $campaignId, string $address, string $network): bool
    {
        return true;
    }

    public function getBalance(string $address, string $network): array
    {
        return [];
    }
}

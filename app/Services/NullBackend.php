<?php

namespace App\Services;

use App\Contracts\TransactionBackend;
use App\Models\Campaign;
use App\Support\MinUtxo;
use Illuminate\Support\Str;

class NullBackend implements TransactionBackend
{
    public function createBucket(Campaign $campaign, string $network): array
    {
        return [
            'address' => 'addr_null1'.Str::random(40),
            'campaignId' => 'null-'.Str::ulid(),
        ];
    }

    public function submitPayment(string $campaignId, array $recipients, string $network, ?string $txnMsg = null): array
    {
        $ids = [];
        foreach ($recipients as $recipient) {
            $ids[$recipient['pooCode']][$recipient['address']] = 'null-purchase-'.Str::ulid();
        }

        return ['purchaseIds' => $ids];
    }

    public function checkStatus(string $purchaseId, string $network): array
    {
        return [
            'status' => 'completed',
            'txHash' => 'null-tx-'.Str::random(64),
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

    /**
     * The configured fallback, and honest about being one.
     *
     * This backend talks to no chain, so there is no epoch to read. Returning the default
     * keeps the minimum-UTxO calculation working on a deployment with no transaction
     * backend configured, which is how the test suite and a fresh self-hosted install both
     * run.
     */
    public function protocolParameters(string $network): array
    {
        return [
            'coins_per_utxo_byte' => MinUtxo::defaultCoinsPerUtxoByte(),
            'source' => 'default',
        ];
    }
}

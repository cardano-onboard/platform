<?php

namespace App\Contracts;

use App\Models\Campaign;

interface TransactionBackend
{
    /**
     * Create a new campaign bucket/wallet on the backend.
     *
     * @return array{address: string, campaignId: string}
     */
    public function createBucket(Campaign $campaign, string $network): array;

    /**
     * Submit a payment/purchase order.
     *
     * @param  array  $recipients  Array of recipient data with address, lovelace, tokens
     * @return array{purchaseIds: array}
     */
    public function submitPayment(string $campaignId, array $recipients, string $network, ?string $txnMsg = null): array;

    /**
     * Check the status of a purchase/transaction.
     *
     * @return array{status: string, txHash: ?string}
     */
    public function checkStatus(string $purchaseId, string $network): array;

    /**
     * Refund remaining bucket contents to an address.
     */
    public function refund(string $campaignId, string $address, string $network): bool;

    /**
     * Get the live UTxO balance for an address.
     */
    public function getBalance(string $address, string $network): array;
}

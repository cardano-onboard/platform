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

    /**
     * The protocol parameters this network is currently running.
     *
     * Only what the application actually uses is promised. `coins_per_utxo_byte` decides
     * the minimum ADA every output must carry and is a governance-settable parameter, so
     * reading it beats assuming it. `source` says where the figure came from, so a page
     * showing a minimum can say whether it is live or a fallback rather than presenting a
     * default as though it had been fetched.
     *
     * Implementations must not throw. A backend that cannot reach a chain returns the
     * configured fallback with a source saying so.
     *
     * @return array{coins_per_utxo_byte: int, source: string}
     */
    public function protocolParameters(string $network): array;
}

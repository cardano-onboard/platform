<?php

namespace App\Cardano;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * Handing a signed transaction to the chain.
 *
 * A duplicate is a success. Submitting a transaction the chain already has is what a retry
 * does, on purpose, because retrying is how a worker killed after submitting finishes its work.
 * Treating that as a failure would send the retry looking for another way to pay, and the only
 * other way is to build a second transaction.
 */
final class ChainSubmitter
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    /**
     * Offer a signed transaction to the chain.
     *
     * @param  string  $bytes  the signed CBOR, exactly as it will be hashed
     * @return string the transaction hash the chain acknowledged
     */
    public function submit(string $network, string $bytes, string $expectedHash): string
    {
        $url = $this->endpoint($network);

        $request = $this->requests->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/cbor')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream($bytes));

        if ($token = (string) config('cardano.koios.token')) {
            $request = $request->withHeader('Authorization', 'Bearer '.$token);
        }

        $response = $this->http->sendRequest($request);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status >= 200 && $status < 300) {
            // The provider answers with the hash, quoted. It is checked rather than trusted,
            // because everything downstream finds this transaction again by hash, and a hash that
            // is not of these bytes finds nothing.
            $acknowledged = trim($body, "\" \n\r\t");

            if ($acknowledged !== '' && $acknowledged !== $expectedHash) {
                throw new RuntimeException(sprintf(
                    'The provider acknowledged %s for a transaction that hashes to %s. Recording either would '
                    .'leave a payment nothing can find again.',
                    $acknowledged,
                    $expectedHash,
                ));
            }

            return $expectedHash;
        }

        if ($this->saysAlreadyAccepted($body)) {
            // Already there, which is what a retry expects to find. The alternative reading, that
            // this failed, sends the caller looking for another way to pay, and the only other way
            // is a second transaction.
            return $expectedHash;
        }

        if ($this->saysInputsAreGone($body)) {
            // The inputs this spends no longer exist. That has two causes and they need opposite
            // responses: this transaction already succeeded and consumed them, or something else
            // did. Guessing the first pays nobody twice but silently loses a failure; guessing the
            // second rebuilds and can pay twice.
            //
            // So neither is guessed. This is raised as its own failure, and resolving it means
            // asking the chain whether the transaction is there, which the status check does.
            throw new ChainInputsConsumed(sprintf(
                'The inputs for %s are already spent. Either this transaction was accepted and this is a '
                .'repeat of it, or something else spent them. Which one it is decides whether anything may '
                .'be rebuilt, so it is read from the chain rather than assumed here.',
                $expectedHash,
            ));
        }

        if ($status < 500) {
            // A refusal with a reason: the node read the transaction and said no, or the provider
            // refused the request before it reached a node. Either way this transaction was not
            // accepted by this submission.
            throw new ChainRejected(sprintf(
                'The chain refused this transaction with status %d: %s',
                $status,
                mb_substr($body, 0, 400),
            ));
        }

        // A server error says nothing about whether the node saw the transaction. It may have
        // been forwarded and accepted, so this is not a refusal and nothing may treat it as one.
        throw new RuntimeException(sprintf(
            'Submission answered with status %d, which does not say whether the transaction reached the chain: %s',
            $status,
            mb_substr($body, 0, 400),
        ));
    }

    private function endpoint(string $network): string
    {
        $base = rtrim((string) config('cardano.koios.'.$network.'_url'), '/');

        if ($base === '') {
            throw new RuntimeException(sprintf(
                'KOIOS_%s_URL is not set, so nothing here can send a transaction on %s.',
                strtoupper($network),
                $network,
            ));
        }

        return $base.'/submittx';
    }

    /**
     * Whether a refusal means the chain already has this transaction.
     *
     * Matched on what the message says rather than on a status code, because codes differ between
     * providers and node versions while the wording of this refusal is stable. Only phrasings that
     * mean the transaction itself is present count. Anything ambiguous is deliberately absent: a
     * reading that is too generous turns a retry into a second payment.
     */
    private function saysAlreadyAccepted(string $body): bool
    {
        $normalised = mb_strtolower($body);

        foreach (['already in the mempool', 'transaction already exists', 'duplicate transaction'] as $marker) {
            if (str_contains($normalised, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the refusal is that the inputs are gone.
     *
     * Its own question because it has two opposite causes and the answer is not in this message.
     */
    private function saysInputsAreGone(string $body): bool
    {
        return str_contains(mb_strtolower($body), 'badinputsutxo');
    }
}

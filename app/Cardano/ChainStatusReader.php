<?php

namespace App\Cardano;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use RuntimeException;

/**
 * Asking the chain how deeply buried a transaction is.
 *
 * Its own class so that the thing which talks to a provider and the thing which decides what a
 * depth means are separate. Reconciliation can then be exercised against every answer the chain can
 * give without a network, which matters because the answers that are hard to get right are the ones
 * a real chain rarely produces on demand: a transaction it has never seen, one sitting at zero
 * confirmations, and one deep but past its validity.
 */
class ChainStatusReader
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    /**
     * How deep each of these is, for those the chain has.
     *
     * A transaction the chain has never seen is absent rather than zero. Zero is a real answer,
     * meaning accepted and in the newest block, so reading absence as zero would make an unseen
     * transaction look like one that had just landed.
     *
     * @param  list<string>  $hashes
     * @return array<string, int>
     */
    public function depths(string $network, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }

        $base = rtrim((string) config('cardano.koios.'.$network.'_url'), '/');

        if ($base === '') {
            throw new RuntimeException(sprintf('KOIOS_%s_URL is not set, so there is no chain data for %s.', strtoupper($network), $network));
        }

        // A request body over the provider's limit is refused outright, so the hashes go in
        // requests of at most as many as fit under it. Each is 66 bytes of JSON plus a comma.
        $perRequest = max(1, intdiv(max(256, (int) config('cardano.koios.max_body_bytes', 5120)) - 32, 67));
        $depths = [];

        foreach (array_chunk(array_values($hashes), $perRequest) as $chunk) {
            $request = $this->requests->createRequest('POST', $base.'/tx_status')
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', 'application/json')
                ->withBody($this->streams->createStream(
                    json_encode(['_tx_hashes' => $chunk], JSON_THROW_ON_ERROR)
                ));

            if ($token = (string) config('cardano.koios.token')) {
                $request = $request->withHeader('Authorization', 'Bearer '.$token);
            }

            $response = $this->http->sendRequest($request);

            if ($response->getStatusCode() >= 400) {
                throw new RuntimeException(sprintf(
                    '%s answered a status request with %d, so nothing here knows what happened to these '
                    .'transactions. Raised rather than read as unconfirmed, which would expire them.',
                    $network,
                    $response->getStatusCode(),
                ));
            }

            $rows = json_decode((string) $response->getBody(), true);

            if (! is_array($rows)) {
                throw new RuntimeException(sprintf('%s answered a status request with something that is not rows.', $network));
            }

            foreach ($rows as $row) {
                if (is_array($row) && isset($row['tx_hash'], $row['num_confirmations']) && is_numeric($row['num_confirmations'])) {
                    $depths[$row['tx_hash']] = (int) $row['num_confirmations'];
                }
            }
        }

        return $depths;
    }
}

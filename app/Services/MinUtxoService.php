<?php

namespace App\Services;

use App\Contracts\TransactionBackend;
use App\Models\Code;
use App\Support\MinUtxo;

/**
 * What a reward bundle has to be worth, answered once for everybody who asks.
 *
 * App\Support\MinUtxo holds the arithmetic and knows nothing about a chain. This joins it
 * to the live protocol parameter and to the shapes the rest of the application actually
 * has: a list of tokens posted from a form, or a code already in the database. Creating a
 * code, editing one and the campaign's funding figures all come through here, so they
 * cannot drift apart.
 *
 * A reward is stored at the higher of what the operator entered and the exact minimum for
 * its assets, so nothing this application creates or edits is ever below the minimum.
 * The one state still reported is below_minimum, for a code stored before that rule: the
 * chain will not accept its output at all, and the operator has to change the code.
 */
class MinUtxoService
{
    /** Coefficients already looked up this request, keyed by network. */
    private array $parameters = [];

    public function __construct(private TransactionBackend $backend) {}

    /**
     * The whole answer for one bundle, and for a proposed amount when there is one.
     *
     * `min_lovelace` is the exact ledger minimum for the output under the network's
     * current coins-per-byte: the smallest amount the chain accepts, so one lovelace less
     * is refused and one more is a lovelace the recipient was not owed. Pass the
     * recipient's address to size the output for it; without one the configured address
     * length stands in.
     *
     * @param  array<int, array{policy?: ?string, asset?: ?string, quantity?: int|string|null}>  $assets
     * @return array{
     *     min_lovelace: int,
     *     coins_per_utxo_byte: int, source: string, asset_count: int, policy_count: int,
     *     output_bytes: int, state: ?string, warning: ?string
     * }
     */
    public function quote(array $assets, string $network, ?int $lovelace = null, ?string $address = null): array
    {
        $parameters = $this->parametersFor($network);
        $coinsPerByte = $parameters['coins_per_utxo_byte'];

        // The recipient's own address when it is known, and the configured stand-in when
        // it is not, which is every time a code is priced before anyone has claimed it.
        $addressBytes = $address === null ? null : MinUtxo::addressBytesFor($address);

        $min = MinUtxo::forBundle($assets, $coinsPerByte, $addressBytes);

        $quote = [
            'min_lovelace' => $min,
            'coins_per_utxo_byte' => $coinsPerByte,
            'source' => $parameters['source'],
            'asset_count' => $this->assetCount($assets),
            'policy_count' => MinUtxo::policyCount($assets),
            'output_bytes' => MinUtxo::outputBytes($assets, $addressBytes, $min),
            'state' => null,
            'warning' => null,
        ];

        if ($lovelace === null) {
            return $quote;
        }

        $quote['state'] = $lovelace < $min ? 'below_minimum' : 'ok';
        $quote['warning'] = $this->warning($quote['state'], $lovelace, $quote);

        return $quote;
    }

    /** The same answer for a code that already exists, judged against what it pays. */
    public function forCode(Code $code): array
    {
        return $this->quote(
            self::assetsFromCode($code),
            $code->campaign?->network ?? 'mainnet',
            (int) $code->lovelace,
        );
    }

    /**
     * What a code is stored at: the higher of the entered amount and the exact minimum for
     * its assets, so a reward is never below what the chain accepts and never above what
     * the operator asked for unless the assets demand it.
     *
     * Shared by every path that accepts a reward for a code (the campaign page's create and
     * edit forms, the bulk import and the code-creation API) so the rule cannot drift
     * between them. Sized with the configured address length, the stand-in for a code
     * nobody has claimed yet.
     *
     * @param  array<int, array{policy?: ?string, asset?: ?string, quantity?: int|string|null}>  $assets
     */
    public function effectiveLovelace(array $assets, string $network, int $lovelace): int
    {
        if ($assets === []) {
            return $lovelace;
        }

        return max($lovelace, MinUtxo::forBundle($assets, $this->coinsPerUtxoByte($network)));
    }

    /**
     * The minimum alone, for a bundle and a coefficient already in hand.
     *
     * Used where a figure is wanted for many codes at once and re-reading the protocol
     * parameter for each of them would be a query per row.
     */
    public function coinsPerUtxoByte(string $network): int
    {
        return $this->parametersFor($network)['coins_per_utxo_byte'];
    }

    /**
     * A code's rewards in the shape the arithmetic wants.
     *
     * @return array<int, array{policy: ?string, asset: ?string, quantity: int}>
     */
    public static function assetsFromCode(Code $code): array
    {
        $rewards = $code->relationLoaded('rewards') ? $code->rewards : $code->rewards()->get();

        return $rewards->map(static fn ($reward) => [
            'policy' => $reward->policy_hex,
            'asset' => $reward->asset_hex,
            'quantity' => (int) $reward->quantity,
        ])->all();
    }

    /**
     * Token rows as the create and edit forms post them, in the shape the arithmetic
     * wants. The two forms send the same field names, which is why there is one of these.
     *
     * @param  array<int, array<string, mixed>>  $tokens
     * @return array<int, array{policy: ?string, asset: ?string, quantity: int}>
     */
    public static function assetsFromRequest(array $tokens): array
    {
        return array_values(array_map(static fn ($token) => [
            'policy' => is_string($token['policy_id'] ?? null) ? $token['policy_id'] : null,
            'asset' => is_string($token['token_id'] ?? null) ? $token['token_id'] : null,
            'quantity' => (int) ($token['quantity'] ?? 0),
        ], $tokens));
    }

    private function parametersFor(string $network): array
    {
        if (! isset($this->parameters[$network])) {
            $parameters = $this->backend->protocolParameters($network);
            $coinsPerByte = (int) ($parameters['coins_per_utxo_byte'] ?? 0);

            // A backend that answers with nothing usable is treated as a backend that
            // could not answer. Multiplying by a zero would report every bundle as free.
            $this->parameters[$network] = $coinsPerByte > 0
                ? [
                    'coins_per_utxo_byte' => $coinsPerByte,
                    'source' => (string) ($parameters['source'] ?? 'default'),
                ]
                : [
                    'coins_per_utxo_byte' => MinUtxo::defaultCoinsPerUtxoByte(),
                    'source' => 'default',
                ];
        }

        return $this->parameters[$network];
    }

    private function warning(string $state, int $lovelace, array $quote): ?string
    {
        if ($state !== 'below_minimum') {
            return null;
        }

        $assets = $quote['asset_count'] === 1 ? '1 asset' : $quote['asset_count'].' assets';
        $min = self::ada($quote['min_lovelace']);
        $paid = self::ada($lovelace);

        return "This code pays {$paid} ADA. An output carrying {$assets} has to hold at least "
            ."{$min} ADA, so the chain will reject the payment and nobody will be paid.";
    }

    private function assetCount(array $assets): int
    {
        return count(array_filter(
            $assets,
            static fn ($asset) => is_string($asset['policy'] ?? null) && trim($asset['policy']) !== '',
        ));
    }

    /** Lovelace as ADA, without a trailing run of zeroes nobody reads. */
    private static function ada(int $lovelace): string
    {
        return rtrim(rtrim(number_format($lovelace / 1_000_000, 6, '.', ''), '0'), '.') ?: '0';
    }
}

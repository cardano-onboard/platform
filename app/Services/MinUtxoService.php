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
 * Three states, and the difference between the last two is the whole point:
 *
 *   below_minimum  the chain will not accept this output at all;
 *   tight          it will be accepted, and the recipient will not be able to spend it
 *                  without ADA they do not have;
 *   ok             there is room to move.
 *
 * Only the first is a refusal. A tight reward is a decision an operator is allowed to
 * make, so it is reported and never corrected on their behalf.
 */
class MinUtxoService
{
    /** Coefficients already looked up this request, keyed by network. */
    private array $parameters = [];

    public function __construct(private TransactionBackend $backend) {}

    /**
     * The whole answer for one bundle, and for a proposed amount when there is one.
     *
     * @param  array<int, array{policy?: ?string, asset?: ?string, quantity?: int|string|null}>  $assets
     * @return array{
     *     min_lovelace: int, headroom_lovelace: int, recommended_lovelace: int,
     *     coins_per_utxo_byte: int, source: string, asset_count: int, policy_count: int,
     *     output_bytes: int, state: ?string, warning: ?string
     * }
     */
    public function quote(array $assets, string $network, ?int $lovelace = null): array
    {
        $parameters = $this->parametersFor($network);
        $coinsPerByte = $parameters['coins_per_utxo_byte'];

        $min = MinUtxo::forBundle($assets, $coinsPerByte);
        $headroom = MinUtxo::headroom();

        $quote = [
            'min_lovelace' => $min,
            'headroom_lovelace' => $headroom,
            'recommended_lovelace' => $min + $headroom,
            'coins_per_utxo_byte' => $coinsPerByte,
            'source' => $parameters['source'],
            'asset_count' => $this->assetCount($assets),
            'policy_count' => MinUtxo::policyCount($assets),
            'output_bytes' => MinUtxo::outputBytes($assets),
            'state' => null,
            'warning' => null,
        ];

        if ($lovelace === null) {
            return $quote;
        }

        $quote['state'] = $this->state($lovelace, $min, $headroom);
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
     * A validation rule that refuses a reward the chain itself would refuse.
     *
     * The floor is not a policy of ours. An output has to hold enough ADA to pay for the
     * bytes it occupies, and one carrying a native token needs more than the flat 1 ADA
     * this form used to accept, so a code set below it was a payment that could be
     * created, printed on a sticker, scanned at a booth and never sent.
     *
     * Only the chain's own floor is enforced here. A reward that clears it but leaves the
     * recipient nothing to pay a fee with is warned about after the fact, not refused: it
     * is a legitimate choice, and an operator handing out a fixed amount is entitled to
     * make it.
     *
     * Shared by every path that accepts a reward for a code — the campaign page's create
     * and edit forms, and the code-creation API — so the floor cannot drift between them.
     */
    public function minimumRule(array $bundle, string $network): callable
    {
        return function (string $attribute, $value, callable $fail) use ($bundle, $network) {
            if ($bundle === []) {
                return;
            }

            $quote = $this->quote($bundle, $network, (int) $value);

            if ($quote['state'] === 'below_minimum') {
                $fail($quote['warning']);
            }
        };
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

    private function state(int $lovelace, int $min, int $headroom): string
    {
        if ($lovelace < $min) {
            return 'below_minimum';
        }

        return $lovelace < $min + $headroom ? 'tight' : 'ok';
    }

    private function warning(string $state, int $lovelace, array $quote): ?string
    {
        if ($state === 'ok') {
            return null;
        }

        $assets = $quote['asset_count'] === 1 ? '1 asset' : $quote['asset_count'].' assets';
        $min = self::ada($quote['min_lovelace']);
        $paid = self::ada($lovelace);

        if ($state === 'below_minimum') {
            return "This code pays {$paid} ADA. An output carrying {$assets} has to hold at least "
                ."{$min} ADA, so the chain will reject the payment and nobody will be paid.";
        }

        $spare = self::ada($lovelace - $quote['min_lovelace']);
        $recommended = self::ada($quote['recommended_lovelace']);

        return "This code pays {$paid} ADA and the minimum for {$assets} is {$min} ADA, which leaves "
            ."{$spare} ADA of room. A claimant whose first wallet is the one they opened at your booth has "
            .'nothing else to pay a fee with, so that is as far as their tokens will travel. Set it to '
            ."{$recommended} ADA or more to give them room to move.";
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

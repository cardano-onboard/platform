<?php

namespace App\Rules;

use CardanoPhp\Bech32\Bech32;
use Closure;
use Exception;
use Illuminate\Contracts\Validation\ValidationRule;

class CardanoAddress implements ValidationRule
{
    public function __construct(private ?string $expectedNetwork = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !preg_match('/^addr(_test)?1[023456789acdefghjklmnpqrstuvwxyz]{53,103}$/', $value)) {
            $fail('The :attribute is not a valid Cardano address.');
            return;
        }

        try {
            $decoded = Bech32::decodeCardanoAddress($value);
        } catch (Exception) {
            $fail('The :attribute could not be decoded as a Cardano address.');
            return;
        }

        if ($this->expectedNetwork) {
            $isMainnet = $decoded['networkId'] == 1;

            if ($this->expectedNetwork === 'mainnet' && !$isMainnet) {
                $fail('The :attribute must be a mainnet Cardano address.');
                return;
            }

            if (in_array($this->expectedNetwork, ['preprod', 'preview']) && $isMainnet) {
                $fail('The :attribute must be a testnet Cardano address.');
            }
        }
    }
}

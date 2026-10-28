<?php

namespace App\Cardano;

use Cardano\Transaction\Address\Credential;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Script\NativeScript;
use Cardano\Transaction\Signing\SigningKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The key platform fees are paid to, and the address it makes.
 *
 * One key for the whole deployment. Every campaign's fees output sits at the one address this
 * script makes, and every payout that collects a fee is signed by this key as well as the
 * campaign's own. The script is
 *
 *     any [ sig feeKey, sig owner1, sig owner2 ]
 *
 * as an enterprise address: the fee key spends it in the ordinary course, and either owner can
 * spend it without the platform, the same break-glass the owners hold on every campaign script.
 * It has no expiry, because fees accumulate here for as long as the platform runs.
 *
 * Generated once and never replaced. A replaced key would strand every fees output recorded
 * against the old one, so there is no way to do it from here. The owner hashes the script was made
 * with are stored beside the key, so changing the environment afterwards cannot move the address.
 */
final class PlatformFeeKey
{
    private const TABLE = 'platform_fee_keys';

    public function __construct(private readonly CampaignKeyVault $vault) {}

    public function exists(): bool
    {
        return DB::table(self::TABLE)->exists();
    }

    /**
     * Make the key and store it wrapped, once.
     *
     * @param  list<string>  $owners  the two owner key hashes the script is made with
     * @return array{script_hash: string, owner_key_hashes: list<string>}
     *
     * @throws RuntimeException where a key exists already, or the owners or wrapping key are unusable
     */
    public function generate(array $owners): array
    {
        if ($this->exists()) {
            throw new RuntimeException(
                'A platform fee key already exists. It is never replaced, because every fees output recorded '
                .'against it would be stranded at an address nothing could sign for.'
            );
        }

        if (! $this->vault->isConfigured()) {
            throw new RuntimeException(
                'No campaign wrapping key is configured, so the fee key could not be stored readably.'
            );
        }

        $owners = array_values($owners);
        $this->assertOwners($owners);

        $seed = random_bytes(32);
        $key = SigningKey::fromSeed($seed);

        try {
            $script = self::scriptFor($key->credential()->hex(), $owners);
            $wrapped = $this->vault->wrap($seed);

            try {
                DB::table(self::TABLE)->insert([
                    'name' => 'platform',
                    'skey' => $wrapped['ciphertext'],
                    'vkey' => $key->publicKeyHex(),
                    'wrapping_key_version' => $wrapped['version'],
                    'owner_key_hashes' => json_encode($owners, JSON_THROW_ON_ERROR),
                    'script_hash' => $script->hashHex(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new RuntimeException('A platform fee key was made by another process first, and it is never replaced.');
            }

            return ['script_hash' => $script->hashHex(), 'owner_key_hashes' => $owners];
        } finally {
            sodium_memzero($seed);
            $key->discard();
        }
    }

    /**
     * The script, rebuilt from what was stored with the key and nothing from configuration.
     */
    public function script(): NativeScript
    {
        $row = $this->row();

        $script = self::scriptFor(
            Credential::fromVerificationKey(hex2bin((string) $row->vkey))->hex(),
            json_decode((string) $row->owner_key_hashes, true, 512, JSON_THROW_ON_ERROR),
        );

        // The address is the hash of this script, so a script that no longer hashes to what was
        // stored is an address that has moved, and nothing here may sign for it.
        if ($script->hashHex() !== $row->script_hash) {
            throw new RuntimeException('The platform fee script no longer hashes to the one stored with the key, so the fee address would not be the one fees were paid to.');
        }

        return $script;
    }

    /**
     * Whether the stored key can be opened and is the key it says it is.
     *
     * The key is wrapped under the version of the wrapping key current when it was made, for as
     * long as it exists, so a wrapping key removed after a rotation leaves it unreadable while
     * everything else about the deployment looks well.
     */
    public function canSign(): bool
    {
        try {
            $vkey = (string) $this->row()->vkey;

            return $this->signing(static fn (SigningKey $key): bool => $key->publicKeyHex() === $vkey);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The fee address on a network: an enterprise script address, because nothing here stakes.
     */
    public function address(string $network): string
    {
        return $this->script()->enterpriseAddress($network === 'mainnet' ? Network::Mainnet : Network::Testnet)->toBech32();
    }

    /**
     * Run something with the fee key, which exists for that long and no longer.
     *
     * @param  callable(SigningKey): mixed  $use
     */
    public function signing(callable $use): mixed
    {
        $row = $this->row();
        $seed = $this->vault->unwrap((string) $row->skey, (int) $row->wrapping_key_version);

        try {
            $key = SigningKey::fromSeed($seed);

            return $use($key);
        } finally {
            sodium_memzero($seed);

            if (isset($key)) {
                $key->discard();
            }
        }
    }

    /**
     * @param  list<string>  $owners
     */
    private static function scriptFor(string $keyHash, array $owners): NativeScript
    {
        return NativeScript::any(
            NativeScript::sig($keyHash),
            ...array_map(static fn (string $hash): NativeScript => NativeScript::sig($hash), $owners),
        );
    }

    /**
     * The owner hashes in configuration, when they are usable as the two break-glass signers.
     *
     * @return list<string>
     */
    public static function configuredOwners(): array
    {
        return array_values(array_filter((array) config('campaign_script.owner_key_hashes', [])));
    }

    /**
     * @param  list<string>  $owners
     */
    private function assertOwners(array $owners): void
    {
        if (count($owners) !== 2 || count(array_unique($owners)) !== 2) {
            throw new RuntimeException(
                'The fee address needs the two distinct owner key hashes, CAMPAIGN_OWNER_KEY_HASH_1 and '
                .'CAMPAIGN_OWNER_KEY_HASH_2, and they are inside the address, so they must be right before it is made.'
            );
        }

        foreach ($owners as $hash) {
            if (preg_match('/^[0-9a-f]{56}$/', (string) $hash) !== 1) {
                throw new RuntimeException(
                    'An owner key hash is 56 lowercase hex characters, being blake2b-224 of a verification key.'
                );
            }
        }
    }

    private function row(): object
    {
        return DB::table(self::TABLE)->first()
            ?? throw new RuntimeException('No platform fee key exists. Generate it before a fee can be collected.');
    }
}

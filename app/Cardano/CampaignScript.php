<?php

namespace App\Cardano;

use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\BaseAddress;
use Cardano\Transaction\Address\Credential;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Address\RewardAddress;
use Cardano\Transaction\Script\NativeScript;

/**
 * The native script that guards a campaign's funds, and the address it makes.
 *
 * A campaign's address is the hash of this script and nothing else, so everything the script
 * is built from is fixed for that campaign's whole life. There is no migration from one
 * address to another once funds have arrived, which is why the shape below is assembled from
 * stored values rather than recomputed from current configuration: a campaign created under
 * one set of owner hashes keeps answering to those hashes after the configuration changes.
 *
 * Two shapes, one per funding path, and the difference between them is who holds the
 * unbounded right to spend. That is whoever put the money in.
 *
 * Customer-funded, where the customer's own wallet paid:
 *
 *     any [ sig customer,
 *           all [ any [ sig campaign, sig owner1, sig owner2 ],
 *                 before: expiry ] ]
 *
 * The customer can always take their money back, with no deadline, because it is theirs. The
 * platform and either owner can spend it to run the campaign, but only until the expiry slot,
 * after which that right lapses and the customer's is all that remains.
 *
 * Operator-funded, where the funds are held by the deployment's operator rather than the campaign's creator:
 *
 *     any [ sig owner1, sig owner2,
 *           all [ sig campaign, before: expiry ] ]
 *
 * Here the unbounded signers are the owners, for the same reason inverted: the money belongs to
 * the operator. The per-campaign key can spend it until expiry, which is the window the campaign
 * actually runs in.
 *
 * Both owner hashes are in every script, so a campaign can be recovered without the platform
 * existing: in the operator-funded shape they sit on the unbounded side and can spend at any time,
 * and in the customer-funded shape they sit beside the campaign key inside the timed branch, so
 * after the expiry only the customer can. It is why the hashes have to be right before the first
 * address is derived rather than before the first campaign is funded: a hash cannot be added to
 * an address afterwards.
 */
final class CampaignScript
{
    public const FUNDING_CUSTOMER = 'customer';

    public const FUNDING_CREDIT = 'credit';

    /**
     * @param  string  $campaignKeyHash  blake2b-224 of the per-campaign verification key, hex
     * @param  list<string>  $ownerKeyHashes  the two successor key hashes, hex
     * @param  ?string  $customerKeyHash  the funding wallet's payment credential, customer-funded only
     */
    private function __construct(
        public readonly string $funding,
        public readonly string $campaignKeyHash,
        public readonly array $ownerKeyHashes,
        public readonly int $expirySlot,
        public readonly ?string $customerKeyHash,
    ) {}

    /**
     * @param  list<string>  $ownerKeyHashes
     */
    public static function customerFunded(
        string $campaignKeyHash,
        array $ownerKeyHashes,
        int $expirySlot,
        string $customerKeyHash,
    ): self {
        self::assertHashes($ownerKeyHashes, $campaignKeyHash);
        self::assertHash($customerKeyHash, 'customer key hash');

        return new self(self::FUNDING_CUSTOMER, $campaignKeyHash, array_values($ownerKeyHashes), $expirySlot, $customerKeyHash);
    }

    /**
     * @param  list<string>  $ownerKeyHashes
     */
    public static function creditFunded(
        string $campaignKeyHash,
        array $ownerKeyHashes,
        int $expirySlot,
    ): self {
        self::assertHashes($ownerKeyHashes, $campaignKeyHash);

        return new self(self::FUNDING_CREDIT, $campaignKeyHash, array_values($ownerKeyHashes), $expirySlot, null);
    }

    /**
     * Rebuild from a stored recovery record, with nothing derived from configuration.
     *
     * This is the path that has to keep working when there is no application: everything the
     * script needs is in the record, so a recovery tool can reach the same address from the
     * record alone. It is also what the record's own test exercises, because a record that
     * cannot rebuild its script is not a recovery record.
     *
     * @param  array<string, mixed>  $record
     */
    public static function fromRecord(array $record): self
    {
        foreach (['funding', 'campaign_key_hash', 'owner_key_hashes', 'expiry_slot'] as $field) {
            if (! array_key_exists($field, $record)) {
                throw new \InvalidArgumentException("The recovery record is missing {$field}.");
            }
        }

        $owners = is_array($record['owner_key_hashes'])
            ? $record['owner_key_hashes']
            : json_decode((string) $record['owner_key_hashes'], true, 512, JSON_THROW_ON_ERROR);

        return $record['funding'] === self::FUNDING_CUSTOMER
            ? self::customerFunded(
                $record['campaign_key_hash'],
                $owners,
                (int) $record['expiry_slot'],
                $record['customer_key_hash'] ?? throw new \InvalidArgumentException(
                    'A customer-funded record carries no customer key hash, so its script cannot be rebuilt.'
                ),
            )
            : self::creditFunded($record['campaign_key_hash'], $owners, (int) $record['expiry_slot']);
    }

    public function script(): NativeScript
    {
        $owners = array_map(
            static fn (string $hash): NativeScript => NativeScript::sig($hash),
            $this->ownerKeyHashes,
        );

        if ($this->funding === self::FUNDING_CUSTOMER) {
            return NativeScript::any(
                NativeScript::sig($this->customerKeyHash),
                NativeScript::all(
                    NativeScript::any(NativeScript::sig($this->campaignKeyHash), ...$owners),
                    NativeScript::before($this->expirySlot),
                ),
            );
        }

        return NativeScript::any(
            ...$owners,
            ...[NativeScript::all(
                NativeScript::sig($this->campaignKeyHash),
                NativeScript::before($this->expirySlot),
            )],
        );
    }

    public function scriptHashHex(): string
    {
        return $this->script()->hashHex();
    }

    /**
     * The campaign's address: a base address whose payment part is this script and whose
     * delegation part is the stake credential it is given.
     *
     * CIP-19, "Shelley Addresses": the header's high four bits are the address type, and type 1
     * (`0001`) is a script hash payment part with a key hash delegation part, type 3 (`0011`) the
     * same with a script hash delegation part. The package picks between them from the kinds of
     * the two credentials, so the stake credential's kind travels with it from the reward address
     * it was read out of. CIP-19, "Network Tag": the low four bits are 0 for every test network
     * and 1 for mainnet. CIP-19, "User-facing Encoding", with the prefixes from CIP-5: `addr` on
     * mainnet and `addr_test` on a test network.
     *
     * Never an enterprise address (types 6 and 7). CIP-19, "Delegation part": whoever owns the
     * delegation part owns the stake rights of the funds, and spending is governed by the payment
     * part alone. So the stake credential earns the rewards and confers nothing over the money,
     * and leaving it out would forfeit staking on every lovelace a campaign holds.
     *
     * The stake credential is an input rather than something looked up here, because who it
     * belongs to depends on who funded the campaign and is decided by the caller: the platform's
     * own for an operator-funded campaign, the connecting wallet's for a customer-funded one. It is
     * inside the address, so it is as frozen as the script.
     */
    public function address(Network $network, Credential $stake): string
    {
        return $this->baseAddress($network, $stake)->toBech32();
    }

    public function baseAddress(Network $network, Credential $stake): BaseAddress
    {
        return $this->script()->baseAddress($network, $stake);
    }

    /**
     * The stake credential inside a bech32 reward address, refusing anything that is not one.
     *
     * CIP-19, "Stake Addresses": a reward address is a header of type 14 (key hash) or 15
     * (script hash) and twenty-eight bytes of credential, written with the CIP-5 prefix `stake`
     * on mainnet and `stake_test` on a test network. The package decodes the bech32 to bytes and
     * checks the prefix against the header, so a payment address or a mislabelled one cannot be
     * read as a stake credential, and the network tag is checked here against the campaign's.
     */
    public static function stakeCredential(string $rewardAddress, Network $network): Credential
    {
        try {
            $address = Address::fromBech32(trim($rewardAddress));
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Not a bech32 stake address: '.$e->getMessage(), 0, $e);
        }

        if (! $address instanceof RewardAddress) {
            throw new \InvalidArgumentException(
                'This is a payment address, not a stake address. A campaign address carries a stake credential, '
                .'which is written as a stake address beginning stake1 or stake_test1.'
            );
        }

        if ($address->network !== $network) {
            throw new \InvalidArgumentException(sprintf(
                'This stake address is for %s and the campaign is on %s.',
                $address->network->isMainnet() ? 'mainnet' : 'a test network',
                $network->isMainnet() ? 'mainnet' : 'a test network',
            ));
        }

        return $address->stake;
    }

    /**
     * The record a recovery needs, and nothing else.
     *
     * Everything here is what the script is made of, so it survives the application and the
     * configuration it was created under. The script JSON is included even though it is
     * derivable from the rest, because a recovery reading this file should not have to trust
     * that somebody else's implementation of the shape agrees with ours.
     *
     * @return array<string, mixed>
     */
    public function recoveryRecord(Network $network, Credential $stake, int $wrappingKeyVersion): array
    {
        return [
            'funding' => $this->funding,
            'network' => $network->isMainnet() ? 'mainnet' : 'testnet',
            'campaign_key_hash' => $this->campaignKeyHash,
            'owner_key_hashes' => $this->ownerKeyHashes,
            'customer_key_hash' => $this->customerKeyHash,
            'expiry_slot' => $this->expirySlot,
            'script' => $this->script()->toArray(),
            'script_hash' => $this->scriptHashHex(),
            // The stake part as the reward address it was read from, so a recovery rebuilding the
            // address has the credential and its kind without trusting anything else here.
            'stake_address' => RewardAddress::of($network, $stake)->toBech32(),
            'address' => $this->address($network, $stake),
            'wrapping_key_version' => $wrappingKeyVersion,
        ];
    }

    /**
     * @param  list<string>  $owners
     */
    private static function assertHashes(array $owners, string $campaignKeyHash): void
    {
        self::assertHash($campaignKeyHash, 'campaign key hash');

        if (count($owners) !== 2) {
            throw new \InvalidArgumentException(sprintf(
                'A campaign script carries exactly two owner key hashes, got %d. Either can recover the '
                .'campaign without the platform, and a hash cannot be added to an address once it exists.',
                count($owners),
            ));
        }

        if (count(array_unique($owners)) !== 2) {
            throw new \InvalidArgumentException(
                'The two owner key hashes are the same, so the campaign has one recovery holder rather than two.'
            );
        }

        foreach ($owners as $hash) {
            self::assertHash($hash, 'owner key hash');
        }

        if (in_array($campaignKeyHash, $owners, true)) {
            throw new \InvalidArgumentException(
                'The campaign key hash is also an owner key hash, which collapses the timed branch into the '
                .'unbounded one and leaves the campaign spendable after its expiry.'
            );
        }
    }

    private static function assertHash(string $hash, string $what): void
    {
        // blake2b-224 is 28 bytes, which is 56 hex characters. A hash of another length is a
        // verification key, an address or a transaction id that somebody has passed by mistake,
        // and every one of those would build a script that hashes to a real but wrong address.
        if (preg_match('/^[0-9a-f]{56}$/', $hash) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A %s is 56 lowercase hex characters, being blake2b-224 of a verification key. Got %d character(s).',
                $what,
                strlen($hash),
            ));
        }
    }
}

<?php

namespace App\Cardano;

use App\Models\Campaign;
use App\Models\Wallet;
use Cardano\Transaction\Address\Credential;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Signing\SigningKey;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Making a campaign's wallet, once.
 *
 * The thing this class exists to prevent is a campaign with two addresses. The job that creates a
 * wallet retries, and the window that matters is between generating a key and committing the row
 * that records it: a failure there and the retry makes a different key, which makes a different
 * script, which makes a different address. Funds sent to the first one are then held by a key
 * nothing refers to any more. Nobody would see this happen. The campaign would look correct, the
 * address would be a real address, and only a payment to the earlier one would reveal it.
 *
 * So generation, persistence and the recovery record happen inside one database transaction, and
 * the key is generated inside it rather than before it, so a rollback discards the key along with
 * everything else rather than leaving it in memory to be written by something later.
 *
 * The unique constraint on the wallets table is the other half. A transaction stops a partial
 * write; it does not stop two workers each completing their own. Check-then-act cannot close that
 * on its own, because both can pass the check before either writes.
 */
final class NativeWalletFactory
{
    public function __construct(private readonly CampaignKeyVault $vault) {}

    /**
     * The wallet for this campaign, making it if it does not exist yet.
     *
     * Idempotent by construction: a campaign that already has a wallet gets that one back, and two
     * callers racing produce one wallet rather than two, because the second is refused by the
     * database rather than by a check it might have passed.
     */
    public function forCampaign(Campaign $campaign): Wallet
    {
        // Refused before anything is generated: the owner hashes and the stake credential go inside
        // the address, and a key made without a wrapping key could not be stored readably. A
        // customer-funded campaign takes its stake credential from the customer's wallet instead.
        $missing = $this->missingSettings((string) $campaign->network);

        if ($campaign->funding_path === CampaignScript::FUNDING_CUSTOMER) {
            $missing = array_values(array_diff($missing, [self::stakeAddressVariable((string) $campaign->network)]));
        }

        if ($missing !== []) {
            throw new RuntimeException($this->missingMessage($missing));
        }

        $owners = $this->ownerKeyHashes();

        if ($existing = $campaign->wallet()->first()) {
            return $existing;
        }

        if ($campaign->expiry_slot === null) {
            throw new RuntimeException(
                'This campaign has no expiry slot, and the slot is inside the script and therefore inside the '
                .'address. Deriving one now would produce an address that cannot be reproduced later.'
            );
        }

        // Read and checked before anything is generated, for the same reason as the owner hashes:
        // it goes inside the address, and a missing one is recoverable while a wrong address is not.
        $network = $this->networkFor($campaign);
        $stake = $this->stakeCredentialFor($campaign, $network);

        return DB::transaction(function () use ($campaign, $owners, $network, $stake): Wallet {
            // Generated inside the transaction, so a rollback discards the key rather than leaving
            // it somewhere a later write could pick up. Fresh entropy from the system source every
            // time: a key derived from anything about the campaign would be reproducible by anyone
            // who knew the same things.
            //
            // The seed is drawn here and expanded, rather than calling the package's generate(),
            // because that method deliberately leaves no seed to write down. That is right for a
            // package that stores nothing and wrong for the one caller that has to: a campaign
            // signs transactions for months after it is created, so something has to be kept. The
            // bytes drawn are the same bytes from the same source either way.
            $seed = random_bytes(32);
            $key = SigningKey::fromSeed($seed);

            try {
                $script = $this->scriptFor($campaign, $key, $owners);
                $wrapped = $this->vault->wrap($seed);
                $address = $script->address($network, $stake);
                // Named for the network the campaign is on. The script only knows mainnet from test,
                // which is enough to derive the address and not enough to say where to submit.
                $record = ['network' => $campaign->network] + $script->recoveryRecord($network, $stake, $wrapped['version']);

                // The record has to rebuild the address it sits beside, from nothing but itself,
                // or it is not a recovery record. Checked at the one moment it is still cheap to
                // find out: before either is written.
                $rebuilt = CampaignScript::fromRecord($record)->address(
                    $network,
                    CampaignScript::stakeCredential($record['stake_address'], $network),
                );

                if ($rebuilt !== $address || $record['address'] !== $address) {
                    throw new RuntimeException(
                        'The recovery record does not rebuild the address it was written for, so nothing is stored.'
                    );
                }

                return Wallet::create([
                    'campaign_id' => $campaign->id,
                    'address' => $address,
                    'key' => $campaign->id,
                    'backend' => 'native',
                    'skey' => $wrapped['ciphertext'],
                    'vkey' => $key->publicKeyHex(),
                    'wrapping_key_version' => $wrapped['version'],
                    'recovery_record' => $record,
                ]);
            } finally {
                // Whatever happened, neither the seed nor the key stays in memory longer than the
                // write needed it. On the failure path this is the difference between a discarded
                // key and one still reachable from a stack trace.
                sodium_memzero($seed);
                $key->discard();
            }
        });
    }

    /**
     * @param  list<string>  $owners
     */
    private function scriptFor(Campaign $campaign, SigningKey $key, array $owners): CampaignScript
    {
        $campaignKeyHash = $key->credential()->hex();
        $expiry = (int) $campaign->expiry_slot;

        if ($campaign->funding_path === CampaignScript::FUNDING_CUSTOMER) {
            if (! $campaign->customer_key_hash) {
                throw new RuntimeException(
                    'This campaign is funded by its customer and no customer key hash is recorded, so the '
                    .'script would omit the branch that lets them take their own money back.'
                );
            }

            return CampaignScript::customerFunded($campaignKeyHash, $owners, $expiry, $campaign->customer_key_hash);
        }

        return CampaignScript::creditFunded($campaignKeyHash, $owners, $expiry);
    }

    private function networkFor(Campaign $campaign): Network
    {
        return $campaign->network === 'mainnet' ? Network::Mainnet : Network::Testnet;
    }

    /**
     * The stake credential the campaign's address carries, chosen by who funded it.
     *
     * Rewards follow ownership. An operator-funded campaign runs on the operator's own ADA, so it
     * carries the stake credential this deployment is configured with for the campaign's network,
     * and the staking rewards and the delegation stay with the operator. A customer-funded campaign carries the stake credential
     * of the wallet the customer connected, read from the reward address recorded on the campaign
     * when that wallet connects.
     *
     * Nothing here falls back to an enterprise address. An address without a delegation part
     * earns nothing on anything sent to it, and it is as permanent as any other.
     */
    public function stakeCredentialFor(Campaign $campaign, Network $network): Credential
    {
        if ($campaign->funding_path === CampaignScript::FUNDING_CUSTOMER) {
            if (! $campaign->customer_reward_address) {
                throw new RuntimeException(
                    'This campaign is funded by its customer and no reward address is recorded for the wallet '
                    .'they connected, so its address would carry no stake credential of theirs.'
                );
            }

            return CampaignScript::stakeCredential($campaign->customer_reward_address, $network);
        }

        return self::platformStakeCredential((string) $campaign->network);
    }

    /**
     * The platform's own stake credential for a network, from configuration.
     *
     * @throws RuntimeException naming the variable to set when it is missing or is not a stake
     *                          address on that network
     */
    public static function platformStakeCredential(string $network): Credential
    {
        $variable = self::stakeAddressVariable($network);
        $configured = trim((string) config('campaign_script.stake_addresses.'.$network));

        if ($configured === '') {
            throw new RuntimeException(sprintf(
                '%s is not set, so a campaign on %s has no stake credential to carry. Set it to the bech32 stake '
                .'address the platform stakes with on %s, then redeploy. A campaign is never given an address '
                .'without one.',
                $variable,
                $network,
                $network,
            ));
        }

        try {
            return CampaignScript::stakeCredential($configured, Network::named($network));
        } catch (\Throwable $e) {
            throw new RuntimeException(sprintf('%s is not a usable stake address for %s: %s', $variable, $network, $e->getMessage()), 0, $e);
        }
    }

    /** The environment variable holding the platform stake address for a network. */
    public static function stakeAddressVariable(string $network): string
    {
        return 'CAMPAIGN_STAKE_ADDRESS_'.strtoupper($network);
    }

    /**
     * The environment variables a campaign on this network cannot be created without and that are
     * not set or not usable: the two recovery key hashes, the wrapping key, and the stake address.
     *
     * @return list<string>
     */
    public function missingSettings(string $network): array
    {
        $missing = [];

        $owners = (array) config('campaign_script.owner_key_hashes', []) + [null, null];

        foreach ([0, 1] as $index) {
            // 56 lowercase hex characters, and the second not the first: a script with one recovery
            // holder is refused later, by a job nobody is watching.
            if (! preg_match('/^[0-9a-f]{56}$/', (string) $owners[$index]) || ($index === 1 && $owners[1] === $owners[0])) {
                $missing[] = 'CAMPAIGN_OWNER_KEY_HASH_'.($index + 1);
            }
        }

        if (! $this->vault->isConfigured()) {
            $missing[] = 'CAMPAIGN_WRAPPING_KEY_'.max(1, (int) config('campaign_script.wrapping_key_version', 1));
        }

        try {
            self::platformStakeCredential($network);
        } catch (RuntimeException) {
            $missing[] = self::stakeAddressVariable($network);
        }

        return $missing;
    }

    public function missingMessage(array $missing): string
    {
        return 'This deployment submits its own transactions, and these settings are not set or not usable: '
            .implode(', ', $missing).'. A campaign cannot be created without them, because they go inside '
            .'its address or protect its key and cannot be added afterwards. Set them and redeploy.';
    }

    /**
     * @return list<string>
     */
    private function ownerKeyHashes(): array
    {
        return array_values(array_filter((array) config('campaign_script.owner_key_hashes', [])));
    }
}

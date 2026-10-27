<?php

namespace App\Services;

use App\Cardano\CampaignKeyVault;
use App\Cardano\CampaignScript;
use App\Cardano\ChainConfirmation;
use App\Cardano\ChainData;
use App\Cardano\ChainInputsConsumed;
use App\Cardano\ChainLease;
use App\Cardano\ChainRejected;
use App\Cardano\ChainRetention;
use App\Cardano\ChainSubmitter;
use App\Cardano\FloatUnknown;
use App\Cardano\InvariantGate;
use App\Cardano\NativeWalletFactory;
use App\Cardano\PaymentBuilder;
use App\Cardano\PlatformFeeKey;
use App\Contracts\TransactionBackend;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Wallet;
use App\Support\PlatformFee;
use App\Support\Pricing;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Ledger\Natural;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Selection\AssetId;
use Cardano\Transaction\Selection\Utxo;
use Cardano\Transaction\Selection\ValueBag;
use Cardano\Transaction\Signing\SigningKey;
use CardanoPhp\DataClient\DTOs\Address\Utxo as ProviderUtxo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

/**
 * A campaign that holds its own funds, rather than one held for it.
 *
 * The other backends here are clients: they ask a service to make a wallet and to pay somebody, and
 * the service holds the key. This one holds nothing out of the application's sight, which means the
 * things a custodian was doing quietly now have to be done here and be seen to be done.
 *
 * Three of those are already built and this composes them. A wallet is generated once and never
 * twice. A chain lease means one worker at a time builds on the campaign's single output. A
 * transaction is recorded before it is submitted, so a worker killed in between resubmits the same
 * bytes rather than building a second transaction that pays the same claims again.
 *
 * Returning what is left to a customer is still unbuilt, and refuses rather than answering false,
 * which would read as an attempt that failed rather than one that never happened.
 */
class NativeBackend implements TransactionBackend
{
    private static ?string $holder = null;

    public function __construct(
        private readonly NativeWalletFactory $wallets,
        private readonly ChainData $chain,
        private readonly ChainRetention $retention,
        private readonly CampaignKeyVault $vault,
        private readonly ChainSubmitter $submitter,
        private readonly ChainConfirmation $confirmation,
        private readonly PlatformFeeKey $feeKey,
    ) {}

    /**
     * A campaign's own address, derived here rather than asked for.
     *
     * The interface promises a campaign id alongside it because the custodial backends use one as a
     * handle on an account they hold. There is no such account here, so the campaign's own id is
     * returned: the handle and the thing it refers to are the same object.
     */
    public function createBucket(Campaign $campaign, string $network): array
    {
        $wallet = $this->wallets->forCampaign($campaign);

        return [
            'address' => $wallet->address,
            'campaignId' => $campaign->id,
        ];
    }

    /**
     * Pay a batch of claims from the campaign's own funds.
     *
     * The order of the steps is the safety property, not an implementation detail.
     *
     * The lease comes first, because only one worker may build on a campaign's funds at a time:
     * two builders reading the same outputs would spend the same ones. It is renewed before each
     * transaction, and a call that finds it lost stops and returns what it has sent.
     *
     * Then the campaign's record is brought up to date with the chain, and before anything is built
     * it is asked whether anybody in this batch is already being paid. A recipient is a claim, and
     * each is paid by an output of its own. A recipient whose claim has a payment that is not
     * finished is answered with that transaction's hash and is not paid again. Only a recipient
     * with no such payment goes into a new transaction. Building a second transaction for
     * somebody already being paid is the one way this pays twice.
     *
     * A new transaction does not wait for earlier ones to confirm. It is built on the campaign's
     * outputs as they will be once everything in flight lands: what the chain holds, less what the
     * transactions in flight spend, plus what they return to the campaign, read from their own
     * recorded bytes rather than predicted. So a second batch arriving while the first is pending
     * spends the first one's change, and the chain accepts it as soon as it has seen the first.
     *
     * Then build, then the gate, then the record, and only then submit. Everything before the
     * submission can fail and be retried harmlessly. What cannot be undone is money moved with
     * nothing pointing at it, which is why the record is written first and why the gate runs on the
     * bytes that are about to go rather than on the intention behind them.
     *
     * Where the platform fee is collected, each payout also spends the campaign's fees output and
     * recreates it with this payout's fees added. Each claim goes into a payout with the rate of the
     * tier its ordinal falls in, counting the campaign's claims that have gone into payouts still
     * real, and the figure is stamped on the claim as the payout is recorded. Ordinals are assigned
     * under the lease, in payout order, so two workers cannot give two claims the same one.
     *
     * The answer names a transaction for every recipient that was paid or is being paid, keyed by
     * claim id. A recipient left out is left unsent,
     * and the claims job queues another run for it after a backoff. That includes everything after a
     * failure part way through: what was already sent is still answered, and the rest waits.
     */
    public function submitPayment(string $campaignId, array $recipients, string $network, ?string $txnMsg = null): array
    {
        if ($recipients === []) {
            throw new RuntimeException('A payment with no recipients pays nobody.');
        }

        $campaign = Campaign::findOrFail($campaignId);
        $wallet = $campaign->wallet()->firstOrFail();
        $lease = $this->lease();

        if (! $lease->take($campaign)) {
            // Answered with no payment rather than thrown. The claims job treats a batch the backend
            // sent nothing for as unsent and queues another run after a backoff, where a thrown
            // error would end the job and leave these claims until another claim happened to arrive.
            Log::warning('NativeBackend: another worker holds this campaign\'s chain, so this batch waits for the next run.', [
                'campaign_id' => $campaign->id,
            ]);

            return ['purchaseIds' => []];
        }

        // Whether this campaign pays the platform fee in its payouts, decided once for the call.
        $collects = PlatformFee::collects($campaign);

        try {
            // A payout that collects a fee is signed by the fee key, so without one nothing here can
            // be paid. Answered with no payment like a chain that cannot be read, so the claims job
            // queues another run after its backoff and the claims go out once the key exists.
            if ($collects && ! $this->feeKey->exists()) {
                $message = 'NativeBackend: the platform fee key does not exist, so no payout that collects a fee can be signed.';

                Log::error($message, [
                    'campaign_id' => $campaign->id,
                ]);

                return ['purchaseIds' => []];
            }

            // Everything this run reads from the chain comes first. A provider that cannot answer
            // is answered with no payment, like a held lease, so the claims job queues another run
            // after its backoff. Thrown, it would end the job and leave the claims unsent until
            // another claim happened to arrive.
            try {
                $tip = $this->chain->tip($network);

                // Brought up to date when the chain answers. When it does not, the record is as the
                // last check left it, and building on that cannot pay anybody twice: a payment not
                // yet marked finished is answered as live, and its claim is rebuilt when a later
                // reconciliation finishes it. Refusing to build here would stall every payment for as
                // long as the status endpoint is down, which is longer than any rebuild is safe to wait.
                try {
                    $this->confirmation->reconcile($campaign, $network);
                } catch (RuntimeException|ClientExceptionInterface $e) {
                    Log::warning('NativeBackend: the chain could not be reconciled, so this run builds on the record as it is.', [
                        'campaign_id' => $campaign->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                [$alreadyPaying, $toPay] = $this->partition($campaign, $recipients);

                if ($toPay !== []) {
                    $parameters = $this->chain->parameters($network);
                    $available = $this->spendableOutputs($campaign, $network, $wallet->address, $tip['slot']);
                    $feesOutput = $collects ? $this->currentFees($campaign, $network, $this->feeKey->address($campaign->network)) : null;
                }
            } catch (RuntimeException|ClientExceptionInterface $e) {
                Log::warning('NativeBackend: the chain could not be read, so this batch waits for the next run.', [
                    'campaign_id' => $campaign->id,
                    'error' => $e->getMessage(),
                ]);

                return ['purchaseIds' => []];
            }

            $ids = $alreadyPaying;

            if ($toPay === []) {
                return ['purchaseIds' => [], 'claimIds' => $ids];
            }

            $script = CampaignScript::fromRecord($wallet->recovery_record);
            $expirySlot = $script->expirySlot;
            $ledger = LedgerParameters::fromArray((array) $parameters);
            $cardanoNetwork = $campaign->network === 'mainnet' ? Network::Mainnet : Network::Testnet;

            // Bounded, and inside the script's expiry. Without an upper bound nothing could ever
            // say a payment had expired rather than gone missing, and expiry is the only condition
            // under which rebuilding one is safe.
            $ttl = min($tip['slot'] + (int) config('campaign_script.validity_seconds', 7200), $expirySlot - 1);

            if ($ttl <= $tip['slot']) {
                throw new RuntimeException(
                    'This campaign\'s authorisation has expired, so the branch of its script that pays a claim '
                    .'can no longer sign. Nothing here can pay from it.'
                );
            }

            // One transaction at a time until the batch is paid. Each builds on the change of the one
            // before, read back as a later run would read it, so a queue larger than a transaction
            // holds is not made to wait for the claims job's backoff between transactions.
            $queue = $toPay;
            $feeAddress = $collects ? $this->feeKey->address($campaign->network) : null;
            $path = Pricing::pathFor($campaign);

            while ($queue !== []) {
                $queue = $this->payable($queue, $available);

                if ($queue === []) {
                    break;
                }

                if (! $lease->renew($campaign)) {
                    Log::warning('NativeBackend: the chain lease was lost, so the rest of this batch is left for the next run.', [
                        'campaign_id' => $campaign->id,
                    ]);

                    break;
                }

                try {
                    // Each claim goes into this payout with the rate of the tier its ordinal falls in,
                    // counted from the campaign's claims in payouts that are still real. Read under
                    // the lease, so no other worker is giving the same ordinals out.
                    $ordinals = [];
                    $paidSoFar = 0;

                    if ($collects) {
                        $paidSoFar = $this->retention->feesState($campaign)['paid'];

                        foreach ($queue as $position => $recipient) {
                            $ordinals[$position] = $paidSoFar + $position + 1;
                            $rate = Pricing::marginalRate($path, $ordinals[$position]);

                            // A claim with no rate is not paid for nothing: the schedule is wrong.
                            if ($rate === null) {
                                throw new RuntimeException(sprintf(
                                    'The tier schedule pricing.paths.%s.tiers has no rate for claim %d. %s',
                                    $path,
                                    $ordinals[$position],
                                    Pricing::scheduleProblem($path) ?? 'Every claim needs a tier.',
                                ));
                            }

                            $queue[$position]['fee'] = $rate;
                        }

                        // With no fees output to spend, the payout opens one holding only its own
                        // fees, and the ledger refuses an output under its minimum. The first claim's
                        // fee covering it on its own is what lets every prefix of the batch open it.
                        if ($feesOutput === null && array_sum(array_column($queue, 'fee')) > 0
                            && $queue[0]['fee'] < PlatformFee::outputMinimum($ledger->utxoCostPerByte->toInt())) {
                            // Claim 1 of a campaign always pays the first tier, so that rate being short is
                            // a setting to fix. Any later claim is short because the fees output was spent
                            // elsewhere.
                            if ($paidSoFar === 0) {
                                throw new RuntimeException((string) PlatformFee::firstTierShortfall($path, PlatformFee::outputMinimum($ledger->utxoCostPerByte->toInt())));
                            }

                            Log::warning('NativeBackend: this campaign has no fees output and its next fee is under the minimum to open one, so the batch waits.', [
                                'campaign_id' => $campaign->id,
                            ]);

                            break;
                        }
                    }

                    // The builder pays as many as fit under the size limit and the balance, from the
                    // first. The rest go in the next transaction of this call.
                    $built = $this->feeSigning($collects, fn (?SigningKey $feeSigner): array => $this->signing($wallet, fn (SigningKey $key): array => (new PaymentBuilder($ledger, $cardanoNetwork))->build(
                        array_values($available),
                        $this->asPayments($queue),
                        $wallet->address,
                        $script->script(),
                        $key,
                        $ttl,
                        $txnMsg,
                        $collects ? [
                            'address' => $feeAddress,
                            'script' => $this->feeKey->script(),
                            'key' => $feeSigner,
                            'previous' => $feesOutput,
                        ] : null,
                    )));

                    $paying = array_slice($queue, 0, $built['paid']);
                    $stamps = array_slice($ordinals, 0, $built['paid']);
                    $queue = array_slice($queue, $built['paid']);
                    $hash = $built['transaction']->hashHex();

                    // Before anything is sent. Everything after this point can fail and be retried; what
                    // cannot be undone is money moved with nothing pointing at it.
                    (new InvariantGate($ledger, $cardanoNetwork))->assert(
                        $built['bytes'],
                        $this->inputValues($available + ($feesOutput === null ? [] : [$feesOutput->reference() => $feesOutput]), $built['inputs']),
                        $wallet->address,
                        $expirySlot,
                        $hash,
                        count($paying),
                        // What the gate holds the payout to comes from the campaign's own record and the
                        // tier schedule, not from what the builder says it did.
                        $collects ? [
                            'address' => $feeAddress,
                            'input' => $feesOutput?->reference(),
                            'previous' => $feesOutput === null ? 0 : $this->provenValue($campaign, $network, $feesOutput),
                            'paid_before' => $paidSoFar,
                            'path' => $path,
                        ] : null,
                        [$script->scriptHashHex(), ...($feesOutput !== null && $built['fees'] !== null ? [$this->feeKey->script()->hashHex()] : [])],
                    );

                    $this->retention->record(
                        $campaign,
                        $hash,
                        $built['bytes'],
                        $built['inputs'],
                        $ttl,
                        $this->linkage($paying, $built['transaction'], $stamps),
                        $built['fees'] === null ? null : ['input' => $built['fees']['input'], 'output_index' => $built['fees']['index']],
                    );

                    try {
                        $this->submitter->submit($network, $built['bytes'], $hash);
                    } catch (ChainRejected $e) {
                        // Refused the first time it was offered, so it never landed and never will from
                        // this offer. Marked finished so that nothing builds on its outputs and its
                        // recipients are free to be paid by the next attempt.
                        $this->retention->markFailed($hash, 'rejected');

                        throw $e;
                    } catch (ChainInputsConsumed $e) {
                        // An input of it is spent. Dead if the provider shows one of ours spent by
                        // somebody else and does not know this transaction, which frees its claims now.
                        $this->confirmation->settleRefusedInputs($network, $hash, array_map(static fn (array $input): string => $input['hash'].'#'.$input['index'], $built['inputs']));

                        throw $e;
                    }

                    foreach ($paying as $recipient) {
                        $ids[(int) $recipient['claimId']] = $hash;
                    }

                    if ($queue !== []) {
                        $available = $this->spendableOutputs($campaign, $network, $wallet->address, $tip['slot']);
                        $feesOutput = $collects ? $this->currentFees($campaign, $network, $feeAddress) : null;
                    }
                } catch (\Throwable $e) {
                    // Whatever went wrong, what was already sent is answered and the rest waits for
                    // the next run. Thrown, the answer for transactions already on the chain would
                    // be lost and nothing would queue another run.
                    Log::error('NativeBackend: a payment could not be completed, so the rest of this batch waits for the next run.', [
                        'campaign_id' => $campaign->id,
                        'error' => $e->getMessage(),
                    ]);

                    break;
                }
            }

            return ['purchaseIds' => [], 'claimIds' => $ids];
        } finally {
            $lease->release($campaign);
        }
    }

    /**
     * Split a batch into recipients somebody is already paying and recipients still to pay.
     *
     * A payment recorded for a claim that is not finished is either confirmed, still inside its
     * validity, or on the chain whatever its validity said, because the record has just been
     * reconciled and a transaction past its validity that the chain never saw is finished. A
     * recipient with such a payment is answered with it. A claim is paid once, by an output of its
     * own, so a wallet that claimed one code twice is two recipients, each with its own answer.
     *
     * @param  array<int, array<string, mixed>>  $recipients
     * @return array{array<int, string>, array<int, array<string, mixed>>} claim id to hash, and the rest
     */
    private function partition(Campaign $campaign, array $recipients): array
    {
        $linked = $this->retention->linkedTo($campaign, array_map(static fn (array $recipient): int => (int) $recipient['claimId'], $recipients));
        $alreadyPaying = [];
        $toPay = [];

        foreach ($recipients as $recipient) {
            $live = array_values(array_filter($linked[(int) $recipient['claimId']] ?? [], static fn (object $row): bool => $row->failed_at === null));

            if ($live !== []) {
                $alreadyPaying[(int) $recipient['claimId']] = $live[0]->tx_hash;
            } else {
                $toPay[] = $recipient;
            }
        }

        return [$alreadyPaying, $toPay];
    }

    /**
     * The campaign's outputs as they will be once everything in flight has landed.
     *
     * What the chain holds, less the inputs each transaction in flight spends, plus the outputs it
     * pays back to the campaign, read from its recorded bytes. Walked in the order the transactions
     * were built, and a transaction is applied only where every input it spends is present: one
     * already in a block has its effect in what the chain holds, and one whose inputs are gone for
     * any other reason will never land, so nothing may be built on it.
     *
     * @return array<string, Utxo>
     */
    private function spendableOutputs(Campaign $campaign, string $network, string $address, int $slot): array
    {
        $outputs = [];

        foreach ($this->chain->utxos($network, $address) as $utxo) {
            $selectable = self::selectable($utxo, $address);
            $outputs[$selectable->reference()] = $selectable;
        }

        $campaignBytes = Address::fromBech32($address)->toBytes();

        [$finished] = $this->retention->unconfirmedByFate($campaign);

        foreach ($this->retention->inFlight($campaign, $slot) as $row) {
            // Built on a transaction that is finished, through its change or through the fees
            // output it spent, so it can never land. Its other inputs stay where they are and
            // nothing may be built on its change.
            if (isset($finished[$row->tx_hash])) {
                continue;
            }

            // The fees output is at another address and is tracked on its own, so it is not among
            // the campaign's outputs to be found here.
            $spends = array_values(array_diff(array_map(
                static fn (array $input): string => $input['hash'].'#'.$input['index'],
                json_decode($row->inputs_consumed, true, 512, JSON_THROW_ON_ERROR),
            ), [(string) $row->fees_input]));

            if (array_diff($spends, array_keys($outputs)) !== []) {
                continue;
            }

            foreach ($spends as $reference) {
                unset($outputs[$reference]);
            }

            $transaction = TransactionDecoder::decode(ChainRetention::signedBytes($row));

            foreach ($transaction->body->outputs() as $index => $output) {
                if ($output->address === $campaignBytes) {
                    $pending = Utxo::of($row->tx_hash, $index, $address, ValueBag::fromValue($output->value));
                    $outputs[$pending->reference()] = $pending;
                }
            }
        }

        if ($outputs === []) {
            throw new RuntimeException(
                'This campaign holds nothing at its address, so there is nothing to pay a claim with.'
            );
        }

        return $outputs;
    }

    /**
     * One provider output as the selector's Utxo.
     */
    public static function selectable(ProviderUtxo $utxo, string $address): Utxo
    {
        $assets = [];

        foreach ($utxo->value->assets as $asset) {
            $assets[] = [AssetId::fromHex($asset->policyId, $asset->assetName), $asset->quantity];
        }

        return Utxo::of(
            $utxo->txHash,
            $utxo->outputIndex,
            $address,
            ValueBag::of((string) $utxo->value->lovelace, $assets),
        );
    }

    /**
     * What each input was worth, for the gate's balance check, from the same outputs it was
     * selected from.
     *
     * @param  array<string, Utxo>  $available
     * @param  array<int, array{hash: string, index: int}>  $inputs
     * @return array<int, array{coin: string, assets: array<string, string>}>
     */
    private function inputValues(array $available, array $inputs): array
    {
        return array_map(static function (array $input) use ($available): array {
            $utxo = $available[$input['hash'].'#'.$input['index']] ?? throw new RuntimeException(sprintf(
                'The input %s#%d is not among the outputs it was selected from, so its value cannot be checked.',
                $input['hash'],
                $input['index'],
            ));

            $assets = [];

            foreach ($utxo->value->assets() as [$id, $quantity]) {
                $assets[$id->key()] = $quantity->value;
            }

            return ['coin' => $utxo->coin()->value, 'assets' => $assets];
        }, $inputs);
    }

    /**
     * The recipients the campaign holds the tokens for, in order, each counted against what is left
     * after the ones before it.
     *
     * A reward whose token the campaign does not hold cannot be built, and one such claim must not
     * stop everyone behind it. It is left out of the batch, so its claim stays unsent and is tried
     * again on a later run, and the rest are paid.
     *
     * @param  array<int, array<string, mixed>>  $recipients
     * @param  array<string, Utxo>  $available
     * @return array<int, array<string, mixed>>
     */
    private function payable(array $recipients, array $available): array
    {
        $held = ValueBag::empty();

        foreach ($available as $utxo) {
            $held = $held->plus($utxo->value->withCoin('0'));
        }

        $payable = [];

        foreach ($recipients as $recipient) {
            $needed = ValueBag::empty();

            try {
                foreach ($recipient['tokens'] ?? [] as $token) {
                    $needed = $needed->plusAsset(AssetId::fromHex($token['policy'], $token['name']), Natural::of($token['amount']));
                }
            } catch (\Throwable $e) {
                // A policy id or asset name that is not what a ledger takes. The reward can never be
                // built, and it must not stop the claims behind it.
                Log::warning('NativeBackend: a reward names a token that cannot exist, so it is left unpaid.', [
                    'code' => $recipient['pooCode'] ?? null,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $held->covers($needed)) {
                Log::warning('NativeBackend: the campaign does not hold the tokens for this reward, so it is left unpaid.', [
                    'code' => $recipient['pooCode'] ?? null,
                ]);

                continue;
            }

            $held = $held->minus($needed);
            $payable[] = $recipient;
        }

        return $payable;
    }

    /**
     * @param  array<int, array<string, mixed>>  $recipients
     * @return array<int, array{address: string, lovelace: int, tokens: array, fee?: int}>
     */
    private function asPayments(array $recipients): array
    {
        return array_map(
            static fn (array $recipient): array => [
                'address' => $recipient['address'],
                'lovelace' => (int) $recipient['lovelace'],
                'tokens' => $recipient['tokens'] ?? [],
            ] + (isset($recipient['fee']) ? ['fee' => (int) $recipient['fee']] : []),
            $recipients,
        );
    }

    /**
     * What a transaction pays, keyed by code and address, the pair the claims job names a payment
     * by and reads the answer back with.
     *
     * @param  array<int, array<string, mixed>>  $recipients
     * @param  array<int, int>  $ordinals  where a fee is collected, each recipient's place among the
     *                                     campaign's paid claims, which names its rate
     * @return array<int, array{claim_id: int, lovelace: int, fee_lovelace?: int, ordinal?: int}>
     */
    private function linkage(array $recipients, Transaction $transaction, array $ordinals = []): array
    {
        // What each output actually holds, which is the recipient's reward raised to the ledger
        // minimum where it had fallen short. The record says what was paid, not what was asked.
        $outputs = $transaction->body->outputs();

        return array_map(static fn (array $recipient, int $index): array => [
            'claim_id' => (int) $recipient['claimId'],
            'lovelace' => $outputs[$index]->value->coin->toInt(),
        ] + (isset($ordinals[$index]) ? ['fee_lovelace' => (int) $recipient['fee'], 'ordinal' => $ordinals[$index]] : []), $recipients, array_keys($recipients));
    }

    /**
     * What happened to a payment this campaign made, in the words the status check acts on.
     *
     * Read from the campaign's record and nothing else. The claim check reconciles the record with
     * the chain once for the whole campaign before it asks about any claim, so asking the chain here
     * would repeat that work for every claim of a transaction. `completed` where the transaction is
     * confirmed, `timeout` only where it is finished (refused, or past its validity and never seen
     * by the chain, which is the one answer on which the claims are paid again), and `processing`
     * for the rest.
     */
    public function checkStatus(string $purchaseId, string $network): array
    {
        $row = DB::table('campaign_transactions')->where('tx_hash', $purchaseId)->first(['tx_hash', 'confirmed_at', 'failed_at']);

        return match (true) {
            $row === null => ['status' => 'unknown', 'txHash' => null],
            $row->confirmed_at !== null => ['status' => 'completed', 'txHash' => $row->tx_hash],
            $row->failed_at !== null => ['status' => 'timeout', 'txHash' => $row->tx_hash],
            default => ['status' => 'processing', 'txHash' => $row->tx_hash],
        };
    }

    /**
     * Return what is left to an address.
     *
     * Settlement's last act. It runs through the same lease, gate and record as a payment, because
     * the ways a sweep can go wrong are the ways a payment can go wrong and one of them is worse:
     * a sweep moves the whole remaining balance, so change paid to the wrong place is everything
     * rather than a part.
     *
     * The interface answers with a boolean, which cannot distinguish a sweep that was not attempted
     * from one that failed. Anything that went wrong therefore throws, and false is never returned.
     */
    public function refund(string $campaignId, string $address, string $network): bool
    {
        $campaign = Campaign::findOrFail($campaignId);
        $wallet = $campaign->wallet()->firstOrFail();
        $lease = $this->lease();

        if (! $lease->take($campaign)) {
            throw new RuntimeException(
                'Another worker holds this campaign\'s chain, so what it holds is about to change. Returning '
                .'it now would build against a balance that is already being spent.'
            );
        }

        try {
            $tip = $this->chain->tip($network);

            // Settled against the chain first: a payment that has landed must not block the sweep
            // for want of a scheduled run to mark it.
            $this->confirmation->reconcile($campaign, $network);

            // Settlement pays what is owed, confirms it landed, and only then returns the rest. A
            // sweep built while a payment is in flight would spend the outputs that payment spends,
            // and whichever reached a block first would leave the other refused.
            if ($this->retention->inFlight($campaign, $tip['slot']) !== []) {
                throw new RuntimeException(
                    'This campaign has payments that have not confirmed yet. What is left is returned once '
                    .'they have, so that a claim being paid is never raced by the sweep.'
                );
            }

            // Nor while a claim is still waiting to be paid: returning the balance would leave it
            // unpayable, with the operator told the refund went through.
            if ($campaign->claims()->awaitingSubmission()->exists()) {
                throw new RuntimeException(
                    'This campaign has claims that have not been paid yet. What is left is returned once they '
                    .'have been, so that a claim is never left owed with nothing to pay it.'
                );
            }

            $script = CampaignScript::fromRecord($wallet->recovery_record);
            $ledger = LedgerParameters::fromArray((array) $this->chain->parameters($network));
            $cardanoNetwork = $campaign->network === 'mainnet' ? Network::Mainnet : Network::Testnet;

            $expirySlot = $script->expirySlot;
            $ttl = min($tip['slot'] + (int) config('campaign_script.validity_seconds', 7200), $expirySlot - 1);

            if ($ttl <= $tip['slot']) {
                throw new RuntimeException(
                    'This campaign\'s authorisation has expired. The platform branch of its script can no '
                    .'longer sign, so what it holds is now reachable only by the recovery keys.'
                );
            }

            $available = $this->spendableOutputs($campaign, $network, $wallet->address, $tip['slot']);
            $sent = 0;

            // As many sweeps as it takes. One holds only the outputs that fit under maxTxSize, and
            // nothing a sweep pays comes back to the campaign, so what is left is still in
            // `$available` and the next sweep takes the next outputs. The same shape as paying a
            // batch: the lease is renewed before each, and a failure after the first is reported as
            // what was sent.
            while ($available !== []) {
                try {
                    if (! $lease->renew($campaign)) {
                        throw new RuntimeException('The chain lease was lost, so the rest of the sweep is left undone.');
                    }

                    $built = $this->signing($wallet, fn (SigningKey $key): array => (new PaymentBuilder($ledger, $cardanoNetwork))->sweep(
                        array_values($available),
                        $address,
                        $script->script(),
                        $key,
                        $ttl,
                    ));

                    $hash = $built['transaction']->hashHex();

                    // The same checks a payment gets. Every output of a sweep returns to the
                    // destination, and the gate refuses one that pays anywhere else.
                    (new InvariantGate($ledger, $cardanoNetwork))->assert(
                        $built['bytes'],
                        $this->inputValues($available, $built['inputs']),
                        $address,
                        $expirySlot,
                        $hash,
                        0,
                        null,
                        [$script->scriptHashHex()],
                    );

                    $this->retention->record($campaign, $hash, $built['bytes'], $built['inputs'], $ttl, []);

                    try {
                        $this->submitter->submit($network, $built['bytes'], $hash);
                    } catch (ChainRejected $e) {
                        // Refused, so it never landed. Marked finished, or it would block every
                        // payment and refund until its validity passed.
                        $this->retention->markFailed($hash, 'rejected');

                        throw $e;
                    }

                    $sent++;
                    $available = array_diff_key($available, array_flip(array_map(
                        static fn (array $input): string => $input['hash'].'#'.$input['index'],
                        $built['inputs'],
                    )));
                } catch (\Throwable $e) {
                    if ($sent === 0) {
                        throw $e;
                    }

                    Log::error('NativeBackend: a sweep could not be completed, so what is left is returned by the next refund.', [
                        'campaign_id' => $campaign->id,
                        'error' => $e->getMessage(),
                    ]);

                    break;
                }
            }

            return true;
        } finally {
            $lease->release($campaign);
        }
    }

    /**
     * What the campaign address holds, one entry per unspent output, in the shape the campaign
     * page reads: lovelace as a decimal string, and each asset as its policy id, its asset name in
     * hex and its quantity. The same shape the hosted backend answers with, so the page does not
     * need to know which backend a campaign is on.
     *
     * A balance that could not be read throws FloatUnknown. Answering with an empty list would be
     * reporting a campaign as drained because a provider was down.
     *
     * @return list<array{txHash: string, outputIndex: int, lovelace: string, nativeAssets: list<array{policy: string, name: string, amount: string}>}>
     *
     * @throws FloatUnknown
     */
    public function getBalance(string $address, string $network): array
    {
        try {
            $utxos = $this->chain->utxos($network, $address);
        } catch (\Throwable $e) {
            Log::warning('NativeBackend: could not read a campaign balance.', [
                'network' => $network,
                'error' => $e->getMessage(),
            ]);

            throw new FloatUnknown('The balance could not be read, which is not the same as it being empty.', 0, $e);
        }

        return array_map(static fn (ProviderUtxo $utxo): array => [
            'txHash' => $utxo->txHash,
            'outputIndex' => $utxo->outputIndex,
            'lovelace' => (string) $utxo->value->lovelace,
            'nativeAssets' => array_values(array_map(static fn ($asset): array => [
                'policy' => $asset->policyId,
                'name' => $asset->assetName,
                'amount' => $asset->quantity,
            ], $utxo->value->assets)),
        ], $utxos);
    }

    /**
     * What the chain says, or what the deployment falls back to, saying which.
     *
     * The interface forbids throwing here, because this figure feeds a minimum shown while somebody
     * is setting a campaign up and a page that cannot render is worse than one that says where its
     * number came from.
     */
    public function protocolParameters(string $network): array
    {
        try {
            $parameters = $this->chain->parameters($network);

            return [
                'coins_per_utxo_byte' => (int) $parameters->utxoCostPerByte,
                'source' => 'chain',
            ];
        } catch (\Throwable $e) {
            Log::warning('NativeBackend: protocol parameters unavailable, using the configured fallback.', [
                'network' => $network,
                'error' => $e->getMessage(),
            ]);

            return [
                'coins_per_utxo_byte' => (int) config('cardano.min_utxo.coins_per_utxo_byte', 4310),
                'source' => 'fallback',
            ];
        }
    }

    /**
     * The campaign's fees output as it will be once everything in flight has landed, or null where
     * it has none to spend.
     *
     * Tracked by output reference, from the campaign's own record: the newest transaction that is
     * still real and made one. A confirmed one is checked against the chain, and an output that
     * is no longer there was spent by something other than this campaign's chain, an owner's
     * break-glass spend for example. The campaign then opens a new fees output with its next
     * payout rather than waiting on one that is gone. Nothing is lost by that: what was collected
     * is on the claims and in the payout records, which this never touches, and the claims behind
     * it keep their ordinals.
     *
     * An unconfirmed one is read from its own recorded bytes, like the change of a payout in
     * flight, so a chained payout spends its parent's fees output before the parent is in a block.
     */
    private function currentFees(Campaign $campaign, string $network, string $feeAddress): ?Utxo
    {
        $latest = $this->retention->feesState($campaign)['latest'];

        if ($latest === null) {
            return null;
        }

        $index = (int) $latest->fees_output_index;

        if ($latest->confirmed_at !== null) {
            $held = $this->chain->unspentOutput($network, $latest->tx_hash, $index);

            if ($held !== null) {
                return Utxo::of($latest->tx_hash, $index, $feeAddress, ValueBag::ofCoin($held));
            }

            Log::warning('NativeBackend: the campaign\'s fees output is no longer unspent, so its next payout opens a new one.', [
                'campaign_id' => $campaign->id,
                'tx_hash' => $latest->tx_hash,
                'output_index' => $index,
            ]);

            return null;
        }

        $output = TransactionDecoder::decode($this->retention->bytesOf($latest->tx_hash))->body->outputs()[$index];

        // Read from bytes this campaign wrote, so it should be at the fee address. Spending
        // something else as a fees output would take it from wherever it is, so it is checked.
        if (Address::fromBytes($output->address)->toBech32() !== $feeAddress) {
            throw new RuntimeException(sprintf('The fees output %s#%d is not at the fee address.', $latest->tx_hash, $index));
        }

        return Utxo::fromOutput($latest->tx_hash, $index, $output);
    }

    /**
     * What the campaign's fees output really holds, read again from where it came from and not from
     * the output the payout was built on.
     *
     * The gate is told this as the value the output held, and the balance check is told what the
     * payout spends. If the two ever differ, the payout does not balance and is refused, which is
     * how a wrong figure on either side is found. For an unconfirmed output the source is the
     * signed bytes of the transaction that made it, and for a confirmed one the provider.
     */
    private function provenValue(Campaign $campaign, string $network, Utxo $fees): int
    {
        $latest = $this->retention->feesState($campaign)['latest'];

        if ($latest === null || $latest->tx_hash.'#'.$latest->fees_output_index !== $fees->reference()) {
            throw new RuntimeException('The fees output being spent is not the one this campaign recorded.');
        }

        if ($latest->confirmed_at === null) {
            return TransactionDecoder::decode($this->retention->bytesOf($latest->tx_hash))->body->outputs()[(int) $latest->fees_output_index]->value->coin->toInt();
        }

        return $this->chain->unspentOutput($network, $latest->tx_hash, (int) $latest->fees_output_index)
            ?? throw new RuntimeException('The fees output being spent is no longer unspent.');
    }

    /**
     * Run something with the fee key where a fee is collected, and with none where it is not.
     *
     * @param  callable(?SigningKey): array  $use
     */
    private function feeSigning(bool $collects, callable $use): array
    {
        return $collects ? $this->feeKey->signing($use) : $use(null);
    }

    /**
     * Run something with the campaign's signing key, which exists for that long and no longer.
     *
     * @param  callable(SigningKey): array  $use
     */
    private function signing(Wallet $wallet, callable $use): array
    {
        $seed = $this->signingSeed($wallet);

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
     * The campaign's signing key, decrypted for as long as one transaction needs it.
     *
     * Its own method so that every route to a decrypted key is this one, and so the wrapping
     * version comes from the row rather than from configuration: a key written before a rotation
     * is read under the version it was written with.
     */
    private function signingSeed(Wallet $wallet): string
    {
        if ($wallet->skey === null || $wallet->wrapping_key_version === null) {
            throw new RuntimeException(
                'This campaign has no stored key, so nothing here can sign for it. A campaign created under '
                .'another backend cannot be moved to this one, because its address belongs to whoever holds '
                .'that key.'
            );
        }

        return $this->vault->unwrap($wallet->skey, (int) $wallet->wrapping_key_version);
    }

    /**
     * A lease for this worker, named so that a stuck lease can be traced to the process holding it.
     *
     * The random part is chosen once per process. A host name and a process id repeat across
     * containers and after a restart, and a second worker that looked like the first would be let
     * back into a lease it does not hold. The host name is cut so the whole fits its column.
     */
    private function lease(): ChainLease
    {
        self::$holder ??= substr((string) gethostname(), 0, 24).':'.getmypid().':'.bin2hex(random_bytes(8));

        return new ChainLease(self::$holder);
    }
}

<?php

namespace App\Console\Commands;

use App\Cardano\CampaignScript;
use App\Cardano\ChainConfirmation;
use App\Cardano\ChainData;
use App\Cardano\ChainInputsConsumed;
use App\Cardano\ChainRetention;
use App\Cardano\FeeInvariantFailed;
use App\Cardano\InvariantGate;
use App\Cardano\PlatformFeeKey;
use App\Jobs\ProcessClaims;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Providers\AppServiceProvider;
use App\Services\ClaimStatusChecker;
use App\Support\PlatformFee;
use App\Support\Pricing;
use Cardano\Transaction\Address\Address;
use Cardano\Transaction\Address\BaseAddress;
use Cardano\Transaction\Address\Network;
use Cardano\Transaction\Builder\TransactionBodyBuilder;
use Cardano\Transaction\Codec\TransactionDecoder;
use Cardano\Transaction\Ledger\LedgerParameters;
use Cardano\Transaction\Primitives\Transaction;
use Cardano\Transaction\Primitives\TransactionOutput;
use Cardano\Transaction\Primitives\Value;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Run a campaign against preprod, end to end, and say what actually happened.
 *
 * Everything this exercises has tests. The tests answer whether the code does what somebody
 * believed a chain does; this answers whether a chain agrees. Three things cannot be settled any
 * other way, and all three are reported as measurements:
 *
 * Whether a chained batch is accepted without waiting. Each batch after the first spends the change
 * of the one before while that one is still unconfirmed, so the node that receives it must already
 * hold the parent. A child refused because its inputs are missing means the endpoint handed it to a
 * node that had not seen the parent, which is what a provider without sticky routing does.
 *
 * How long each transaction takes to reach the configured confirmation depth, which is what that
 * depth is traded against.
 *
 * And whether the production path closes the loop. Each batch is paid by the claims job itself,
 * the scheduled status check marks the claims completed from the chain, and nothing is paid twice.
 *
 * On a campaign that collects the platform fee it also shows the fees output doing what it must on a
 * chain: the first payout creates it holding the tier-1 fee, a chained payout carries it forward
 * before its parent is confirmed, a payout after confirmation tops it up, the fee address ends up
 * holding what the claims were stamped with, and the gate refuses a fees output that is one lovelace
 * out. Nothing else about the run changes where no fee is collected.
 *
 * It is deliberately not a test. A test that needs a funded key, a network and several minutes is a
 * test that gets skipped, and a skipped test reads as a passing one.
 */
class PreprodCampaignSuite extends Command
{
    protected $signature = 'cardano:preprod-suite
        {campaign : The campaign to exercise, which must already hold funds}
        {--to= : A test network address you hold; every claim the suite makes pays it}
        {--claims=3 : How many claims to pay in each batch}
        {--chain=3 : How many batches to submit back to back without waiting for confirmation}
        {--lovelace=2000000 : What each claim pays, in lovelace}
        {--sweep= : Once everything has confirmed, return what is left to this address}
        {--wait=900 : Seconds to wait for confirmations before reporting what did not land}
        {--poll=20 : Seconds between confirmation checks}';

    protected $description = 'Exercise a funded preprod campaign end to end and report what the chain did';

    /** @var array<string, float> transaction hash to the moment it was submitted */
    private array $submittedAt = [];

    /** @var array<string, float> transaction hash to seconds from submission to confirmation */
    private array $confirmedAfter = [];

    public function handle(
        ChainRetention $retention,
        ChainConfirmation $confirmation,
        ChainData $chain,
        ClaimStatusChecker $checker,
    ): int {
        $campaign = Campaign::find($this->argument('campaign'));

        if (! $campaign) {
            $this->error('No such campaign.');

            return self::FAILURE;
        }

        if (! in_array($campaign->network, ['preprod', 'preview'], true)) {
            // Refused rather than warned about. This submits real transactions, and the one place
            // a suite like this must never run is the network where a mistake costs somebody's
            // money rather than test ada.
            $this->error('This campaign is on '.$campaign->network.'. This suite submits transactions and is for preprod or preview.');

            return self::FAILURE;
        }

        $wallet = $campaign->wallet()->first();

        if (! $wallet || $wallet->backend !== 'native') {
            $this->error('This campaign does not hold its own funds, so there is nothing here to exercise.');

            return self::FAILURE;
        }

        $collects = PlatformFee::collects($campaign);

        if ($collects && ! app(PlatformFeeKey::class)->exists()) {
            $message = 'This campaign collects the platform fee and the platform fee key does not exist.';

            $this->error($message);

            return self::FAILURE;
        }

        $to = $this->testnetAddress((string) $this->option('to'));

        if ($to === null) {
            $this->error('--to must be a test network payment address (addr_test1...) that you hold. Every claim the suite makes pays it.');

            return self::FAILURE;
        }

        $network = $campaign->network;

        $this->line('Campaign '.$campaign->id.' on '.$network);
        $this->newLine();

        // 1. What it holds, read from the chain. A failed read stops the suite: it is not an empty
        //    campaign.
        try {
            $balance = array_sum(array_map(
                static fn ($utxo): int => (int) $utxo->value->lovelace,
                $chain->utxos($network, $wallet->address),
            ));
        } catch (Throwable $e) {
            $this->error('The balance could not be read, so nothing here knows whether to start: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Balance '.number_format($balance).' lovelace.');

        // 2. Batches back to back, each paid by the claims job, each built on the change of the
        //    one before while that one is still unconfirmed.
        $batches = [];
        $refused = null;
        $startedAt = microtime(true);
        $paidBefore = $collects ? $retention->feesState($campaign)['paid'] : 0;

        for ($batch = 1; $batch <= (int) $this->option('chain'); $batch++) {
            $claims = $this->makeClaims($campaign, $to, (int) $this->option('claims'));
            $submittedAt = microtime(true);

            try {
                (new ProcessClaims($campaign->id))->handle();
            } catch (Throwable $e) {
                $refused = ['batch' => $batch, 'error' => $e];

                break;
            }

            $hashes = Claim::whereIn('id', $claims)->pluck('transaction_id')->filter()->unique()->values()->all();

            if (count($hashes) !== 1 || Claim::whereIn('id', $claims)->whereNull('transaction_id')->exists()) {
                // The backend answers a batch the chain refused with nothing sent, so the claims job
                // names no transaction. After the first batch that is what a child reaching a node
                // without its parent looks like from here.
                $refused = ['batch' => $batch, 'error' => $batch > 1
                    ? new ChainInputsConsumed('The claims job did not name a transaction for every claim in the batch.')
                    : new \RuntimeException('The claims job did not name one transaction for every claim in the batch.')];

                break;
            }

            $hash = $hashes[0];
            $this->submittedAt[$hash] = $submittedAt;
            $parent = $batches === [] ? null : $batches[count($batches) - 1];
            $chained = $parent !== null && $this->spendsOutputOf($hash, $parent['hash']);
            $batches[] = ['batch' => $batch, 'hash' => $hash, 'chained' => $chained];

            $this->info(sprintf(
                'Batch %d submitted: %s (%d claim(s))%s',
                $batch,
                $hash,
                count($claims),
                $parent === null ? '' : ($chained
                    ? ', spending the unconfirmed change of batch '.$parent['batch']
                    : ', NOT spending the change of batch '.$parent['batch']),
            ));
        }

        $submittedIn = round(microtime(true) - $startedAt, 1);

        if ($refused !== null) {
            $this->error(sprintf('Batch %d refused after %d accepted: %s', $refused['batch'], count($batches), $refused['error']->getMessage()));

            if ($refused['error'] instanceof ChainInputsConsumed && $refused['batch'] > 1) {
                $this->error(
                    'The node that answered did not hold the input this batch spends, which is the previous batch\'s '
                    .'change. Either the endpoint is not sticky and another node answered, or the previous batch left '
                    .'the mempool. The claims job offers it again on its next run.'
                );
            }
        }

        $this->newLine();
        $this->line(sprintf('Submitted %d batch(es) in %ss without waiting. Depth in flight: %d.', count($batches), $submittedIn, count($retention->open($campaign))));

        // 3. How long each one takes to reach the configured depth.
        $counts = $this->awaitConfirmation($campaign, $confirmation, array_column($batches, 'hash'));

        // 3b. Where the fee is collected, one more payout once everything has confirmed: it spends
        //     the confirmed fees output rather than an unconfirmed one, which is the top-up case.
        $topUp = null;

        if ($collects && $refused === null && $counts['pending'] === 0 && $counts['expired'] === 0 && $batches !== []) {
            $topUp = $this->topUp($campaign, $to, $confirmation);

            if ($topUp !== null && $topUp['confirmed'] === false) {
                $counts['pending']++;
            }
        }

        // 3c. The fees themselves, read back from the records and from the chain.
        $fees = $collects ? $this->feesMeasurements($campaign, $chain, $batches, $topUp, $paidBefore) : null;

        // 4. The status check the scheduler runs, which is what marks a claim completed.
        $checked = $checker->check($campaign, 30);
        $this->line(sprintf(
            'Claim status check: %d checked, %d completed, %d processing, %d timed out.',
            $checked['checked'],
            $checked['completed'],
            $checked['processing'],
            $checked['timeout'],
        ));

        // 5. What is left, back where it came from, once nothing is in flight.
        $sweep = null;

        if ($this->option('sweep')) {
            $sweep = $counts['pending'] === 0 && $counts['expired'] === 0
                ? $this->sweep($campaign, $confirmation, (string) $this->option('sweep'))
                : ['hash' => null, 'confirmed' => false, 'error' => 'not run, because a batch had not confirmed'];
        }

        return $this->report($batches, $counts, $checked, $submittedIn, $refused, $sweep, $fees);
    }

    /**
     * Claims paying the operator's own address, made the way a claimant's are: a code of the
     * campaign with one use, and a claim against it with nothing sent yet.
     *
     * @return list<int> the claim ids
     */
    private function makeClaims(Campaign $campaign, Address $to, int $count): array
    {
        $ids = [];
        $stake = $to instanceof BaseAddress ? $to->rewardAddress()->toBech32() : '';

        for ($n = 0; $n < $count; $n++) {
            $code = Code::create([
                'campaign_id' => $campaign->id,
                'code' => Code::generateUniqueCode($campaign->id),
                'perWallet' => 1,
                'uses' => 1,
                'lovelace' => (int) $this->option('lovelace'),
            ]);

            $ids[] = Claim::create([
                'code_id' => $code->id,
                'address' => $to->toBech32(),
                'stake_key' => $stake,
            ])->id;
        }

        return $ids;
    }

    /**
     * @param  list<string>  $hashes
     * @return array{confirmed: int, expired: int, pending: int}
     */
    private function awaitConfirmation(Campaign $campaign, ChainConfirmation $confirmation, array $hashes): array
    {
        $counts = ['confirmed' => 0, 'expired' => 0, 'pending' => count($hashes)];

        if ($hashes === []) {
            return $counts;
        }

        $this->newLine();
        $this->line(sprintf('Waiting for each to reach a depth of %d block(s).', max(1, (int) config('campaign_script.confirmation_depth', 3))));

        $deadline = microtime(true) + (int) $this->option('wait');

        while (true) {
            try {
                $confirmation->reconcile($campaign, $campaign->network);
            } catch (Throwable $e) {
                $this->warn('A status read failed, which is not the same as a transaction failing: '.$e->getMessage());
            }

            $rows = DB::table('campaign_transactions')->whereIn('tx_hash', $hashes)->get()->keyBy('tx_hash');
            $counts = ['confirmed' => 0, 'expired' => 0, 'pending' => 0];

            foreach ($hashes as $hash) {
                $row = $rows[$hash] ?? null;

                if ($row?->confirmed_at !== null) {
                    $counts['confirmed']++;
                    $this->confirmedAfter[$hash] ??= microtime(true) - $this->submittedAt[$hash];
                } elseif ($row?->failed_at !== null) {
                    $counts['expired']++;
                } else {
                    $counts['pending']++;
                }
            }

            $this->line(sprintf('  %d confirmed, %d pending, %d expired', $counts['confirmed'], $counts['pending'], $counts['expired']));

            if ($counts['pending'] === 0 || microtime(true) >= $deadline) {
                return $counts;
            }

            sleep(max(1, (int) $this->option('poll')));
        }
    }

    /**
     * @return array{hash: ?string, confirmed: bool, error: ?string}
     */
    private function sweep(Campaign $campaign, ChainConfirmation $confirmation, string $destination): array
    {
        $this->newLine();
        $this->line('Returning what is left to '.$destination.'.');

        $before = DB::table('campaign_transactions')->where('campaign_id', $campaign->id)->pluck('tx_hash')->all();
        $submittedAt = microtime(true);

        try {
            AppServiceProvider::resolveBackend('native')->refund($campaign->id, $destination, $campaign->network);
        } catch (Throwable $e) {
            $this->error('The sweep was refused: '.$e->getMessage());

            return ['hash' => null, 'confirmed' => false, 'error' => 'refused: '.$e->getMessage()];
        }

        $hash = DB::table('campaign_transactions')
            ->where('campaign_id', $campaign->id)
            ->whereNotIn('tx_hash', $before)
            ->value('tx_hash');

        $this->submittedAt[$hash] = $submittedAt;
        $this->info('Sweep submitted: '.$hash);

        $counts = $this->awaitConfirmation($campaign, $confirmation, [$hash]);

        return ['hash' => $hash, 'confirmed' => $counts['confirmed'] === 1, 'error' => null];
    }

    /**
     * @param  list<array{batch: int, hash: string, chained: bool}>  $batches
     */
    private function report(array $batches, array $counts, array $checked, float $submittedIn, ?array $refused, ?array $sweep, ?array $fees = null): int
    {
        $children = count($batches) > 0 ? count($batches) - 1 : 0;
        $chained = count(array_filter($batches, static fn (array $batch): bool => $batch['chained']));
        $inputsGone = $refused !== null && $refused['batch'] > 1 && $refused['error'] instanceof ChainInputsConsumed;
        $times = array_values(array_map(static fn (float $seconds): int => (int) round($seconds), $this->confirmedAfter));
        sort($times);

        $this->newLine();
        $this->info('What this run measured, which no local test can answer:');
        $this->table(['Measurement', 'Result'], [
            ['Batches submitted without waiting', count($batches).' of '.(int) $this->option('chain')],
            ['Seconds to submit them', (string) $submittedIn],
            ['Batches spending an unconfirmed parent\'s change', $chained.' of '.$children],
            ['Endpoint stickiness', $inputsGone ? 'FAILED: batch '.$refused['batch'].' reached a node without its parent' : ($chained > 0 ? 'held for '.$chained.' chained batch(es)' : 'not exercised')],
            ['Confirmed / pending / expired', $counts['confirmed'].' / '.$counts['pending'].' / '.$counts['expired']],
            ['Seconds to confirmation (min / median / max)', $times === [] ? 'none confirmed' : sprintf('%d / %d / %d', $times[0], $times[intdiv(count($times), 2)], $times[count($times) - 1])],
            ['Claims completed by the status check', $checked['completed'].' of '.$checked['checked']],
            ['Sweep', $sweep === null ? 'not asked for' : ($sweep['error'] ?? ($sweep['confirmed'] ? 'confirmed '.$sweep['hash'] : 'submitted, not confirmed by the deadline'))],
            ...($fees === null ? [] : array_map(static fn (array $row): array => [$row['label'], $row['result']], $fees['rows'])),
        ]);

        if ($counts['expired'] > 0) {
            $this->error('Something expired without being accepted. That is the case worth understanding before mainnet.');
        }

        if ($fees !== null && ! $fees['ok']) {
            $this->error('The platform fee did not behave as it must. Each failing row above says which part, and nothing should go to mainnet until it passes.');
        }

        if ($counts['pending'] > 0) {
            $this->warn('Still pending at the deadline. Nothing is lost: the scheduled pass carries on from here, and a longer --wait measures it.');
        }

        $failed = $refused !== null
            || $counts['expired'] > 0
            || $counts['pending'] > 0
            || $chained < $children
            || $checked['completed'] < $checked['checked']
            || ($sweep !== null && ! $sweep['confirmed'])
            || ($fees !== null && ! $fees['ok']);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * One payout after everything has confirmed, so its fees input is a confirmed output and not an
     * unconfirmed parent's.
     *
     * @return ?array{hash: string, confirmed: bool}
     */
    private function topUp(Campaign $campaign, Address $to, ChainConfirmation $confirmation): ?array
    {
        $claims = $this->makeClaims($campaign, $to, max(1, (int) $this->option('claims')));
        $submittedAt = microtime(true);

        try {
            (new ProcessClaims($campaign->id))->handle();
        } catch (Throwable $e) {
            $this->error('The payout after confirmation was refused: '.$e->getMessage());

            return null;
        }

        $hashes = Claim::whereIn('id', $claims)->pluck('transaction_id')->filter()->unique()->values()->all();

        if (count($hashes) !== 1) {
            $this->error('The payout after confirmation was not paid in one transaction.');

            return null;
        }

        $hash = $hashes[0];
        $this->submittedAt[$hash] = $submittedAt;
        $this->info('Payout after confirmation submitted: '.$hash);

        $counts = $this->awaitConfirmation($campaign, $confirmation, [$hash]);

        return ['hash' => $hash, 'confirmed' => $counts['confirmed'] === 1];
    }

    /**
     * What the fees output did, read from the campaign's own records and from the chain, with the
     * pass or fail of each part.
     *
     * @param  list<array{batch: int, hash: string, chained: bool}>  $batches
     * @param  ?array{hash: string, confirmed: bool}  $topUp
     * @return array{ok: bool, rows: list<array{label: string, result: string}>}
     */
    private function feesMeasurements(Campaign $campaign, ChainData $chain, array $batches, ?array $topUp, int $paidBefore): array
    {
        $path = Pricing::pathFor($campaign);
        $feeAddress = app(PlatformFeeKey::class)->address($campaign->network);
        $rows = [];
        $ok = true;
        $note = static function (bool $pass, string $text) use (&$ok): string {
            $ok = $ok && $pass;

            return ($pass ? 'ok: ' : 'FAILED: ').$text;
        };

        $records = [];

        foreach (array_column($batches, 'hash') as $hash) {
            $records[$hash] = $this->feesRecord($hash);
        }

        $topUpRecord = $topUp === null ? null : $this->feesRecord($topUp['hash']);

        if ($batches === []) {
            return ['ok' => false, 'rows' => [['label' => 'Platform fee', 'result' => $note(false, 'no batch was paid, so there was nothing to read')]]];
        }

        // 1. The first payout creates the fees output holding the tier-1 fee for each claim, or,
        //    where the campaign was paid before, tops up the one it has.
        $first = $records[$batches[0]['hash']];
        $expected = (int) Pricing::marginalTotal($path, $paidBefore, $first['claims']);

        if ($paidBefore === 0) {
            $rows[] = ['label' => 'First payout creates the fees output', 'result' => $note(
                $first['input'] === null && $first['added'] === $expected && $first['held'] === $first['added'],
                sprintf('%s#%d holds %s lovelace, the tier-1 fee for %d claim(s)', substr($batches[0]['hash'], 0, 12), $first['index'], number_format($first['held']), $first['claims']),
            )];
        } else {
            $rows[] = ['label' => 'First payout and the fees output', 'result' => $note(
                $first['added'] === $expected && $first['held'] === $first['previous'] + $first['added'],
                sprintf('the campaign had paid %d claims already, so this payout added %s lovelace to the output it had', $paidBefore, number_format($first['added'])),
            )];
        }

        // 2. A chained payout spends its parent's fees output before the parent is confirmed.
        $carried = 0;
        $children = max(0, count($batches) - 1);

        for ($i = 1; $i < count($batches); $i++) {
            $parent = $records[$batches[$i - 1]['hash']];
            $child = $records[$batches[$i]['hash']];

            if ($child['input'] === $batches[$i - 1]['hash'].'#'.$parent['index'] && $child['held'] === $parent['held'] + $child['added']) {
                $carried++;
            }
        }

        $rows[] = ['label' => 'Chained payouts carrying the fees output', 'result' => $note($carried === $children, $carried.' of '.$children)];

        // 3. A payout after confirmation spends the confirmed fees output and adds to it.
        if ($topUp === null) {
            $rows[] = ['label' => 'Payout after confirmation tops it up', 'result' => $note(false, 'no such payout was made')];
        } else {
            $last = $records[$batches[count($batches) - 1]['hash']];
            $topped = $topUpRecord['input'] === $batches[count($batches) - 1]['hash'].'#'.$last['index']
                && $topUpRecord['held'] === $last['held'] + $topUpRecord['added'];

            $rows[] = ['label' => 'Payout after confirmation tops it up', 'result' => $note(
                $topped && $topUp['confirmed'],
                sprintf('%s lovelace after adding %s%s', number_format($topUpRecord['held']), number_format($topUpRecord['added']), $topUp['confirmed'] ? '' : ', not confirmed by the deadline'),
            )];
        }

        // 4. The fee address holds exactly the newest fees output, and it is the only one of this
        //    campaign's.
        $newest = $topUpRecord ?? $records[$batches[count($batches) - 1]['hash']];
        $newestHash = $topUp['hash'] ?? $batches[count($batches) - 1]['hash'];

        try {
            $onChain = collect($chain->utxos($campaign->network, $feeAddress))
                ->first(static fn ($utxo): bool => $utxo->txHash === $newestHash && $utxo->outputIndex === $newest['index']);

            $rows[] = ['label' => 'Fees output on the chain', 'result' => $note(
                $onChain !== null && (int) $onChain->value->lovelace === $newest['held'],
                $onChain === null ? 'not at the fee address' : number_format((int) $onChain->value->lovelace).' lovelace at the fee address, as recorded',
            )];
        } catch (Throwable $e) {
            $rows[] = ['label' => 'Fees output on the chain', 'result' => $note(false, 'the fee address could not be read: '.$e->getMessage())];
        }

        // 5. The gate refuses a fees output one lovelace out, and allows the real one.
        $rows[] = ['label' => 'Tampered fees output', 'result' => $this->tamperedFeesOutput($campaign, $chain, $newestHash, $feeAddress, $note)];

        return ['ok' => $ok, 'rows' => $rows];
    }

    /**
     * What a payout's records and bytes say about its fees output.
     *
     * @return array{input: ?string, index: int, held: int, added: int, previous: int, claims: int}
     */
    private function feesRecord(string $hash): array
    {
        $row = DB::table('campaign_transactions')->where('tx_hash', $hash)->first();
        $index = (int) $row->fees_output_index;
        $claims = DB::table('campaign_transaction_claims')->where('tx_hash', $hash)->whereNotNull('fee_lovelace');
        $added = (int) $claims->sum('fee_lovelace');
        $held = TransactionDecoder::decode(ChainRetention::signedBytes($row))->body->outputs()[$index]->value->coin->toInt();

        return [
            'input' => $row->fees_input,
            'index' => $index,
            'held' => $held,
            'added' => $added,
            'previous' => $held - $added,
            'claims' => $claims->count(),
        ];
    }

    /**
     * Run the real payout through the gate with the fees expectations the campaign recorded, which
     * it must allow, and again with the fees output made one lovelace short and the change one
     * lovelace more so value still balances, which it must refuse.
     *
     * @param  callable(bool, string): string  $note
     */
    private function tamperedFeesOutput(Campaign $campaign, ChainData $chain, string $hash, string $feeAddress, callable $note): string
    {
        try {
            $row = DB::table('campaign_transactions')->where('tx_hash', $hash)->first();
            $record = $this->feesRecord($hash);
            $transaction = TransactionDecoder::decode(ChainRetention::signedBytes($row));
            $network = $campaign->network === 'mainnet' ? Network::Mainnet : Network::Testnet;
            $gate = new InvariantGate(LedgerParameters::fromArray((array) $chain->parameters($campaign->network)), $network);
            $wallet = $campaign->wallet()->firstOrFail();
            $expiry = (int) $wallet->recovery_record['expiry_slot'];
            $payments = DB::table('campaign_transaction_claims')->where('tx_hash', $hash)->count();

            $fees = [
                'address' => $feeAddress,
                'input' => $record['input'],
                'previous' => $record['previous'],
                'paid_before' => (int) DB::table('campaign_transaction_claims')->where('tx_hash', $hash)->min('ordinal') - 1,
                'path' => Pricing::pathFor($campaign),
            ];

            // The inputs are spent by now, so their value is the one thing that balances this
            // transaction: everything it pays out plus its fee.
            $total = $transaction->body->fee()->toInt() + array_sum(array_map(
                static fn (TransactionOutput $output): int => $output->value->coin->toInt(),
                $transaction->body->outputs(),
            ));
            $inputs = [['coin' => $total]];

            $scripts = [
                CampaignScript::fromRecord($wallet->recovery_record)->scriptHashHex(),
                ...($record['input'] === null ? [] : [app(PlatformFeeKey::class)->script()->hashHex()]),
            ];

            $gate->assert($transaction->encode(), $inputs, $wallet->address, $expiry, $transaction->hashHex(), $payments, $fees, $scripts);

            $outputs = $transaction->body->outputs();
            $last = count($outputs) - 1;
            $outputs[$record['index']] = TransactionOutput::create($outputs[$record['index']]->address, Value::of($record['held'] - 1));
            $outputs[$last] = TransactionOutput::create($outputs[$last]->address, Value::of($outputs[$last]->value->coin->toInt() + 1));

            $body = TransactionBodyBuilder::create()
                ->inputs($transaction->body->inputs())
                ->outputs($outputs)
                ->fee($transaction->body->fee()->toInt())
                ->ttl($transaction->body->ttl()->toInt())
                ->networkId($transaction->body->networkId()->toInt());
            $tampered = Transaction::assemble(
                ($transaction->auxiliaryData === null ? $body : $body->auxiliaryData($transaction->auxiliaryData))->build(),
                $transaction->witnessSet,
                $transaction->auxiliaryData,
            );

            try {
                $gate->assert($tampered->encode(), $inputs, $wallet->address, $expiry, $tampered->hashHex(), $payments, $fees, $scripts);
            } catch (FeeInvariantFailed $refusal) {
                return $note(true, 'the gate refused it: '.$refusal->getMessage());
            } catch (\RuntimeException $other) {
                return $note(false, 'refused for another reason, so the fees invariant was not shown: '.$other->getMessage());
            }

            return $note(false, 'the gate let a fees output one lovelace short through');
        } catch (Throwable $e) {
            return $note(false, 'could not be exercised: '.$e->getMessage());
        }
    }

    /**
     * Whether one transaction spends an output of another, read from its own stored bytes.
     */
    private function spendsOutputOf(string $child, string $parent): bool
    {
        $row = DB::table('campaign_transactions')->where('tx_hash', $child)->first();

        foreach (TransactionDecoder::decode(ChainRetention::signedBytes($row))->body->inputs() as $input) {
            if ($input->transactionIdHex() === $parent) {
                return true;
            }
        }

        return false;
    }

    private function testnetAddress(string $bech32): ?Address
    {
        try {
            $address = Address::fromBech32($bech32);
        } catch (Throwable) {
            return null;
        }

        return $address->hrp() === 'addr_test' ? $address : null;
    }
}

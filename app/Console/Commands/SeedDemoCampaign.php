<?php

namespace App\Console\Commands;

use App\Jobs\CreateCampaignBucket;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\Reward;
use App\Models\User;
use App\Services\CreditLedger;
use App\Support\Pricing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed a realistic, large-scale campaign whose claims are spread over time, so the
 * campaign performance charts (claims-over-time sparkline, claimed-vs-unclaimed, code
 * utilization) and per-code reward details can be QA'd against life-like data.
 *
 * Local/dev/QA tool — refuses to run in production.
 *
 * Example:
 *   php artisan demo:campaign --codes=250 --claims=3000 --days=90 --network=preprod
 */
class SeedDemoCampaign extends Command
{
    protected $signature = 'demo:campaign
        {--user= : Owner email (created if missing; otherwise the first user)}
        {--codes=200 : Number of codes to generate}
        {--claims=2000 : Approximate number of claims to distribute over time}
        {--days=90 : Days of history to spread claims across (campaign start = now - days)}
        {--network=preprod : preprod|mainnet}
        {--ended : Close the redemption window yesterday, so panels that wait for a campaign to finish have something to show}
        {--nfts=25 : Codes carrying a serial of their own under one policy, so the asset grouping and its export have something to collapse}
        {--credits=0 : Grant the owner this many credits, so the ledger and the balance have something in them}
        {--held=0 : Leave this many claims held for credit, so the holds and the operator alert have something to show}
        {--spend-limit= : Cap the campaign at this many claims}
        {--fresh : Delete previously-seeded demo campaigns for this user first}';

    protected $description = 'Seed a demo campaign that exercises every panel, so a database wipe costs one command rather than an evening';

    // Real, registry-listed tokens per network so reward details resolve readable
    // names + decimals (via the synced known_assets table / Koios). 'unit' scales the
    // quantity into realistic base units for the token's decimals.
    private array $tokenSets = [
        'mainnet' => [
            'HOSKY' => ['policy' => 'a0028f350aaabe0545fdcb56b039bfb08e4bb4d8c4d7c3c7d481c235', 'asset' => '484f534b59', 'unit' => 1000, 'max' => 100],       // 0 decimals
            'USDM' => ['policy' => 'c48cbb3d5e57ed56e276bc45f99ab39abe94e6cd7ac39fb402da47ad', 'asset' => '0014df105553444d', 'unit' => 1_000_000, 'max' => 25], // 6 decimals
        ],
        'preprod' => [
            'tDRIP' => ['policy' => '698a6ea0ca99f315034072af31eaac6ec11fe8558d3f48e9775aab9d', 'asset' => '7444524950', 'unit' => 1_000_000, 'max' => 100],       // 6 decimals
            'tUSDM' => ['policy' => '16a55b2a349361ff88c03788f93e1e966e5d689605d044fef722ddde', 'asset' => '0014df10745553444d', 'unit' => 1_000_000, 'max' => 25], // 6 decimals
        ],
    ];

    // Active token set for the run (assigned in handle() from the chosen network).
    private array $tokens = [];

    public function handle(): int
    {
        if ($this->getLaravel()->environment('production')) {
            $this->error('Refusing to seed demo data in production.');

            return self::FAILURE;
        }

        $codeCount = max(1, (int) $this->option('codes'));
        $claimTarget = max(0, (int) $this->option('claims'));
        $days = max(1, (int) $this->option('days'));
        $network = $this->option('network') === 'mainnet' ? 'mainnet' : 'preprod';
        $this->tokens = $this->tokenSets[$network];
        [$tokenA, $tokenB] = array_keys($this->tokens);

        $user = $this->resolveUser();

        if ($this->option('fresh')) {
            $deleted = Campaign::where('user_id', $user->id)->where('name', 'like', 'Demo Campaign %')->get();
            foreach ($deleted as $c) {
                // Clear child rows before the codes themselves — rewards.code_id and
                // claims.code_id are constrained without ON DELETE CASCADE, so a bulk
                // codes()->delete() would otherwise FK-fail on any code carrying a reward.
                $c->codes()->each(function ($code) {
                    $code->claims()->delete();
                    $code->rewards()->delete();
                });
                $c->codes()->delete();
                $c->wallet()?->delete();
                $c->forceDelete();
            }
            $this->warn("Removed {$deleted->count()} previous demo campaign(s).");
        }

        // Build models directly (no factories) — factories depend on fakerphp/faker,
        // a dev-only package absent from the --no-dev install this runs against in production.
        $campaign = Campaign::create([
            'user_id' => $user->id,
            'name' => 'Demo Campaign '.now()->format('Y-m-d H:i:s'),
            'description' => "Seeded demo: {$codeCount} codes, ~{$claimTarget} claims over {$days} days.",
            'start_date' => now()->subDays($days)->toDateString(),
            // A demo campaign is normally left open so it reads as live. --ended closes
            // it instead, because the wallet breakdown is withheld until a campaign
            // finishes and there is otherwise no way to demonstrate it without waiting.
            'end_date' => $this->option('ended')
                ? now()->subDay()->toDateString()
                : now()->addDays(14)->toDateString(),
            'one_per_wallet' => false,
            'network' => $network,
        ]);
        // Provision a real bucket wallet via the same job the UI uses, so the demo
        // campaign shows a genuine wallet address + status chip. On staging with
        // TRANSACTION_BACKEND=phyrhose this is a real preprod Phyrhose bucket (empty of
        // funds is fine for a walkthrough); locally the null backend returns a stub.
        try {
            CreateCampaignBucket::dispatchSync($campaign->id);
            $campaign->load('wallet');
            $this->info('Provisioned bucket wallet: '.($campaign->wallet?->address ?? 'pending'));
        } catch (\Throwable $e) {
            $this->warn('Bucket provisioning failed ('.$e->getMessage().'); the campaign page will retry on view. Continuing with seed data.');
        }

        // ── Codes with varied capacity + a realistic reward mix ──────────────────
        // Every code pays ADA (lovelace). On top of that: ~50% ADA-only, ~25% + tokenA,
        // ~15% + tokenB, ~10% + BOTH — so reward details show single- and multi-asset
        // codes. Tokens are network-appropriate (mainnet: HOSKY/USDM; preprod: tDRIP/tUSDM).
        $this->info("Creating {$codeCount} codes with {$tokenA} / {$tokenB} rewards ({$network})...");
        $codes = [];
        $rewardMix = ['ada-only' => 0, $tokenA => 0, $tokenB => 0, 'both' => 0];
        $usesChoices = [1, 5, 10, 25, 50];
        foreach (range(1, $codeCount) as $i) {
            $uses = $usesChoices[array_rand($usesChoices)];
            $code = Code::create([
                'campaign_id' => $campaign->id,
                'code' => Code::generateUniqueCode($campaign->id),
                'uses' => $uses,
                'perWallet' => 1,
                'lovelace' => [2_000_000, 5_000_000, 10_000_000][array_rand([0, 1, 2])],
            ]);

            $roll = random_int(1, 100);
            if ($roll <= 50) {
                $rewardMix['ada-only']++;
            } elseif ($roll <= 75) {
                $this->addReward($code, $tokenA);
                $rewardMix[$tokenA]++;
            } elseif ($roll <= 90) {
                $this->addReward($code, $tokenB);
                $rewardMix[$tokenB]++;
            } else {
                $this->addReward($code, $tokenA);
                $this->addReward($code, $tokenB);
                $rewardMix['both']++;
            }

            $codes[] = ['id' => $code->id, 'uses' => $uses];
        }

        $this->seedSerialPolicy($codes);

        // ── Build claim slots, leaving ~25% of codes unclaimed for bucket variety ──
        shuffle($codes);
        $claimable = array_slice($codes, 0, (int) ceil(count($codes) * 0.75));
        $slots = [];
        foreach ($claimable as $c) {
            for ($u = 0; $u < $c['uses']; $u++) {
                $slots[] = $c['id'];
            }
        }
        shuffle($slots);
        if ($claimTarget < count($slots)) {
            $slots = array_slice($slots, 0, $claimTarget); // partial fill → mix of available/exhausted
        } elseif ($claimTarget > count($slots)) {
            $this->warn('Requested claims exceed code capacity; capping at '.count($slots).' (fully exhausts the claimable codes).');
        }

        // ── Insert claims with time-spread created_at (triangular peak mid-window) ──
        $this->info('Distributing '.count($slots).' claims over '.$days.' days...');
        $start = now()->subDays($days);
        $rows = [];
        $completed = 0;
        $platformFee = Pricing::inBandCharge(Pricing::pathFor($campaign))['revenue_lovelace'];
        $networkFee = (int) config('cardano.network_fee_lovelace');
        $bar = $this->output->createProgressBar(count($slots));
        foreach ($slots as $codeId) {
            $frac = (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax()) / 2; // peak near middle
            $when = (clone $start)->addSeconds((int) ($frac * $days * 86400));
            $roll = random_int(1, 100);
            [$status, $txId, $txHash] = match (true) {
                $roll <= 85 => ['completed', (string) Str::uuid(), hash('sha256', Str::random())],
                $roll <= 95 => ['pending', (string) Str::uuid(), null],
                default => ['failed', null, null],
            };
            if ($status === 'completed') {
                $completed++;
            }
            $rows[] = [
                'code_id' => $codeId,
                // Stamped the way a real claim stamps it, from the rates in force. A
                // claim inserted straight into the table never went through the claim
                // path, so without this the cost statement on a freshly seeded campaign
                // is empty and the panel cannot be looked at.
                'revenue_lovelace' => $platformFee,
                'network_fee_lovelace' => $networkFee,
                'address' => 'addr_test1qz'.Str::lower(Str::random(50)),
                'stake_key' => 'stake_test1uz'.Str::lower(Str::random(50)),
                'transaction_id' => $txId,
                'transaction_hash' => $txHash,
                'status' => $status,
                'retry_count' => $status === 'failed' ? 5 : 0,
                'created_at' => $when,
                'updated_at' => $when,
            ];
            if (count($rows) >= 1000) {
                Claim::insert($rows);
                $bar->advance(count($rows));
                $rows = [];
            }
        }
        if ($rows) {
            Claim::insert($rows);
            $bar->advance(count($rows));
        }
        $bar->finish();
        $this->newLine(2);

        $walletMix = $this->seedWalletSpread($campaign->id, $completed);
        $billing = $this->seedBilling($campaign, $user);

        $this->info('✔ Seeded demo campaign.');
        $this->table(['Field', 'Value'], [
            ['Campaign', $campaign->name],
            ['ID', $campaign->id],
            ['Owner', $user->email],
            ['Network', $network],
            ['Wallet', $campaign->wallet?->address ?? 'pending (retries on view)'],
            ['Backend', $campaign->wallet?->backend ?? '—'],
            ['Codes', $codeCount],
            ['Reward mix', "ADA-only {$rewardMix['ada-only']} · +{$tokenA} {$rewardMix[$tokenA]} · +{$tokenB} {$rewardMix[$tokenB]} · +BOTH {$rewardMix['both']}"],
            ['Claims', count($slots)],
            ['Wallet mix', $walletMix === [] ? '—' : collect($walletMix)->map(fn ($r) => $r['client'].' '.$r['claims'])->implode(' · ')],
            ['Window', $days.' days'],
            ['View at', url("/campaigns/{$campaign->id}")],
            ...collect($billing)->map(static fn ($value, $label) => [$label, (string) $value])->values()->all(),
        ]);

        return self::SUCCESS;
    }

    /**
     * Give some codes a serial of their own under one policy.
     *
     * This is the case the asset grouping exists for and the reason the export exists at
     * all: a drop that mints one numbered asset per recipient produces as many distinct
     * assets as there are claims, and a page listing them buries everything else on it.
     * A demo campaign without one cannot show either behaviour.
     */
    private function seedSerialPolicy(array $codes): void
    {
        $count = min((int) $this->option('nfts'), count($codes));

        if ($count < 1) {
            return;
        }

        $policy = str_repeat('7d', 28);

        $this->info("Minting {$count} serials under one policy...");

        foreach (array_slice($codes, 0, $count) as $i => $code) {
            Reward::create([
                'code_id' => $code['id'],
                'policy_hex' => $policy,
                'asset_hex' => bin2hex('DEMO'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT)),
                'quantity' => 1,
            ]);
        }
    }

    /**
     * Put credit in the owner's account, hold some claims against it, and cap the
     * campaign, so the ledger, the holds and the operator alert all have something to
     * show without waiting for a real campaign to run short.
     */
    private function seedBilling(Campaign $campaign, User $user): array
    {
        $summary = [];

        if (($credits = (int) $this->option('credits')) > 0) {
            if ($reason = Pricing::pathUnavailableReason(Pricing::pathFor($campaign))) {
                $this->warn("No credits granted: {$reason}");
            } else {
                app(CreditLedger::class)->grant($user, $credits, 'Seeded demo pack');
                $summary['Credits'] = $credits;
            }
        }

        if (($held = (int) $this->option('held')) > 0) {
            $ids = $campaign->claims()->orderByDesc('claims.created_at')->limit($held)->pluck('claims.id');
            Claim::whereIn('id', $ids)->update(['held_reason' => CreditLedger::HELD_NO_CREDIT]);
            $summary['Held claims'] = $ids->count();
        }

        if (($limit = $this->option('spend-limit')) !== null) {
            $campaign->forceFill(['spend_limit_micro' => (int) $limit * Pricing::MICRO])->save();
            $summary['Spend limit'] = $limit.' claims';
        }

        return $summary;
    }

    /**
     * Give the campaign a wallet spread, so the breakdown on the campaign page has
     * something to show without pointing a demo at a real campaign.
     *
     * Written as totals rather than per claim, because that is what the tally is: a
     * counter per campaign and client, carrying no key and no timestamp and nothing that
     * points at a claim. There is no per-claim row here to classify.
     *
     * The mix is invented. It is shaped like an event rather than split evenly, with one
     * wallet ahead, a tail behind it, and a slice that matched no rule, because a real
     * campaign always has some of the last one.
     *
     * @return list<array{campaign_id: string, client: string, claims: int}>
     */
    private function seedWalletSpread(string $campaignId, int $claims): array
    {
        if ($claims < 1) {
            return [];
        }

        $mix = ['okhttp' => 34, 'dalvik' => 27, 'axios' => 21, 'webview' => 14, 'unknown' => 4];

        $rows = [];
        $assigned = 0;
        $last = array_key_last($mix);

        foreach ($mix as $client => $percent) {
            // The last slice takes what is left, so the tally sums to the number of
            // claims rather than to whatever the rounding happened to leave.
            $count = $client === $last
                ? $claims - $assigned
                : (int) floor($claims * $percent / 100);
            $assigned += $count;

            if ($count > 0) {
                $rows[] = ['campaign_id' => $campaignId, 'client' => $client, 'claims' => $count];
            }
        }

        if ($rows !== []) {
            DB::table('campaign_claim_clients')->insert($rows);
        }

        return $rows;
    }

    private function addReward(Code $code, string $key): void
    {
        $t = $this->tokens[$key];
        Reward::create([
            'code_id' => $code->id,
            'policy_hex' => $t['policy'],
            'asset_hex' => $t['asset'],
            'quantity' => random_int(1, $t['max']) * $t['unit'],
        ]);
    }

    private function resolveUser(): User
    {
        if ($email = $this->option('user')) {
            return User::firstOrCreate(
                ['email' => $email],
                ['name' => 'Demo User', 'password' => bcrypt('password')]
            );
        }

        return User::query()->first() ?? User::create([
            'name' => 'Demo User',
            'email' => 'demo@onbd.io',
            'password' => bcrypt('password'),
        ]);
    }
}

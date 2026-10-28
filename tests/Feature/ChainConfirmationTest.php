<?php

namespace Tests\Feature;

use App\Cardano\ChainConfirmation;
use App\Cardano\ChainData;
use App\Cardano\ChainRetention;
use App\Cardano\ChainStatusReader;
use App\Cardano\ChainSubmitter;
use App\Models\Campaign;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

/**
 * Reading what the chain did with a campaign's transactions.
 */
class ChainConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private const TIP = 150_000_000;

    private function campaign(): Campaign
    {
        return Campaign::factory()->create(['network' => 'preprod']);
    }

    /**
     * A confirmation service whose chain answers exactly what a test says, with no network.
     *
     * @param  array<string, ?int>  $depths  hash to confirmations, or null for one the chain lacks
     */
    private function confirmation(array $depths, int $tip = self::TIP): ChainConfirmation
    {
        $chain = new class($tip) extends ChainData
        {
            public function __construct(private readonly int $tip) {}

            public function tip(string $network): array
            {
                return ['slot' => $this->tip, 'time' => 1_800_000_000];
            }
        };

        // A reader that answers what the test says, injected rather than subclassed. The answers
        // worth testing are the ones a real chain will not produce on demand: a transaction it has
        // never seen, one sitting at zero, and one buried but past its validity.
        $reader = new class($depths) extends ChainStatusReader
        {
            public function __construct(private readonly array $answers) {}

            public function depths(string $network, array $hashes): array
            {
                return array_filter(
                    array_intersect_key($this->answers, array_flip($hashes)),
                    static fn ($depth): bool => $depth !== null,
                );
            }
        };

        // Records what is offered to the chain, and takes it.
        $submissions = &$this->submissions;
        $http = new class($submissions) implements ClientInterface
        {
            public function __construct(private array &$submissions) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->submissions[] = (string) $request->getBody();

                return (new HttpFactory)->createResponse(202)->withBody((new HttpFactory)->createStream('"ok"'));
            }
        };

        return new ChainConfirmation($chain, new ChainRetention, $reader, new ChainSubmitter($http, new HttpFactory, new HttpFactory));
    }

    /** @var list<string> the bodies offered to the chain's submit endpoint */
    private array $submissions = [];

    private function record(Campaign $campaign, string $hash, int $validityEnd, int $sequence = 1): void
    {
        DB::table('campaign_transactions')->insert([
            'campaign_id' => $campaign->id,
            'sequence' => $sequence,
            'tx_hash' => $hash,
            'signed_cbor' => bin2hex('bytes'),
            'inputs_consumed' => '[]',
            'validity_end' => $validityEnd,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Deep enough is settled.
     */
    public function test_a_transaction_buried_deep_enough_is_confirmed(): void
    {
        config(['campaign_script.confirmation_depth' => 3]);

        $campaign = $this->campaign();
        $this->record($campaign, str_repeat('a', 64), self::TIP + 1000);

        $counts = $this->confirmation([str_repeat('a', 64) => 5])->reconcile($campaign, 'preprod');

        $this->assertSame(1, $counts['confirmed']);
        $this->assertNotNull(DB::table('campaign_transactions')->where('tx_hash', str_repeat('a', 64))->value('confirmed_at'));
    }

    /**
     * Accepted but not yet buried is not settled.
     *
     * A transaction in the newest block can still be rolled back, and a campaign's transactions are
     * a chain, so a rollback takes what was built on top of it too.
     */
    public function test_a_transaction_not_yet_deep_enough_stays_pending(): void
    {
        config(['campaign_script.confirmation_depth' => 3]);

        $campaign = $this->campaign();
        $this->record($campaign, str_repeat('a', 64), self::TIP + 1000);

        $counts = $this->confirmation([str_repeat('a', 64) => 1])->reconcile($campaign, 'preprod');

        $this->assertSame(0, $counts['confirmed']);
        $this->assertSame(1, $counts['pending']);
    }

    /**
     * Past its validity and unseen is expired, and provably so.
     */
    public function test_a_transaction_past_its_validity_that_the_chain_never_saw_is_expired(): void
    {
        $campaign = $this->campaign();
        $this->record($campaign, str_repeat('a', 64), self::TIP - 1);

        $counts = $this->confirmation([str_repeat('a', 64) => null])->reconcile($campaign, 'preprod');

        $this->assertSame(1, $counts['expired']);
        $this->assertSame(0, $counts['confirmed']);

        // Recorded as finished, so it is not asked about again and nothing builds on it.
        $this->assertSame('expired', DB::table('campaign_transactions')->where('tx_hash', str_repeat('a', 64))->value('failure'));
        $this->assertSame(
            ['confirmed' => 0, 'expired' => 0, 'pending' => 0],
            $this->confirmation([])->reconcile($campaign, 'preprod'),
        );
    }

    /**
     * Unseen and still inside its validity is pending, not expired.
     *
     * The difference between a transaction that will never be accepted and one that is merely slow
     * is the whole reason each carries a validity interval. Reading silence as failure would rebuild
     * a payment that is about to land, and rebuilding is the only thing that can pay twice.
     */
    public function test_a_transaction_the_chain_has_not_seen_yet_is_not_expired(): void
    {
        $campaign = $this->campaign();
        $this->record($campaign, str_repeat('a', 64), self::TIP + 1000);

        $counts = $this->confirmation([str_repeat('a', 64) => null])->reconcile($campaign, 'preprod');

        $this->assertSame(0, $counts['expired']);
        $this->assertSame(1, $counts['pending']);
    }

    /**
     * A transaction the chain holds is confirmed even past its validity interval.
     *
     * The interval bounds when a transaction may be accepted, not how long it stays accepted.
     * Treating a deep transaction as expired because its window has passed would report a payment
     * that is on the chain as one that never happened.
     */
    public function test_a_transaction_the_chain_holds_is_confirmed_even_past_its_validity(): void
    {
        config(['campaign_script.confirmation_depth' => 3]);

        $campaign = $this->campaign();
        $this->record($campaign, str_repeat('a', 64), self::TIP - 1);

        $counts = $this->confirmation([str_repeat('a', 64) => 10])->reconcile($campaign, 'preprod');

        $this->assertSame(1, $counts['confirmed']);
        $this->assertSame(0, $counts['expired']);
    }

    /**
     * Zero confirmations is an answer and absence is not.
     *
     * Zero means accepted and sitting in the newest block. Reading a transaction the chain has never
     * seen as zero would make it look like one that had just landed.
     */
    public function test_zero_confirmations_is_accepted_and_shallow_rather_than_unseen(): void
    {
        config(['campaign_script.confirmation_depth' => 3]);

        $campaign = $this->campaign();
        // Past its validity, so if zero were read as unseen this would be called expired.
        $this->record($campaign, str_repeat('a', 64), self::TIP - 1);

        $counts = $this->confirmation([str_repeat('a', 64) => 0])->reconcile($campaign, 'preprod');

        $this->assertSame(0, $counts['expired'], 'A transaction the chain holds was reported as expired.');
        $this->assertSame(1, $counts['pending']);
    }

    /**
     * Nothing queued behind an expiry can land either, and that is said rather than assumed.
     */
    public function test_a_tail_behind_an_expiry_is_reported(): void
    {
        $campaign = $this->campaign();
        $this->record($campaign, str_repeat('a', 64), self::TIP - 1, 1);
        $this->record($campaign, str_repeat('b', 64), self::TIP + 1000, 2);

        $counts = $this->confirmation([
            str_repeat('a', 64) => null,
            str_repeat('b', 64) => null,
        ])->reconcile($campaign, 'preprod');

        $this->assertSame(1, $counts['expired']);
        $this->assertSame(1, $counts['pending']);
    }

    public function test_a_campaign_with_nothing_outstanding_reports_nothing(): void
    {
        $this->assertSame(
            ['confirmed' => 0, 'expired' => 0, 'pending' => 0],
            $this->confirmation([])->reconcile($this->campaign(), 'preprod'),
        );
    }

    /**
     * Unseen and still inside its validity is offered to the chain again, once per reconciliation,
     * and one the chain holds, or that has expired, is not.
     */
    public function test_an_unseen_transaction_inside_its_validity_is_offered_again_once(): void
    {
        $campaign = $this->campaign();
        $this->record($campaign, str_repeat('a', 64), self::TIP + 1000, 1);
        $this->record($campaign, str_repeat('b', 64), self::TIP + 1000, 2);
        $this->record($campaign, str_repeat('c', 64), self::TIP - 1, 3);

        $this->confirmation([str_repeat('a', 64) => null, str_repeat('b', 64) => 1, str_repeat('c', 64) => null])
            ->reconcile($campaign, 'preprod');

        $this->assertSame(['bytes'], $this->submissions);
    }

    /**
     * Hashes go to the provider in requests that stay under its body limit, however many a
     * campaign has open.
     */
    public function test_the_status_of_many_transactions_is_asked_in_requests_under_the_provider_limit(): void
    {
        config(['cardano.koios.preprod_url' => 'https://koios.test/api/v1/', 'cardano.koios.max_body_bytes' => 5120]);
        $bodies = [];
        $http = new class($bodies) implements ClientInterface
        {
            public function __construct(private array &$bodies) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->bodies[] = (string) $request->getBody();

                return (new HttpFactory)->createResponse(200)->withBody((new HttpFactory)->createStream('[]'));
            }
        };

        $hashes = array_map(static fn (int $n): string => hash('sha256', (string) $n), range(1, 200));
        (new ChainStatusReader($http, new HttpFactory, new HttpFactory))->depths('preprod', $hashes);

        $this->assertGreaterThan(1, count($bodies));

        foreach ($bodies as $body) {
            $this->assertLessThanOrEqual(5120, strlen($body));
        }

        $this->assertCount(200, array_merge(...array_map(static fn (string $body): array => json_decode($body, true)['_tx_hashes'], $bodies)));
    }
}

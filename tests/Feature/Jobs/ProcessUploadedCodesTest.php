<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessUploadedCodes;
use App\Models\Campaign;
use App\Models\CampaignTask;
use App\Models\Code;
use App\Models\Partner;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessUploadedCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_codes_from_valid_json(): void
    {
        Storage::fake($this->uploadDisk());

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Wallet::factory()->for($campaign)->create();

        $codesData = [
            'CODE001' => [
                'lovelaces' => 2000000,
                'abc123def456abc123def456abc123def456abc123def456abc123def456.746f6b656e' => 5,
            ],
            'CODE002' => [
                'lovelaces' => 3000000,
            ],
        ];

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode($codesData));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();

        $this->assertDatabaseCount('codes', 2);
        $this->assertDatabaseCount('rewards', 1);
    }

    public function test_rejects_oversized_file(): void
    {
        Storage::fake($this->uploadDisk());

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        // Create a file larger than the configured max
        $largeContent = str_repeat('x', config('cardano.max_file_size', 10 * 1024 * 1024) + 1);
        Storage::disk($this->uploadDisk())->put('test/large.json', $largeContent);

        (new ProcessUploadedCodes($campaign->id, 'test/large.json'))->handle();

        $this->assertDatabaseCount('codes', 0);
    }

    public function test_rejects_invalid_json_structure(): void
    {
        Storage::fake($this->uploadDisk());

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        Storage::disk($this->uploadDisk())->put('test/invalid.json', '"just a string"');

        (new ProcessUploadedCodes($campaign->id, 'test/invalid.json'))->handle();

        $this->assertDatabaseCount('codes', 0);
    }

    public function test_skips_codes_with_invalid_token_format(): void
    {
        Storage::fake($this->uploadDisk());

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();
        Wallet::factory()->for($campaign)->create();

        $codesData = [
            'CODE001' => [
                'lovelaces' => 2000000,
                'invalid_token_no_dot' => 5,
            ],
        ];

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode($codesData));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();

        $this->assertDatabaseCount('codes', 1);
        $this->assertDatabaseCount('rewards', 0);
    }

    public function test_skips_entries_missing_lovelaces(): void
    {
        Storage::fake($this->uploadDisk());

        $user = User::factory()->create();
        $campaign = Campaign::factory()->for($user)->create();

        $codesData = [
            'CODE001' => [
                'some_field' => 'no lovelaces key',
            ],
        ];

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode($codesData));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();

        $this->assertDatabaseCount('codes', 0);
    }

    public function test_reads_the_upload_from_the_configured_default_disk(): void
    {
        // The job used to hard-code the "s3" disk, which meant it read from a bucket that
        // exists neither on a host that injects its bucket under another disk name nor on a
        // self-hosted install (no AWS_* at all). It must follow the app's default disk.
        config(['filesystems.default' => 'uploads']);
        config(['filesystems.disks.uploads' => ['driver' => 'local', 'root' => storage_path('app/uploads')]]);
        Storage::fake('uploads');

        $campaign = Campaign::factory()->for(User::factory())->create();
        Storage::disk('uploads')->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
        ]));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();

        $this->assertDatabaseCount('codes', 1);
    }

    public function test_imported_codes_are_stamped_with_the_partner_the_import_was_for(): void
    {
        Storage::fake($this->uploadDisk());

        $campaign = Campaign::factory()->for(User::factory())->create();
        $partner = Partner::factory()->for($campaign)->create();

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
            'CODE002' => ['lovelaces' => 2000000],
        ]));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json', partner_id: $partner->id))->handle();

        $this->assertSame(2, Code::where('partner_id', $partner->id)->count());
    }

    /**
     * The job carries whatever it was dispatched with and runs later somewhere else, so
     * the partner is checked again here rather than trusted.
     *
     * The import still runs. Refusing the whole file would lose every code in it over an
     * attribution the operator can put on their next batch, and writing the id anyway
     * would put another campaign's name on this campaign's codes.
     */
    public function test_a_partner_from_another_campaign_is_dropped_and_the_import_still_runs(): void
    {
        Storage::fake($this->uploadDisk());

        $campaign = Campaign::factory()->for(User::factory())->create();
        $theirs = Partner::factory()->for(Campaign::factory()->for(User::factory()))->create();

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
        ]));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json', partner_id: $theirs->id))->handle();

        $this->assertDatabaseCount('codes', 1);
        $this->assertNull(Code::first()->partner_id);
        $this->assertSame(0, Code::where('partner_id', $theirs->id)->count());
    }

    /**
     * Deleting a partner between starting an import and the worker picking it up does
     * not change who the codes were handed to, and a code pointing at a soft-deleted
     * partner is the ordinary state this schema is built for.
     */
    public function test_a_partner_deleted_mid_import_still_stamps_the_codes(): void
    {
        Storage::fake($this->uploadDisk());

        $campaign = Campaign::factory()->for(User::factory())->create();
        $partner = Partner::factory()->for($campaign)->create();

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
        ]));

        $partner->delete();

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json', partner_id: $partner->id))->handle();

        $this->assertSame($partner->id, Code::first()->partner_id);
    }

    public function test_an_import_with_no_partner_leaves_the_codes_unassigned(): void
    {
        Storage::fake($this->uploadDisk());

        $campaign = Campaign::factory()->for(User::factory())->create();

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
        ]));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();

        $this->assertNull(Code::first()->partner_id);
    }

    /**
     * The same cap the campaign page's own form and the code-creation API enforce, checked
     * one row at a time rather than once against the whole file: a file that would push
     * the campaign over it imports up to the cap and stops there, rather than being
     * refused whole or, the older bug this replaced, over-counting a re-run and refusing
     * rows that would not actually have consumed any capacity.
     */
    /**
     * Hitting the cap mid-file fails the run rather than completing it quietly. The
     * campaign page has nowhere it shows a completed run's result, only a failed one's
     * message, so a cap that stops an import partway through has to surface there or an
     * operator has no way to learn about it short of counting codes themselves.
     */
    public function test_a_file_that_would_exceed_the_campaigns_code_cap_stops_at_it(): void
    {
        Storage::fake($this->uploadDisk());

        $campaign = Campaign::factory()->for(User::factory())->create(['max_codes' => 1]);
        Wallet::factory()->for($campaign)->create();

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
            'CODE002' => ['lovelaces' => 2000000],
        ]));

        $task = CampaignTask::claim($campaign, ProcessUploadedCodes::TASK_TYPE, 'over-cap');

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json', task_id: $task->id))->handle();

        $this->assertDatabaseCount('codes', 1);
        $this->assertDatabaseHas('codes', ['code' => 'CODE001']);

        $task->refresh();
        $this->assertSame(CampaignTask::STATUS_FAILED, $task->status);
        $this->assertStringContainsString('1', $task->error);
        $this->assertStringContainsString('capped at 1', $task->error);
    }

    /**
     * Two imports for the same campaign, each individually under the cap, but together
     * over it: the second import's rows are reserved one at a time under the same lock
     * the first import's rows were, so the two together still land exactly on the cap
     * rather than past it.
     */
    public function test_two_imports_racing_near_the_cap_do_not_exceed_it_together(): void
    {
        Storage::fake($this->uploadDisk());

        $campaign = Campaign::factory()->for(User::factory())->create(['max_codes' => 3]);
        Wallet::factory()->for($campaign)->create();

        Storage::disk($this->uploadDisk())->put('test/first.json', json_encode([
            'FIRST01' => ['lovelaces' => 2000000],
            'FIRST02' => ['lovelaces' => 2000000],
        ]));
        Storage::disk($this->uploadDisk())->put('test/second.json', json_encode([
            'SECOND01' => ['lovelaces' => 2000000],
            'SECOND02' => ['lovelaces' => 2000000],
        ]));

        (new ProcessUploadedCodes($campaign->id, 'test/first.json'))->handle();
        (new ProcessUploadedCodes($campaign->id, 'test/second.json'))->handle();

        $this->assertDatabaseCount('codes', 3);
        $this->assertDatabaseHas('codes', ['code' => 'FIRST01']);
        $this->assertDatabaseHas('codes', ['code' => 'FIRST02']);
        $this->assertDatabaseHas('codes', ['code' => 'SECOND01']);
        $this->assertDatabaseMissing('codes', ['code' => 'SECOND02']);
    }

    /**
     * A file already fully imported, re-run against a campaign now sitting exactly at its
     * cap. Every row is a duplicate of one already there, so nothing new is inserted and
     * nothing is refused for lack of room: the old upfront-count bug would have measured
     * this run's would-be total against the cap and refused the whole re-run, even though
     * it was about to insert zero rows.
     *
     * The run is given a real task row so its final status is the thing this actually
     * asserts on. A row count of 2 alone would read the same whether the re-run correctly
     * inserted nothing, or was refused outright the old way and inserted nothing for a
     * completely different reason; the task's own status is what tells those apart.
     */
    public function test_a_rerun_near_the_cap_inserts_nothing_and_is_not_refused(): void
    {
        Storage::fake($this->uploadDisk());

        $campaign = Campaign::factory()->for(User::factory())->create(['max_codes' => 2]);
        Wallet::factory()->for($campaign)->create();

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
            'CODE002' => ['lovelaces' => 2000000],
        ]));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();
        $this->assertDatabaseCount('codes', 2);

        $task = CampaignTask::claim($campaign, ProcessUploadedCodes::TASK_TYPE, 'rerun');

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json', task_id: $task->id))->handle();

        $this->assertDatabaseCount('codes', 2);
        $this->assertSame(CampaignTask::STATUS_COMPLETE, $task->fresh()->status);
        $this->assertSame(2, $task->fresh()->result['skipped'] ?? null);
        $this->assertSame(0, $task->fresh()->result['codes'] ?? null);
    }

    public function test_a_file_that_fits_under_the_campaigns_code_cap_is_imported(): void
    {
        Storage::fake($this->uploadDisk());

        $campaign = Campaign::factory()->for(User::factory())->create(['max_codes' => 5]);
        Wallet::factory()->for($campaign)->create();

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
            'CODE002' => ['lovelaces' => 2000000],
        ]));

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();

        $this->assertDatabaseCount('codes', 2);
    }

    /**
     * An uncapped campaign has nothing for CodeCapacity to lock or count, so an import
     * against one should never query the campaigns table beyond the one lookup the job
     * itself makes at the top of handle() to load the campaign it is importing into.
     *
     * Counted by watching every query fired during the import rather than by asserting on
     * SQL text: SQLite compiles lockForUpdate() away to nothing, so a query naming the
     * lock explicitly would pass here whether or not the lock was ever asked for. A second
     * query against campaigns, by id, only has one source in this code path — the campaign
     * row lock this import should never take — so counting those queries is what actually
     * proves it.
     */
    public function test_an_uncapped_import_never_queries_the_campaigns_table_again(): void
    {
        Storage::fake($this->uploadDisk());

        $campaign = Campaign::factory()->for(User::factory())->create(['max_codes' => null]);
        Wallet::factory()->for($campaign)->create();

        Storage::disk($this->uploadDisk())->put('test/codes.json', json_encode([
            'CODE001' => ['lovelaces' => 2000000],
            'CODE002' => ['lovelaces' => 2000000],
            'CODE003' => ['lovelaces' => 2000000],
            'CODE004' => ['lovelaces' => 2000000],
            'CODE005' => ['lovelaces' => 2000000],
        ]));

        $campaignQueries = 0;
        DB::listen(function ($query) use (&$campaignQueries) {
            if (str_contains($query->sql, 'campaigns')) {
                $campaignQueries++;
            }
        });

        (new ProcessUploadedCodes($campaign->id, 'test/codes.json'))->handle();

        $this->assertDatabaseCount('codes', 5);

        // Exactly one: the job's own Campaign::find() at the top of handle(). Every row
        // this import wrote took the fast, unlocked path, whatever the running count said,
        // because there was never a cap for it to approach.
        $this->assertSame(1, $campaignQueries);
    }

    /** The disk the signed upload wrote to — the app default, never a hard-coded name. */
    private function uploadDisk(): string
    {
        return config('filesystems.default');
    }
}

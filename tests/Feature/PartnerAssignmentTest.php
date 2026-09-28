<?php

namespace Tests\Feature;

use App\Jobs\ProcessUploadedCodes;
use App\Models\Campaign;
use App\Models\Claim;
use App\Models\Code;
use App\Models\Partner;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Who a batch of codes was handed to, recorded when the batch is generated.
 *
 * The attribution is only worth having if it cannot be edited afterwards and cannot be
 * pointed at another tenant's partner, so both of those are what most of these tests are
 * about.
 */
class PartnerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->campaign = Campaign::factory()->for($this->user)->create([
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]);
        Wallet::factory()->for($this->campaign)->create();
    }

    /** @param  array<string, mixed>  $payload */
    private function createCodes(array $payload = [])
    {
        return $this->actingAs($this->user)->post(route('codes.store'), array_merge([
            'campaign_id' => $this->campaign->id,
            'lovelace' => 2000000,
            'perWallet' => 1,
            'uses' => 1,
        ], $payload));
    }

    public function test_a_batch_is_stamped_with_the_partner_it_was_generated_for(): void
    {
        $partner = Partner::factory()->for($this->campaign)->create(['name' => 'Vendor A']);

        $this->createCodes(['quantity' => 3, 'partner_id' => $partner->id])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(3, Code::where('partner_id', $partner->id)->count());
        // Picking a partner that exists must not quietly create a second one.
        $this->assertSame(1, Partner::where('campaign_id', $this->campaign->id)->count());
    }

    /**
     * Unassigned is a choice, not a missing value. It is stored as null and the request
     * succeeds, because a batch generated before anybody decided who is handing it out is
     * the ordinary case on every campaign that predates partners.
     */
    public function test_unassigned_is_accepted_and_stored_as_null(): void
    {
        $this->createCodes()->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('codes', 1);
        $this->assertNull(Code::first()->partner_id);
    }

    /**
     * The scope on the exists rule is the whole of the tenant boundary here. Without it this
     * endpoint answers differently for an id that exists and one that does not, which turns
     * a form that writes this campaign's codes into a way to find out which partner ids are
     * real in somebody else's account.
     */
    public function test_a_partner_from_another_campaign_is_refused(): void
    {
        $other = Campaign::factory()->for(User::factory())->create();
        $theirs = Partner::factory()->for($other)->create(['name' => 'Their Vendor']);

        $this->createCodes(['partner_id' => $theirs->id])
            ->assertSessionHasErrors(['partner_id']);

        $this->assertDatabaseCount('codes', 0);
    }

    /**
     * A made-up id and a real id belonging to another campaign have to fail the same way.
     * If they did not, the difference between the two answers would be the leak the scope
     * exists to close.
     */
    public function test_an_unknown_partner_id_fails_the_same_way_as_a_foreign_one(): void
    {
        $other = Campaign::factory()->for(User::factory())->create();
        $theirs = Partner::factory()->for($other)->create();

        $this->createCodes(['partner_id' => $theirs->id])
            ->assertSessionHasErrors(['partner_id']);
        $foreignErrors = session('errors')->get('partner_id');

        $this->createCodes(['partner_id' => (string) Str::ulid()])
            ->assertSessionHasErrors(['partner_id']);
        $inventedErrors = session('errors')->get('partner_id');

        $this->assertNotEmpty($foreignErrors);
        $this->assertSame($foreignErrors, $inventedErrors);
        $this->assertDatabaseCount('codes', 0);
    }

    public function test_a_soft_deleted_partner_cannot_be_assigned(): void
    {
        $partner = Partner::factory()->for($this->campaign)->create();
        $partner->delete();

        $this->createCodes(['partner_id' => $partner->id])
            ->assertSessionHasErrors(['partner_id']);

        $this->assertDatabaseCount('codes', 0);
    }

    public function test_naming_a_partner_creates_it_and_stamps_the_batch(): void
    {
        $this->createCodes([
            'quantity' => 2,
            'partner_name' => 'Booth Staff',
            'partner_kind' => 'staff',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $partner = Partner::where('campaign_id', $this->campaign->id)->sole();

        $this->assertSame('Booth Staff', $partner->name);
        $this->assertSame('staff', $partner->kind);
        $this->assertSame(2, Code::where('partner_id', $partner->id)->count());
    }

    public function test_a_kind_outside_the_list_is_refused(): void
    {
        $this->createCodes([
            'partner_name' => 'Booth Staff',
            'partner_kind' => 'whatever-they-typed',
        ])->assertSessionHasErrors(['partner_kind']);

        $this->assertDatabaseCount('partners', 0);
        $this->assertDatabaseCount('codes', 0);
    }

    /**
     * The ordinary way a name clash is refused: the validation rule reads the table, finds
     * the name held, and answers on the form. Two live partners with the same name make
     * every report that groups by name wrong and give the operator two identical entries to
     * choose between.
     */
    public function test_a_second_partner_cannot_take_a_live_name(): void
    {
        Partner::factory()->for($this->campaign)->create(['name' => 'Vendor A']);

        $this->createCodes(['partner_name' => 'Vendor A'])
            ->assertSessionHasErrors(['partner_name']);

        $this->assertSame(1, Partner::where('campaign_id', $this->campaign->id)->count());
        $this->assertDatabaseCount('codes', 0);
    }

    /**
     * The column is 80 characters and SQLite does not enforce that, so the rule is the
     * only thing standing between a long name and a truncated one on MySQL.
     */
    public function test_a_name_longer_than_the_column_is_refused(): void
    {
        $this->createCodes(['partner_name' => str_repeat('a', 81)])
            ->assertSessionHasErrors(['partner_name']);

        $this->assertDatabaseCount('partners', 0);
        $this->assertDatabaseCount('codes', 0);
    }

    /**
     * The same name in another campaign is a different partner. Scoping the rule to
     * the campaign is what lets two operators each have a "Front Desk".
     */
    public function test_the_same_name_is_free_in_another_campaign(): void
    {
        $other = Campaign::factory()->for(User::factory())->create();
        Partner::factory()->for($other)->create(['name' => 'Front Desk']);

        $this->createCodes(['partner_name' => 'Front Desk'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Partner::where('campaign_id', $this->campaign->id)->count());
    }

    /**
     * Deleting a partner releases its name for a new one. This is why the unique index is
     * on live_name rather than on name: a soft-deleted row sitting in the index would hold
     * a name the operator had already removed, and the refusal would arrive as a constraint
     * violation nobody can act on.
     */
    public function test_a_name_comes_free_again_once_its_partner_is_deleted(): void
    {
        $original = Partner::factory()->for($this->campaign)->create(['name' => 'Vendor A']);
        $original->delete();

        $this->createCodes(['partner_name' => 'Vendor A'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $live = Partner::where('campaign_id', $this->campaign->id)->sole();

        $this->assertNotSame($original->id, $live->id);
        $this->assertSame(2, Partner::withTrashed()->where('campaign_id', $this->campaign->id)->count());
    }

    /**
     * A name that differs only in case is the same name.
     *
     * Two partners called "Vendor A" and "vendor a" on one campaign are a typo, not two
     * partners: the picker shows two entries nobody can tell apart and every report that
     * groups by partner splits one vendor's codes across two lines. An operator typing the
     * name again at a booth means the one already on the list.
     *
     * This is also the case the two engines answered differently. MySQL's
     * utf8mb4_unicode_ci refuses it; SQLite compares text byte for byte and used to accept
     * it, so the local suite demonstrated neither behaviour. The collation declared on
     * partners.name in the migration is what makes this test say the same thing on both.
     */
    public function test_a_name_that_differs_only_in_case_is_refused(): void
    {
        Partner::factory()->for($this->campaign)->create(['name' => 'Vendor A']);

        $this->createCodes(['partner_name' => 'vendor a'])
            ->assertSessionHasErrors(['partner_name']);

        $this->assertSame(
            'This campaign already has a partner with that name.',
            session('errors')->first('partner_name'),
        );
        $this->assertSame(1, Partner::where('campaign_id', $this->campaign->id)->count());
        $this->assertDatabaseCount('codes', 0);
    }

    /**
     * The same answer from the database, where the validation rule is not in the way.
     *
     * The rule reads the table and the index holds the guarantee, so both have to fold case
     * or the two disagree: a seeder or a console one-liner would write the row the rule
     * refuses, and the picker would have the pair of entries the rule exists to prevent.
     */
    public function test_the_database_refuses_a_live_name_that_differs_only_in_case(): void
    {
        $this->campaign->partners()->create(['name' => 'Vendor A']);

        try {
            $this->campaign->partners()->create(['name' => 'VENDOR A']);
            $this->fail('The database accepted a second live partner whose name differs only in case.');
        } catch (QueryException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }

        $this->assertSame(1, Partner::where('campaign_id', $this->campaign->id)->count());
    }

    /**
     * Folding is for comparing, not for storing. The name goes on the picker, on the codes
     * table and in the export exactly as the operator typed it.
     */
    public function test_a_name_is_stored_as_it_was_typed(): void
    {
        $this->createCodes(['partner_name' => 'McTavish AV'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('McTavish AV', Partner::where('campaign_id', $this->campaign->id)->sole()->name);
    }

    /**
     * The losing side of the race, where the two names differ only in case.
     *
     * The index refuses the insert, and what decides whether that refusal is answered as a
     * name clash or raised as a 500 is a second lookup by name. That lookup has to fold case
     * the same way the index does, or the operator who typed "vendor a" one second after
     * somebody else typed "Vendor A" gets an error page instead of a message on the form.
     */
    public function test_the_race_loser_is_told_the_name_is_taken_when_only_the_case_differs(): void
    {
        Partner::creating(function (Partner $partner) {
            DB::table('partners')->insert([
                'id' => (string) Str::ulid(),
                'campaign_id' => $partner->campaign_id,
                'name' => strtoupper((string) $partner->name),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->createCodes(['partner_name' => 'Front Desk'])
            ->assertRedirect()
            ->assertSessionHasErrors(['partner_name']);

        $this->assertSame(
            'This campaign already has a partner with that name.',
            session('errors')->first('partner_name'),
        );
        $this->assertSame(1, Partner::where('campaign_id', $this->campaign->id)->count());
        $this->assertDatabaseCount('codes', 0);
    }

    /**
     * Deleting releases the name whatever case it is typed in next. The fold applies to the
     * index, and the index only holds live rows.
     */
    public function test_a_deleted_name_is_free_again_in_any_case(): void
    {
        $original = Partner::factory()->for($this->campaign)->create(['name' => 'Vendor A']);
        $original->delete();

        $this->createCodes(['partner_name' => 'VENDOR A'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('VENDOR A', Partner::where('campaign_id', $this->campaign->id)->sole()->name);
    }

    /**
     * The fold is scoped to the campaign, like the rest of the guarantee. Another operator's
     * "vendor a" is not this campaign's "Vendor A".
     */
    public function test_a_case_variant_is_free_in_another_campaign(): void
    {
        $other = Campaign::factory()->for(User::factory())->create();
        $other->partners()->create(['name' => 'Vendor A']);

        $this->createCodes(['partner_name' => 'vendor a'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Partner::where('campaign_id', $this->campaign->id)->count());
    }

    /**
     * The guarantee the validation rule cannot give on its own.
     *
     * Two requests naming the same new partner each read the table before either of them
     * writes, so the rule passes for both: that is what the two validator runs below show,
     * against a campaign neither has written to yet. What refuses the second write is the
     * unique index, and without it the campaign ends up with two live partners of one name,
     * two identical entries on the picker, and one partner's codes split across both in
     * every report.
     */
    public function test_the_database_refuses_a_second_live_partner_with_the_same_name(): void
    {
        $validate = fn () => Validator::make(
            ['partner_name' => 'Front Desk'],
            ['partner_name' => [Partner::uniqueNameRule($this->campaign)]],
        );

        $this->assertTrue($validate()->passes());
        $this->assertTrue($validate()->passes());

        $this->campaign->partners()->create(['name' => 'Front Desk']);

        try {
            $this->campaign->partners()->create(['name' => 'Front Desk']);
            $this->fail('The database accepted a second live partner with the same name.');
        } catch (QueryException $e) {
            // 23000 is the SQL state for an integrity constraint violation on both SQLite and
            // MySQL, so this says the index refused the row rather than the driver failing
            // for some reason of its own.
            $this->assertSame('23000', (string) $e->getCode());
        }

        $this->assertSame(1, Partner::where('campaign_id', $this->campaign->id)->count());
    }

    /**
     * A deleted partner holds nothing. The name is free at the database, not only past the
     * validation rule, and any number of deleted rows can carry it: these three are created
     * and deleted within the same second, which is the case an index built on deleted_at
     * itself would refuse.
     */
    public function test_the_database_lets_a_deleted_name_be_taken_again(): void
    {
        $first = $this->campaign->partners()->create(['name' => 'Front Desk']);
        $first->delete();

        $second = $this->campaign->partners()->create(['name' => 'Front Desk']);
        $second->delete();

        $third = $this->campaign->partners()->create(['name' => 'Front Desk']);

        $this->assertSame(3, Partner::withTrashed()->where('campaign_id', $this->campaign->id)->count());
        $this->assertSame($third->id, Partner::where('campaign_id', $this->campaign->id)->sole()->id);
    }

    /**
     * The index is scoped to the campaign, like the rule, so two operators can each have a
     * "Front Desk" and neither of them finds the name taken by a campaign they cannot see.
     */
    public function test_the_database_scopes_the_name_to_one_campaign(): void
    {
        $other = Campaign::factory()->for(User::factory())->create();

        $mine = $this->campaign->partners()->create(['name' => 'Front Desk']);
        $theirs = $other->partners()->create(['name' => 'Front Desk']);

        $this->assertNotSame($mine->id, $theirs->id);
        $this->assertSame(2, Partner::whereIn('campaign_id', [$this->campaign->id, $other->id])->count());
    }

    /**
     * What the operator who loses the race actually sees.
     *
     * The other request writes the name between this one validating and inserting, which is
     * the interleaving the creating hook stages. The index refuses the insert, and the
     * refusal is answered as the rule would have answered it rather than as a 500 for a name
     * that is simply taken.
     */
    public function test_the_request_that_loses_the_race_is_told_the_name_is_taken(): void
    {
        Partner::creating(function (Partner $partner) {
            DB::table('partners')->insert([
                'id' => (string) Str::ulid(),
                'campaign_id' => $partner->campaign_id,
                'name' => $partner->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->createCodes(['partner_name' => 'Front Desk'])
            ->assertRedirect()
            ->assertSessionHasErrors(['partner_name']);

        $this->assertSame(
            'This campaign already has a partner with that name.',
            session('errors')->first('partner_name'),
        );
        // The row that won the race, and no second one.
        $this->assertSame(1, Partner::where('campaign_id', $this->campaign->id)->count());
        $this->assertDatabaseCount('codes', 0);
    }

    /**
     * Only a name clash is answered as one. A row the database refused for any other reason
     * is not something the operator can fix by typing a different name, so it is raised
     * instead of being dressed up as a message about the name.
     */
    public function test_a_refusal_that_is_not_a_name_clash_is_raised(): void
    {
        Partner::creating(function (Partner $partner) {
            // A campaign id that belongs to nothing, so the foreign key refuses the row while
            // the name it was given stays free.
            $partner->campaign_id = (string) Str::ulid();
        });

        $this->expectException(QueryException::class);

        $this->withoutExceptionHandling();
        $this->createCodes(['partner_name' => 'Front Desk']);
    }

    /**
     * The partner row is written only after the rest of the request has passed. A batch that
     * is refused for its lovelace figure must not leave a partner behind that no code was
     * ever generated for, because the operator will retype the name on their second attempt
     * and be told it is taken.
     */
    public function test_a_refused_batch_leaves_no_partner_behind(): void
    {
        $this->createCodes([
            'lovelace' => 500,
            'partner_name' => 'Vendor A',
        ])->assertSessionHasErrors(['lovelace']);

        $this->assertDatabaseCount('partners', 0);
        $this->assertDatabaseCount('codes', 0);
    }

    public function test_the_import_carries_the_partner_to_the_job(): void
    {
        Bus::fake();
        $partner = Partner::factory()->for($this->campaign)->create();

        $this->actingAs($this->user)->post(route('codes.store'), [
            'campaign_id' => $this->campaign->id,
            'uploadedCodes' => true,
            'file_key' => 'uploads/codes.json',
            'partner_id' => $partner->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        Bus::assertDispatched(
            ProcessUploadedCodes::class,
            fn (ProcessUploadedCodes $job) => $job->partner_id === $partner->id
                && $job->campaign_id === $this->campaign->id,
        );
    }

    public function test_the_import_is_refused_a_partner_from_another_campaign(): void
    {
        Bus::fake();
        $other = Campaign::factory()->for(User::factory())->create();
        $theirs = Partner::factory()->for($other)->create();

        $this->actingAs($this->user)->post(route('codes.store'), [
            'campaign_id' => $this->campaign->id,
            'uploadedCodes' => true,
            'file_key' => 'uploads/codes.json',
            'partner_id' => $theirs->id,
        ])->assertSessionHasErrors(['partner_id']);

        Bus::assertNotDispatched(ProcessUploadedCodes::class);
    }

    public function test_an_import_with_no_partner_still_dispatches(): void
    {
        Bus::fake();

        $this->actingAs($this->user)->post(route('codes.store'), [
            'campaign_id' => $this->campaign->id,
            'uploadedCodes' => true,
            'file_key' => 'uploads/codes.json',
        ])->assertRedirect()->assertSessionHasNoErrors();

        Bus::assertDispatched(
            ProcessUploadedCodes::class,
            fn (ProcessUploadedCodes $job) => $job->partner_id === null,
        );
    }

    /**
     * Soft deleting is what the operator does when a vendor is finished, and it must not
     * move the codes that vendor handed out into somebody else's column, or out of every
     * column at once.
     */
    public function test_soft_deleting_a_partner_keeps_the_codes_pointing_at_it(): void
    {
        $partner = Partner::factory()->for($this->campaign)->create();
        $code = Code::factory()->for($this->campaign)->create(['partner_id' => $partner->id]);

        $partner->delete();

        $this->assertSame($partner->id, $code->fresh()->partner_id);
        $this->assertNotNull(Partner::withTrashed()->find($partner->id));
    }

    /**
     * A hard delete is the only thing that severs the link, and it severs it rather than
     * leaving a code pointing at a row that is gone.
     */
    public function test_hard_deleting_a_partner_unassigns_its_codes(): void
    {
        $partner = Partner::factory()->for($this->campaign)->create();
        $code = Code::factory()->for($this->campaign)->create(['partner_id' => $partner->id]);

        $partner->forceDelete();

        $this->assertNull($code->fresh()->partner_id);
        $this->assertDatabaseCount('codes', 1);
    }

    public function test_the_campaign_page_offers_only_this_campaigns_live_partners(): void
    {
        $mine = Partner::factory()->for($this->campaign)->create(['name' => 'Vendor A']);
        $deleted = Partner::factory()->for($this->campaign)->create(['name' => 'Gone']);
        $deleted->delete();
        $theirs = Partner::factory()
            ->for(Campaign::factory()->for(User::factory()))
            ->create(['name' => 'Their Vendor']);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('partners', fn ($partners) => collect($partners)->pluck('id')->all() === [$mine->id])
                ->etc()
            );

        $this->assertNotNull(Partner::withTrashed()->find($deleted->id));
        $this->assertNotNull(Partner::find($theirs->id));
    }

    /**
     * Generating another batch for the same person is the repeated action at a booth, and
     * Unassigned is the one answer that cannot be corrected later, so it is not what the
     * picker should start on once there is a better answer available.
     */
    public function test_the_picker_defaults_to_the_last_partner_used(): void
    {
        $first = Partner::factory()->for($this->campaign)->create(['name' => 'Vendor A']);
        $second = Partner::factory()->for($this->campaign)->create(['name' => 'Vendor B']);

        Code::factory()->for($this->campaign)->create(['partner_id' => $first->id]);
        Code::factory()->for($this->campaign)->create(['partner_id' => $second->id]);
        Code::factory()->for($this->campaign)->create(['partner_id' => null]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('default_partner_id', $second->id)->etc());
    }

    public function test_the_default_falls_back_to_unassigned_when_the_last_partner_is_deleted(): void
    {
        $partner = Partner::factory()->for($this->campaign)->create();
        Code::factory()->for($this->campaign)->create(['partner_id' => $partner->id]);
        $partner->delete();

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('default_partner_id', null)->etc());
    }

    public function test_the_default_is_unassigned_on_a_campaign_with_no_partners(): void
    {
        Code::factory()->for($this->campaign)->create();

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('default_partner_id', null)->etc());
    }

    /**
     * Another campaign's codes are not this campaign's history. A default read across the
     * tenant boundary would put an id in the picker that its own validation rule refuses.
     */
    public function test_the_default_ignores_another_campaigns_codes(): void
    {
        $other = Campaign::factory()->for(User::factory())->create();
        $theirs = Partner::factory()->for($other)->create();
        Code::factory()->for($other)->create(['partner_id' => $theirs->id]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('default_partner_id', null)->etc());
    }

    /**
     * The codes table has to be able to name the partner, or the attribution is written and
     * never read. The page carries the name on each code; an unassigned code carries no
     * partner at all rather than a placeholder, because the table and the export have to be
     * able to tell "nobody" from a name.
     */
    public function test_the_page_names_the_partner_on_each_code(): void
    {
        $partner = Partner::factory()->for($this->campaign)->create(['name' => 'Vendor A']);
        Code::factory()->for($this->campaign)->create(['code' => 'ASSIGNED', 'partner_id' => $partner->id]);
        Code::factory()->for($this->campaign)->create(['code' => 'UNASSIGNED', 'partner_id' => null]);

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('campaign.codes', function ($codes) use ($partner) {
                $byCode = collect($codes)->keyBy('code');

                return $byCode['ASSIGNED']['partner']['id'] === $partner->id
                    && $byCode['ASSIGNED']['partner']['name'] === 'Vendor A'
                    && $byCode['ASSIGNED']['partner_id'] === $partner->id
                    && $byCode['UNASSIGNED']['partner'] === null
                    && $byCode['UNASSIGNED']['partner_id'] === null;
            })->etc());
    }

    /**
     * Removing a partner takes it off the picker. It does not take its name off the codes it
     * was handed, which is the whole reason the delete is a soft one: last month's batch was
     * still given to that vendor, and a table that blanked the column would be saying it was
     * given to nobody.
     */
    public function test_the_page_still_names_a_deleted_partner_on_its_codes(): void
    {
        $partner = Partner::factory()->for($this->campaign)->create(['name' => 'Gone Vendor']);
        Code::factory()->for($this->campaign)->create(['code' => 'THEIRS', 'partner_id' => $partner->id]);
        $partner->delete();

        $this->actingAs($this->user)
            ->get(route('campaigns.show', $this->campaign))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Off the picker.
                ->where('partners', fn ($partners) => collect($partners)->isEmpty())
                // Still on the code.
                ->where('campaign.codes', fn ($codes) => collect($codes)->firstWhere('code', 'THEIRS')['partner']['name'] === 'Gone Vendor')
                ->etc()
            );
    }

    /**
     * The export is where the attribution answers the question it was recorded for: which of
     * the people handing codes out brought the wallets that turned up.
     */
    public function test_the_claims_export_names_the_partner_the_code_came_from(): void
    {
        $partner = Partner::factory()->for($this->campaign)->create(['name' => 'Vendor A']);
        $code = Code::factory()->for($this->campaign)->create(['code' => 'FROMVENDOR', 'partner_id' => $partner->id]);
        $claim = Claim::factory()->completed()->create(['code_id' => $code->id]);

        $csv = $this->actingAs($this->user)
            ->get(route('campaigns.export-claims', $this->campaign))
            ->assertOk()
            ->streamedContent();

        $rows = $this->csvRows($csv);

        $this->assertSame('partner', $rows[0][3]);
        $this->assertSame('FROMVENDOR', $rows[1][2]);
        $this->assertSame('Vendor A', $rows[1][3]);
        $this->assertSame($claim->address, $rows[1][0]);
    }

    /**
     * A blank cell, not the word Unassigned. Every code generated before this campaign used
     * partners reads the same way, and a name in that column would be a claim about who
     * handed it out that nobody made.
     */
    public function test_the_claims_export_leaves_the_partner_blank_when_there_is_none(): void
    {
        $code = Code::factory()->for($this->campaign)->create(['code' => 'NOBODY', 'partner_id' => null]);
        Claim::factory()->completed()->create(['code_id' => $code->id]);

        $rows = $this->csvRows(
            $this->actingAs($this->user)
                ->get(route('campaigns.export-claims', $this->campaign))
                ->streamedContent()
        );

        $this->assertSame('NOBODY', $rows[1][2]);
        $this->assertSame('', $rows[1][3]);
    }

    /**
     * A partner removed after its event still handed those codes out. The export is the
     * record of what happened, so it names them.
     */
    public function test_the_claims_export_names_a_deleted_partner(): void
    {
        $partner = Partner::factory()->for($this->campaign)->create(['name' => 'Gone Vendor']);
        $code = Code::factory()->for($this->campaign)->create(['code' => 'FROMGONE', 'partner_id' => $partner->id]);
        Claim::factory()->completed()->create(['code_id' => $code->id]);
        $partner->delete();

        $rows = $this->csvRows(
            $this->actingAs($this->user)
                ->get(route('campaigns.export-claims', $this->campaign))
                ->streamedContent()
        );

        $this->assertSame('Gone Vendor', $rows[1][3]);
    }

    /**
     * The whole header, in order, because this file is read by column.
     *
     * Each column sits with its own subject: partner belongs to the code that was handed
     * out, so it follows it; reward_lovelace and reward_tokens are what that claim was paid,
     * so they follow its status; and the onboarding classification stays one unbroken block
     * at the end rather than being split by either of them.
     *
     * Two batches of work added columns here at once, so an operator whose sheet was built
     * against the older file is reading a shifted row from this one. Everything after `code`
     * has moved, and that is what this assertion is for: the order is a decision, and it
     * should not change again without somebody choosing it.
     */
    public function test_the_export_keeps_the_columns_that_were_already_there(): void
    {
        $code = Code::factory()->for($this->campaign)->create();
        Claim::factory()->completed()->create(['code_id' => $code->id]);

        $rows = $this->csvRows(
            $this->actingAs($this->user)
                ->get(route('campaigns.export-claims', $this->campaign))
                ->streamedContent()
        );

        $this->assertSame([
            'address', 'stake_key', 'code', 'partner', 'claimed_at', 'transaction_hash', 'status',
            'reward_lovelace', 'reward_tokens',
            'wallet_classification', 'prior_tx_count', 'own_transactions',
            'days_to_first_activity', 'delegated_after_claim', 'days_to_first_delegation',
            'delegated', 'pool_id',
        ], $rows[0]);
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function csvRows(string $csv): array
    {
        return array_map(
            static fn (string $line) => str_getcsv($line, ',', '"', '\\'),
            array_values(array_filter(explode("\n", trim($csv)), static fn ($line) => trim($line) !== '')),
        );
    }
}

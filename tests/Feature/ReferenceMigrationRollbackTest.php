<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The reference migration's down() puts a plain index back on campaign_id, MySQL only, so
 * the campaign_id foreign key still has something to stand on once the composite unique
 * index is dropped. That add has to survive running twice in the same session: a second
 * rollback finds the plain index already there, put back by the first one and never removed
 * by the up() run between them, and adding it again unconditionally is a duplicate key name
 * on MySQL (error 1061).
 *
 * Its own class and DatabaseMigrations, not a shared transaction, for the same reason
 * UnreadOnboardingWalletsRollbackTest and BackfillTokenExpiryTest are: rolling a migration
 * back and forward is schema work this suite needs to actually happen.
 *
 * MySQL only. The guard this proves exists purely because MySQL enforces a foreign key
 * needing a supporting index; SQLite never takes that branch at all, so running this on
 * SQLite would pass whether or not the guard were there and prove nothing.
 */
class ReferenceMigrationRollbackTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = 'database/migrations/2026_09_22_090000_add_reference_to_codes.php';

    public function test_the_migration_reverses_and_reapplies_twice_in_a_row(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The guard this proves, and the bug it fixes, only exist on MySQL.');
        }

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('codes', 'reference'));

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('codes', 'reference'));

        // The second rollback in the same run, which is what a plain unconditional
        // $table->index('campaign_id') in down() cannot survive: the first rollback
        // already put that index there, and nothing removes it in the up() between the
        // two, so adding it again here is the duplicate key name this test exists to
        // rule out.
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('codes', 'reference'));

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('codes', 'reference'));
    }
}

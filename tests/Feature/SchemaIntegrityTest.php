<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every foreign key has to match the column it points at.
     *
     * This application does not use one kind of key throughout: users, claims and codes
     * are auto-incrementing, campaigns and the newer tables are ULIDs. A foreign key
     * declared as the wrong one is silently accepted by SQLite, which does not check that
     * a reference is type-compatible, and rejected outright by MySQL, which does. So the
     * whole suite passes locally and every test fails in CI on a migration that never
     * ran.
     *
     * Asserted here rather than left to CI to discover, because a broken migration takes
     * the entire schema with it and the failure that gets reported is hundreds of tests
     * failing for reasons that have nothing to do with them.
     */
    public function test_every_foreign_key_matches_the_column_it_references(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            // On MySQL the constraint enforces this itself: an incompatible key fails the
            // migration, so reaching this point already proves it.
            $this->assertTrue(true);

            return;
        }

        $mismatches = [];

        foreach ($this->tables() as $table) {
            foreach (DB::select("PRAGMA foreign_key_list(`{$table}`)") as $fk) {
                $from = Schema::getColumnType($table, $fk->from);
                $to = Schema::getColumnType($fk->table, $fk->to ?? 'id');

                if ($from !== $to) {
                    $mismatches[] = "{$table}.{$fk->from} is {$from} but {$fk->table}.{$fk->to} is {$to}";
                }
            }
        }

        $this->assertSame([], $mismatches, "Foreign keys that will fail on MySQL:\n".implode("\n", $mismatches));
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_map(
            static fn ($row) => $row->name,
            DB::select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%'"),
        );
    }

    /**
     * MySQL refuses an identifier longer than 64 characters. SQLite has no such limit, so a
     * migration carrying an over-long index name runs perfectly well locally and fails only
     * where it matters. Laravel generates a name from the table and every column in the key,
     * so a composite key on a table with a long name reaches the limit without anyone writing
     * anything unusual.
     *
     * This reads the schema the migrations actually produced rather than parsing their source,
     * so it catches an explicit name that is too long as well as a generated one.
     */
    public function test_no_index_name_exceeds_what_mysql_allows(): void
    {
        $limit = 64;
        $long = [];

        foreach (Schema::getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table)[1] : $table;

            foreach (Schema::getIndexes($table) as $index) {
                $name = $index['name'] ?? '';

                if (strlen($name) > $limit) {
                    $long[] = sprintf('%s on %s is %d characters', $name, $table, strlen($name));
                }
            }
        }

        $this->assertSame([], $long, implode("\n", array_merge(
            ['An index name is longer than MySQL allows:'],
            $long,
            ['', 'Pass an explicit short name as the second argument to unique() or index().'],
        )));
    }
}

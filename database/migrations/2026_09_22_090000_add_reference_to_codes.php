<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a caller of the code-creation API made this code from.
     *
     * Nullable, because every code made through the campaign page has no reference and
     * never will: it is set once, by the one caller that has an identity to key a code
     * on, and nothing backfills one for a code that was never asked for that way.
     *
     * Unique per campaign rather than globally, matching how a code's own random string
     * is scoped. Two campaigns are free to use the same reference for their own callers;
     * nothing about one means anything to the other. MySQL and SQLite both treat two NULL
     * references in the same campaign as distinct, so campaigns with no API caller at all
     * are unaffected.
     *
     * Given a binary collation on MySQL. This deployment's default collation compares text
     * case-insensitively, so without this "aB3x" and "Ab3X" would read as the same reference
     * to the unique index below, and the second caller's request would silently return the
     * first caller's code. SQLite's TEXT columns already compare byte-for-byte, so there is
     * nothing to correct there; the guard exists because the two engines disagree, not
     * because either is wrong on its own.
     */
    public function up(): void
    {
        Schema::table('codes', static function (Blueprint $table) {
            $column = $table->string('reference', 191)->nullable()->after('code');

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $column->collation('utf8mb4_bin');
            }

            $table->unique(['campaign_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::table('codes', static function (Blueprint $table) {
            // On MySQL, this composite index is by now the only one covering campaign_id
            // at all, so it is what the campaign_id foreign key relies on to enforce
            // itself — nothing explicitly named that column alone survived being made
            // redundant by it. Dropping it first would leave that foreign key backed by
            // no index, which MySQL refuses outright (error 1553) rather than allow.
            // Putting a plain index back on campaign_id first gives the foreign key
            // something else to stand on, so the unique index can then be dropped
            // freely. SQLite does not enforce this at all, so it never needed the extra
            // index and is not given one.
            //
            // Guarded, because a second down() in the same run — up, down, up, down,
            // exactly what a test that proves this migration reverses cleanly does —
            // finds the plain index already there from the first down() and never
            // removed by the up() in between. Adding it again unconditionally is a
            // duplicate key name on MySQL (error 1061); this only adds it when it is
            // not already there.
            if (Schema::getConnection()->getDriverName() === 'mysql'
                && ! Schema::hasIndex('codes', ['campaign_id'])) {
                $table->index('campaign_id');
            }

            $table->dropUnique(['campaign_id', 'reference']);
            $table->dropColumn('reference');
        });
    }
};

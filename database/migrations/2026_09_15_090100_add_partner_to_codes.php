<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which partner a code was generated for.
     *
     * Nullable, because every code that already exists was generated before partners
     * did, and there is no honest value to backfill: a code attributed to a partner
     * after the fact was not necessarily handed out by them.
     *
     * nullOnDelete covers a hard delete only. The ordinary delete is a soft delete, which
     * leaves the row in place and the assignment with it.
     */
    public function up(): void
    {
        Schema::table('codes', static function (Blueprint $table) {
            $table->foreignUlid('partner_id')
                ->nullable()
                ->after('campaign_id')
                ->constrained()
                ->nullOnDelete();

            $table->index('partner_id');
        });
    }

    /**
     * The foreign key goes first, and the index it uses second.
     *
     * MySQL builds the index before the constraint, so the constraint adopts
     * `codes_partner_id_index` rather than creating an index of its own. While the
     * constraint is still there the index cannot be dropped, and MySQL refuses with error
     * 1553. SQLite does not enforce that, which is why this order matters only where the
     * application actually runs.
     */
    public function down(): void
    {
        Schema::table('codes', static function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropIndex(['partner_id']);
            $table->dropColumn('partner_id');
        });
    }
};

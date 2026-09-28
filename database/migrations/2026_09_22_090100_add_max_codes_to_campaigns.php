<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many codes a campaign may ever hold, the operator's own ceiling on top of
     * whatever the credit ledger already enforces.
     *
     * Nullable, meaning no limit, which is what every campaign made before this existed
     * keeps meaning for it. Enforced wherever a code is created: the campaign page's own
     * form, the bulk import, and the code-creation API, all through one shared check so
     * the three paths cannot drift into three different answers for "is there room".
     */
    public function up(): void
    {
        Schema::table('campaigns', static function (Blueprint $table) {
            $table->unsignedInteger('max_codes')->nullable()->after('one_per_wallet');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', static function (Blueprint $table) {
            $table->dropColumn('max_codes');
        });
    }
};

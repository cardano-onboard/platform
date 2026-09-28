<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each claim was charged, recorded on the claim itself.
     *
     * Revenue is read from the claim rather than worked out afterwards by multiplying a
     * count by today's rate. Rates change, campaigns sit on different terms, and some are
     * billed in credits while others are billed in ADA, so a figure reconstructed from the
     * current rate would be wrong for every claim taken under a previous one.
     *
     * This is the same rule already settled for what a claim paid out: the claim records
     * what actually happened at the moment it happened, and a later edit applies forward.
     *
     * Both columns are nullable and stay null until a rate is configured. A claim with no
     * recorded charge is reported as exactly that, never as zero revenue, because the two
     * mean different things.
     */
    public function up(): void
    {
        Schema::table('claims', static function (Blueprint $table) {
            $table->unsignedBigInteger('revenue_lovelace')->nullable()->after('transaction_hash');
            $table->decimal('revenue_usd', 12, 6)->nullable()->after('revenue_lovelace');
        });
    }

    public function down(): void
    {
        Schema::table('claims', static function (Blueprint $table) {
            $table->dropColumn(['revenue_lovelace', 'revenue_usd']);
        });
    }
};

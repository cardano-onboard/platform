<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What each claim was actually paid, recorded on the claim itself.
     *
     * A claim has until now stored an address, a stake key and a transaction id, and
     * nothing at all about the reward. What somebody received had to be read back off the
     * code, which answers with whatever the code says today rather than with what was sent.
     * That was already a gap in the record before anything could be edited: settlement,
     * a dispute and an operator's own accounts all need what went out, not what is
     * configured now.
     *
     * It becomes load-bearing the moment a code's reward can be changed. A code with ten
     * uses and three claims against it is one row whose reward is about to mean two
     * different things, and only the claim can hold the first of them.
     *
     * Written at fulfilment, from the bundle the payment was built out of, so an edit
     * applies forward and never rewrites what a previous claimant was given. Both columns
     * are nullable and stay null for a claim that has not been sent yet and for every claim
     * taken before this existed. Null means unrecorded, which is a different thing from a
     * reward of nothing, and nothing backfills it: a figure invented now would be today's
     * configuration wearing the authority of a record.
     *
     * The same rule as the revenue snapshot on this table, for the same reason.
     */
    public function up(): void
    {
        Schema::table('claims', static function (Blueprint $table) {
            $table->unsignedBigInteger('reward_lovelace')->nullable()->after('revenue_usd');
            $table->json('reward_tokens')->nullable()->after('reward_lovelace');
        });
    }

    public function down(): void
    {
        Schema::table('claims', static function (Blueprint $table) {
            $table->dropColumn(['reward_lovelace', 'reward_tokens']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a campaign's payouts remember about the platform fees they collected.
 *
 * A fee is collected in the payout that pays the claim, into one fees output per campaign that
 * each payout spends and recreates. Three things have to be on record for that to be safe to
 * continue after a restart: which output is the campaign's current fees output, what each claim
 * in a payout paid and as which of the campaign's paid claims, and that both are written with
 * the transaction before it is sent, like everything else a payout does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_transactions', static function (Blueprint $table) {
            // The fees output this transaction spent, as hash#index, and the index of the one it
            // created. The first is null when the transaction opened a new fees output, which is
            // what a campaign's first payout does and what the next one does after the old output
            // was spent by something other than this campaign's chain.
            $table->string('fees_input', 80)->nullable()->after('inputs_consumed');
            $table->unsignedSmallInteger('fees_output_index')->nullable()->after('fees_input');
        });

        Schema::table('campaign_transaction_claims', static function (Blueprint $table) {
            // What the claim paid the platform in this payout, and which of the campaign's paid
            // claims it was. Append-only like the rest of the row: a payout that never lands
            // keeps its figures here as history, and the claims it would have paid are given
            // fresh ones in the payout that replaces it.
            $table->unsignedBigInteger('fee_lovelace')->nullable()->after('lovelace');
            $table->unsignedInteger('ordinal')->nullable()->after('fee_lovelace');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_transaction_claims', static function (Blueprint $table) {
            $table->dropColumn(['fee_lovelace', 'ordinal']);
        });

        Schema::table('campaign_transactions', static function (Blueprint $table) {
            $table->dropColumn(['fees_input', 'fees_output_index']);
        });
    }
};

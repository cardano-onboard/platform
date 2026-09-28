<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * First-party source codes, so a visit can be traced to the thing that sent it.
     *
     * A short opaque code travels on a published link or a printed sticker, is captured on
     * landing and held in the session, and is attached to the user record at registration.
     * That is what turns a post into an account rather than into an anonymous hit.
     *
     * The code carries no meaning of its own. Channel, campaign, placement and variant are
     * columns here, so reporting is by dimension rather than by opaque string, and a code
     * pasted into a chat reveals nothing about what published it.
     *
     * Four hex characters give 65,536 values against the few hundred that will ever be in
     * use, so a mistyped code lands on nothing rather than on somebody else's campaign.
     */
    public function up(): void
    {
        Schema::create('source_codes', static function (Blueprint $table) {
            $table->char('code', 4)->primary();
            // The channel the code was issued for: a social account, an email send, a
            // printed run, a partner. Required, because a code with no channel cannot be
            // reported on by dimension and is therefore no better than the opaque string.
            $table->string('channel', 32);
            // A marketing campaign, not a platform Campaign. There is no campaign_id here
            // and none is intended: these describe our own outreach, not a customer's
            // airdrop.
            $table->string('campaign', 64)->nullable();
            $table->string('placement', 64)->nullable();
            $table->string('variant', 32)->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['channel', 'campaign']);
        });

        Schema::create('source_code_visits', static function (Blueprint $table) {
            $table->char('code', 4);
            $table->date('date');
            $table->unsignedInteger('visits')->default(0);

            $table->primary(['code', 'date']);
            // A visit count is meaningless once the code it belongs to is gone, so it goes
            // with it. Contrast users.source_code below, which deliberately has no
            // constraint.
            $table->foreign('code')->references('code')->on('source_codes')->cascadeOnDelete();
        });

        Schema::table('users', static function (Blueprint $table) {
            // No foreign key, on purpose. Where an account came from is a fact about that
            // account's history, and deleting the code record should not rewrite it or
            // blank it out. The column keeps its value whether or not the code still exists.
            $table->char('source_code', 4)->nullable()->after('email_verified_at');

            $table->index('source_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table) {
            $table->dropIndex(['source_code']);
            $table->dropColumn('source_code');
        });

        Schema::dropIfExists('source_code_visits');
        Schema::dropIfExists('source_codes');
    }
};

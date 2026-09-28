<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which client software claims arrive from, stored so that it can never be traced back
     * to a claimant.
     *
     * Naming the wallet that signed for a given address ties a person's choice of wallet to
     * their stake key, and the claims table holds both the address and the stake key. A
     * column there would create that link for anyone who can read the table, so the storage
     * here is shaped to make the join impossible rather than merely unused: a per-campaign
     * tally with no reference to a claim, and a catalogue of distinct strings scoped to no
     * campaign and no claim.
     *
     * NEITHER TABLE MAY GAIN A SURROGATE KEY OR A TIMESTAMP, and this is why. An
     * auto-incrementing id records insertion order, insertion order is claim order, and so
     * row n would identify the nth claim whatever the foreign keys say. A created_at gives
     * the same answer directly, and for a client seen once it gives that claim's own time.
     *
     * That also rules out the project-wide HasUlids convention on these two tables. A ULID
     * encodes its creation time in its leading bits, so a ULID primary key is a millisecond
     * timestamp wearing an identifier's clothes and leaks strictly more than the created_at
     * column already ruled out. These tables are the deliberate exception to that pattern.
     */
    public function up(): void
    {
        Schema::create('campaign_claim_clients', static function (Blueprint $table) {
            $table->foreignUlid('campaign_id')
                ->constrained()
                ->cascadeOnDelete();
            // Shorthand for the client library, not the raw string. See App\Support\ClaimClient.
            $table->string('client', 32);
            $table->unsignedInteger('claims')->default(0);

            // The campaign and the client together are the identity of a row. Incremented
            // only where a claim is accepted, never where a request merely arrives:
            // rejections are dominated by scanners and retries and say nothing about what
            // completed a claim.
            $table->primary(['campaign_id', 'client']);
        });

        Schema::create('claim_user_agents', static function (Blueprint $table) {
            // Content-addressed, so the key is determined by what the string is rather than
            // by when it arrived.
            $table->char('fingerprint', 64)->primary();
            $table->text('user_agent');
            $table->string('client', 32);
            // A guess, and only ever a guess. The mapping holds while no two wallets share
            // a client library, which is a fact about today rather than a rule.
            $table->string('wallet', 32)->nullable();

            $table->index('client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_user_agents');
        Schema::dropIfExists('campaign_claim_clients');
    }
};

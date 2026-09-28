<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per post-claim transaction a claimant wallet was seen in.
     *
     * The per-wallet insight row carries totals, and a total cannot be cut into a
     * thirty-day figure afterwards. Answering "what happened in the first thirty days"
     * needs the time each transaction landed, so each transaction gets a row with the
     * timestamp the chain reported for it.
     *
     * Certificates live here for the same reason. A delegation certificate is what tells a
     * wallet that only delegated apart from one that spent, and the difference is per
     * transaction: the same wallet may delegate in one transaction and pay somebody in the
     * next. Recording the certificate types seen against this wallet, per transaction,
     * keeps both answers available without asking the chain twice.
     *
     * Every column is an observation with the moment it was observed beside it, never a
     * figure replayed against the clock of whoever is reading. `occurred_at` is the block
     * time the provider reported, `seconds_after_claim` is that time minus the claim time
     * recorded in the same run, and `observed_at` is when the run read it.
     */
    public function up(): void
    {
        Schema::create('campaign_wallet_activity', static function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('campaign_id')
                ->constrained()
                ->cascadeOnDelete();
            // The wallet identity used everywhere in this analysis. Not a foreign key to
            // campaign_wallet_insights: these rows are written in the same pass that
            // writes the insight, and a stake key is what both are keyed on.
            $table->string('stake_key');
            $table->string('tx_hash', 64);
            $table->unsignedBigInteger('block_height')->nullable();
            // When the transaction landed, per the provider's block time. Null when the
            // provider returned no time for it, which is unknown and not zero.
            $table->timestamp('occurred_at')->nullable();
            // Signed, and not clamped. A post-claim transaction is after the claim by
            // block height, but the claim time can come from the platform's own record
            // when the chain did not supply one, and forcing a negative offset to zero
            // would invent a fact the run never observed.
            $table->bigInteger('seconds_after_claim')->nullable();
            // Did this wallet put an input into the transaction. Receiving is not doing.
            $table->boolean('self_initiated')->default(false);
            // The certificate types in this transaction whose subject is THIS wallet,
            // as the provider named them. A list, so the order it is written in is the
            // order it comes back in on both engines.
            $table->json('certificate_types')->nullable();
            // Did this wallet submit a stake-lifecycle certificate here.
            $table->boolean('is_delegation')->default(false);
            // Did this wallet do something here beyond submitting a certificate.
            $table->boolean('is_activity')->default(false);
            $table->boolean('script_interaction')->default(false);
            $table->timestamp('observed_at')->nullable();
            $table->timestamps();

            // A re-analysis rewrites a campaign's rows. The unique key is the guard that
            // an interrupted rewrite cannot leave the same transaction recorded twice.
            $table->unique(['campaign_id', 'stake_key', 'tx_hash'], 'cwa_campaign_stake_tx_unique');
            // The window query: a campaign's rows, narrowed by how long after the claim
            // they happened.
            $table->index(['campaign_id', 'seconds_after_claim'], 'cwa_campaign_offset_index');
        });
    }

    /**
     * Foreign key first, then the indexes, then the table.
     *
     * InnoDB will not drop an index a foreign key is using, and the constraint created by
     * foreignUlid()->constrained() sits on campaign_id, which the unique key above also
     * covers. Dropping the table alone would work; doing it in this order means the same
     * migration is correct if the table ever gains a step that drops one index and keeps
     * the rest.
     */
    public function down(): void
    {
        if (! Schema::hasTable('campaign_wallet_activity')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('campaign_wallet_activity', static function (Blueprint $table) {
                $table->dropForeign(['campaign_id']);
                $table->dropUnique('cwa_campaign_stake_tx_unique');
                $table->dropIndex('cwa_campaign_offset_index');
            });
        }

        Schema::drop('campaign_wallet_activity');
    }
};

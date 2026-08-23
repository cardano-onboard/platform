<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Onboarding analysis storage.
     *
     * Two tables rather than a blob on the campaign: the per-wallet rows are what make a
     * re-run cheap. Analysis costs roughly one Koios query per distinct claimant wallet,
     * so a run that is interrupted by rate limiting needs to resume from the wallets it
     * has already classified instead of replaying the whole campaign.
     */
    public function up(): void
    {
        Schema::create('campaign_analyses', static function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('campaign_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('wallets_total')->default(0);
            $table->unsignedInteger('wallets_analyzed')->default(0);
            // Aggregate metrics, rendered as-is by the campaign page. Kept alongside the
            // per-wallet rows so the panel has something to show while a re-run is in
            // flight, rather than blanking out.
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique('campaign_id');
        });

        Schema::create('campaign_wallet_insights', static function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('campaign_id')
                ->constrained()
                ->cascadeOnDelete();
            // Stake key is the wallet identity here: a claimant may present different
            // payment addresses from the same wallet, and it is what Koios keys history on.
            $table->string('stake_key');
            $table->string('address')->nullable();
            $table->string('claim_tx_hash')->nullable();
            $table->unsignedBigInteger('claim_block_height')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedInteger('prior_tx_count')->default(0);
            $table->boolean('is_new')->default(false);
            $table->boolean('activated')->default(false);
            $table->unsignedInteger('self_initiated_count')->default(0);
            $table->unsignedInteger('post_claim_tx_count')->default(0);
            $table->boolean('delegated')->default(false);
            $table->string('pool_id')->nullable();
            $table->unsignedInteger('script_interactions')->default(0);
            $table->boolean('is_operator')->default(false);
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'stake_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_wallet_insights');
        Schema::dropIfExists('campaign_analyses');
    }
};

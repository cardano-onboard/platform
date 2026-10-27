<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a campaign has to remember about the transactions it has sent.
 *
 * Three tables, each closing a way a payment can be lost or made twice.
 *
 * A lease, because a campaign's outputs are a chain: each transaction spends the one before it,
 * so two workers that both read the same tip build two transactions spending the same output and
 * one of them is rejected after its claims were marked as sent. Process-level locks do not help.
 * They are time-bounded and know nothing about a chain, so a worker that runs long enough loses
 * its lock while still holding a tip.
 *
 * A record of every transaction, written before it is submitted rather than after. A worker killed
 * between submitting and writing leaves money moved with nothing pointing at it, and the retry,
 * finding no record, builds and submits a second one. Writing first means the retry finds the
 * transaction and resubmits the same bytes, which the chain accepts once however many times it is
 * offered.
 *
 * And what each transaction paid, append-only. The claims table already carries a transaction id,
 * but that column is cleared when a payment is rebuilt, so it cannot answer what was paid before.
 * The question a settlement has to answer is what has already gone out, and a column that forgets
 * cannot answer it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_chain_leases', static function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();

            // Who holds it and until when. A holder that dies without releasing it is the ordinary
            // case rather than the exception, so the lease expires rather than waiting to be
            // returned.
            $table->string('holder', 64)->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // One per campaign, so taking a lease is an update of a row that already exists and
            // two workers cannot each insert their own.
            $table->unique('campaign_id', 'chain_leases_campaign_unique');
        });

        Schema::create('campaign_transactions', static function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('campaign_id')->constrained()->cascadeOnDelete();

            // Its place in the campaign's chain, so a stored tail can be replayed in the order it
            // was built rather than in the order rows happen to come back.
            $table->unsignedInteger('sequence');

            $table->string('tx_hash', 64);

            // The signed bytes, kept so a resubmission offers exactly what was offered before. A
            // rebuild is the only thing that can pay twice, so the bytes are what makes a retry
            // safe.
            $table->longText('signed_cbor');

            // What it spends, so an input consumed twice can be found rather than inferred.
            $table->json('inputs_consumed');

            $table->unsignedBigInteger('validity_end');

            $table->timestamp('confirmed_at')->nullable();

            // A transaction that will never land: refused by the node, or past its validity
            // without the chain ever seeing it. Nothing builds on it and the claims it would have
            // paid are free to be paid by another.
            $table->timestamp('failed_at')->nullable();
            $table->string('failure', 32)->nullable();
            $table->timestamps();

            $table->unique('tx_hash', 'campaign_txs_hash_unique');
            $table->unique(['campaign_id', 'sequence'], 'campaign_txs_sequence_unique');
            $table->index(['campaign_id', 'confirmed_at'], 'campaign_txs_unconfirmed_index');
        });

        Schema::create('campaign_transaction_claims', static function (Blueprint $table) {
            $table->id();
            $table->string('tx_hash', 64);
            // An integer, because that is what claims use. A ULID column here would be a varchar
            // pointing at an integer key: accepted by SQLite, refused by MySQL, and refused at
            // deploy time rather than in a test.
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();

            // What this transaction paid, recorded here rather than read back from the claim,
            // because the claim's own record of it is allowed to change and this is not.
            $table->unsignedBigInteger('lovelace');
            $table->timestamps();

            $table->unique(['tx_hash', 'claim_id'], 'campaign_tx_claims_unique');
        });
    }

    public function down(): void
    {
        // Dropping a table takes its indexes and its foreign keys with it, so the order here is
        // only about which table is referenced by another.
        Schema::dropIfExists('campaign_transaction_claims');
        Schema::dropIfExists('campaign_transactions');
        Schema::dropIfExists('campaign_chain_leases');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credits, and the ledger that explains a balance.
     *
     * A credit is the largest a single claim can cost, so a balance of five hundred is a
     * guarantee that five hundred claims can be served whatever they turn out to cost.
     * That is what lets a spend limit answer "what if ten thousand people claim" with a
     * number rather than a hope.
     *
     * What a credit covers differs by path, because the two customers buy different
     * things. A Cardano-native customer funds their own float, so only the platform fee
     * is ours to sell. A customer paying in dollars buys the fee, the network cost, the
     * minimum UTxO and the headroom a recipient needs to move what they were given.
     *
     * Everything is integer millionths, the way lovelace is. No money figure passes
     * through a float on its way anywhere.
     */
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table) {
            // Which of the two paths this customer buys on. Null falls back to the
            // configured default, so existing accounts need no backfill.
            $table->string('billing_path', 8)->nullable()->after('is_admin');
        });

        Schema::table('campaigns', static function (Blueprint $table) {
            // An override, for the customer running both a Cardano drop and a
            // dollar-priced event. Null means the account's path.
            $table->string('billing_path', 8)->nullable()->after('network');
            // A ceiling on what this campaign may consume. This is a product feature
            // before it is a billing control: the first objection an events manager
            // raises to a giveaway is what happens if ten thousand people claim, and a
            // cap answers it inside the product rather than in a sales call. Null means
            // no ceiling beyond the account balance.
            $table->unsignedBigInteger('spend_limit_micro')->nullable()->after('billing_path');
        });

        Schema::table('claims', static function (Blueprint $table) {
            // What this claim consumed, stamped when it happened. The money it was worth
            // is already stamped alongside in revenue_lovelace and revenue_usd, and both
            // follow the same rule: written at fulfilment, never recomputed.
            $table->unsignedBigInteger('credits_micro')->nullable()->after('revenue_usd');
            // Why this claim is not being sent. A claim held for credit is accepted and
            // recorded, and the claimant is told it is accepted, which is what they are
            // told anyway because delivery has always been asynchronous. It simply is not
            // picked up until the operator tops up. Null is the normal case.
            $table->string('held_reason', 32)->nullable()->after('credits_micro');

            $table->index('held_reason');
        });

        Schema::create('credit_transactions', static function (Blueprint $table) {
            $table->ulid('id')->primary();

            // Keys follow the table they point at, and this application does not use one
            // kind throughout: users and claims are auto-incrementing, campaigns are
            // ULIDs. SQLite does not check that a foreign key matches the column it
            // references, so getting this wrong is invisible there and fatal on MySQL.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Both nullable: a granted pack belongs to an account and to no campaign, and
            // an adjustment may belong to neither.
            $table->foreignUlid('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('claim_id')->nullable()->constrained()->nullOnDelete();

            // Which grant a debit drew from. Credits bought in different packs were
            // bought at different prices, so a debit has to say which ones it spent or
            // the value consumed cannot be told from the value sold. Oldest pack first,
            // and a debit that crosses the end of one pack becomes two rows rather than
            // one row with an averaged price. It is also the column that any later
            // question about expiry or an unspent balance will be asked through.
            $table->foreignUlid('source_id')->nullable()->constrained('credit_transactions')->nullOnDelete();

            // grant, debit, adjustment. A string rather than an enum so adding a kind is a
            // deploy and not a migration.
            $table->string('kind', 16);
            $table->string('path', 8);

            // Signed. A grant is positive, a debit negative, and the balance is the sum.
            // Deriving the balance rather than keeping a column means it cannot drift out
            // of agreement with the rows that explain it.
            $table->bigInteger('delta_micro');

            // What a credit was sold for on this movement, so revenue is read from what
            // was charged rather than recomputed from a rate that has since moved. Only
            // the column matching the path is filled.
            $table->unsignedBigInteger('unit_price_lovelace')->nullable();
            $table->decimal('unit_price_usd', 12, 6)->nullable();

            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        Schema::table('claims', static function (Blueprint $table) {
            $table->dropIndex(['held_reason']);
            $table->dropColumn(['credits_micro', 'held_reason']);
        });

        Schema::table('campaigns', static function (Blueprint $table) {
            $table->dropColumn(['billing_path', 'spend_limit_micro']);
        });

        Schema::table('users', static function (Blueprint $table) {
            $table->dropColumn('billing_path');
        });
    }
};

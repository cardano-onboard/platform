<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lets a wallet the chain read could not answer for come out unknown.
     *
     * `is_new` and `prior_tx_count` were written by every run for every wallet. A run whose
     * provider returned nothing wrote a prior transaction count of zero, which reads as a
     * wallet with no history, which is the headline claim this whole analysis exists to
     * make. `delegated` had the same shape. All three become nullable, and null means no
     * run has been able to read it rather than a wallet that had nothing and did nothing.
     *
     * `unread_wallets` on the analysis is how many wallets the last run could not read
     * through. It is what lets the panel say a result has holes in it without inferring
     * that from the rows, which cannot tell a wallet this run failed on from a wallet an
     * earlier run measured and this one left alone.
     */
    public function up(): void
    {
        Schema::table('campaign_wallet_insights', static function (Blueprint $table) {
            $table->unsignedInteger('prior_tx_count')->nullable()->default(null)->change();
            $table->boolean('is_new')->nullable()->default(null)->change();
            $table->boolean('delegated')->nullable()->default(null)->change();
        });

        Schema::table('campaign_analyses', static function (Blueprint $table) {
            $table->unsignedInteger('unread_wallets')->default(0)->after('wallets_analyzed');
        });
    }

    /**
     * The nulls have to go before the columns stop accepting them, or the change fails on
     * any install that has recorded an unread wallet. They go back to the values the
     * columns used to default to, which is what a run before this migration would have
     * written for the same wallet.
     *
     * The 'partial' status goes with them. It is written by the same runs that write these
     * nulls, and the code being rolled back to has no such status: `isFinished()` and
     * `isRunning()` both say no to it, so a campaign left on 'partial' would show neither a
     * result nor a run in flight and offer no way back. It becomes 'complete', which is
     * what a run with holes in it was recorded as before this migration and matches the
     * zeros the rows are being given above.
     */
    public function down(): void
    {
        DB::table('campaign_wallet_insights')->whereNull('prior_tx_count')->update(['prior_tx_count' => 0]);
        DB::table('campaign_wallet_insights')->whereNull('is_new')->update(['is_new' => false]);
        DB::table('campaign_wallet_insights')->whereNull('delegated')->update(['delegated' => false]);

        DB::table('campaign_analyses')->where('status', 'partial')->update(['status' => 'complete']);

        Schema::table('campaign_wallet_insights', static function (Blueprint $table) {
            $table->unsignedInteger('prior_tx_count')->default(0)->nullable(false)->change();
            $table->boolean('is_new')->default(false)->nullable(false)->change();
            $table->boolean('delegated')->default(false)->nullable(false)->change();
        });

        Schema::table('campaign_analyses', static function (Blueprint $table) {
            $table->dropColumn('unread_wallets');
        });
    }
};

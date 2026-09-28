<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retires `activated` and records what the run actually watched.
     *
     * `activated` was stored as "this wallet initiated at least one transaction after its
     * claim". A wallet that registers a stake key and delegates puts itself in as an input
     * of that transaction, because it pays the deposit and the fee, so delegating alone
     * satisfied it. The figure counted delegation as activity and there is no way to tell
     * the two apart in what was stored, which is why the column goes rather than staying
     * beside the corrected one: keeping a number known to be wrong makes the panel harder
     * to read, not easier to reconcile.
     *
     * What replaces it is recorded with the moment it was recorded at:
     *
     *   activity_count             transactions this wallet initiated that did something
     *                              other than submit a certificate of its own
     *   delegation_events          transactions in which it submitted a stake-lifecycle
     *                              certificate
     *   first_activity_seconds     how long after the claim the first of those happened,
     *                              which is what a thirty, sixty or ninety day window is
     *                              asked of
     *   first_delegation_seconds   the same for delegation
     *   observed_seconds           how long this wallet could be watched: the moment the
     *                              run read the chain, minus the claim. A wallet claimed
     *                              nine days ago has no ninety-day answer, and reporting
     *                              it as a zero would be a different claim entirely
     *   windows_observed_at        when the run read it. Null means this row predates the
     *                              correction, so its windows are unknown rather than zero
     *
     * `windowed_at` on the analysis row is the same distinction for the campaign as a
     * whole. A stored summary written before this migration holds activation figures that
     * counted delegation, so the panel has to be able to tell that it is looking at one.
     */
    public function up(): void
    {
        Schema::table('campaign_wallet_insights', static function (Blueprint $table) {
            $table->unsignedInteger('activity_count')->default(0)->after('self_initiated_count');
            $table->unsignedInteger('delegation_events')->default(0)->after('activity_count');
            $table->bigInteger('first_activity_seconds')->nullable()->after('delegation_events');
            $table->bigInteger('first_delegation_seconds')->nullable()->after('first_activity_seconds');
            $table->bigInteger('observed_seconds')->nullable()->after('first_delegation_seconds');
            $table->timestamp('windows_observed_at')->nullable()->after('analyzed_at');
        });

        // Separate statement from the additions above. SQLite rebuilds the table for a
        // dropped column, and asking one blueprint to both add and drop leaves the order
        // the two engines apply them in as the thing the result depends on.
        Schema::table('campaign_wallet_insights', static function (Blueprint $table) {
            $table->dropColumn('activated');
        });

        Schema::table('campaign_analyses', static function (Blueprint $table) {
            $table->timestamp('windowed_at')->nullable()->after('completed_at');
        });
    }

    /**
     * No index or foreign key is added or removed here, so there is no drop order to get
     * wrong. `activated` comes back with its original default rather than a reconstructed
     * value: the run that could tell delegation from activity is the only thing that ever
     * knew which wallets it applied to, and guessing it back from the corrected columns
     * would write the wrong answer under the old name.
     */
    public function down(): void
    {
        Schema::table('campaign_wallet_insights', static function (Blueprint $table) {
            $table->boolean('activated')->default(false)->after('is_new');
        });

        Schema::table('campaign_wallet_insights', static function (Blueprint $table) {
            $table->dropColumn([
                'activity_count',
                'delegation_events',
                'first_activity_seconds',
                'first_delegation_seconds',
                'observed_seconds',
                'windows_observed_at',
            ]);
        });

        Schema::table('campaign_analyses', static function (Blueprint $table) {
            $table->dropColumn('windowed_at');
        });
    }
};

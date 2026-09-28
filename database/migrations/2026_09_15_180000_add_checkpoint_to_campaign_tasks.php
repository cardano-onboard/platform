<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a run got to, so the next attempt carries on instead of starting again.
     *
     * Progress and checkpoint answer different questions and cannot share a column.
     * progress_done is for the operator: a number that only has to be close enough to draw
     * a bar with, and the trait deliberately writes it every so many items rather than
     * every one. The checkpoint is for the worker: the cursor it stopped at, where the
     * partial output was left, and enough about that output to decide whether it can still
     * be trusted. Resuming from a bar's numerator would mean resuming from a number that
     * was rounded for display.
     *
     * Nullable, and null is the ordinary state: a run that has not checkpointed yet, and a
     * job that never checkpoints at all, both leave it alone.
     */
    public function up(): void
    {
        Schema::table('campaign_tasks', static function (Blueprint $table) {
            $table->json('checkpoint')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_tasks', static function (Blueprint $table) {
            $table->dropColumn('checkpoint');
        });
    }
};

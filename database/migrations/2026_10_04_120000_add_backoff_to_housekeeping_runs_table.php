<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a task was last attempted and last failed, and how many attempts in a row have not
     * succeeded.
     *
     * Traffic uses these to wait before trying a failed task again, rather than trying it at
     * every check. The count is raised when a run takes its lock and cleared when a run
     * succeeds, so a run whose worker dies before it can report counts as a failure too.
     */
    public function up(): void
    {
        Schema::table('housekeeping_runs', static function (Blueprint $table) {
            // When the most recent run took the lock, whether or not it went on to succeed.
            $table->timestamp('last_attempt_at')->nullable()->after('last_run_at');
            // When the most recent failed run reported its failure. The wait after a failure
            // runs from here, or from last_attempt_at for a run that died without reporting.
            $table->timestamp('last_failed_at')->nullable()->after('last_attempt_at');
            // Runs since the last success that took the lock and did not succeed.
            $table->unsignedInteger('failures')->default(0)->after('last_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('housekeeping_runs', static function (Blueprint $table) {
            $table->dropColumn(['last_attempt_at', 'last_failed_at', 'failures']);
        });
    }
};

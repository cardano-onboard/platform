<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per housekeeping task: when it last succeeded, and who is running it now.
     *
     * This lives in the database rather than the cache because a deployment that scales to
     * zero may come back with an empty cache, and a forgotten last run reads as a task that
     * has never run. The lock is here for the same reason: it has to be seen by every
     * instance, which a per-instance file cache is not.
     */
    public function up(): void
    {
        Schema::create('housekeeping_runs', static function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('task')->unique();
            // When the last successful run started. Null until one has succeeded.
            $table->timestamp('last_run_at')->nullable();
            // Held while a run is in progress; a lock past this time is abandoned.
            $table->timestamp('locked_until')->nullable();
            $table->string('locked_by', 26)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housekeeping_runs');
    }
};

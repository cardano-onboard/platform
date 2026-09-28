<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One table for every piece of background work an operator waits on.
     *
     * Six jobs already run behind the campaign page and none of them writes a state anyone
     * can read, so the only way to find out whether an import finished is to reload the
     * page and look at the codes table. A table per feature would answer "what is running
     * on this campaign" with a union that grows a branch per job, so this one is
     * polymorphic: type says which job, and what a run produced stays in the feature's own
     * table, because an export or an analysis outlives the run that wrote it.
     *
     * ULIDs because these ids travel in polled URLs, where an auto-incrementing id would
     * leak how much work the whole deployment is doing.
     *
     * The unique key is the concurrency rule. The default dedupe key means one run per
     * campaign per type; a job that has genuinely separate runs passes its own key, so two
     * of them proceed side by side without either being able to start twice.
     */
    public function up(): void
    {
        Schema::create('campaign_tasks', static function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('campaign_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('dedupe_key', 64)->default('default');
            // Who asked for it. Nullable because scheduled work has no requester, and
            // nulled rather than cascaded on delete because the run still happened.
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('status', 16)->default('queued');
            // The short label the panel shows while the job is working, such as
            // "Confirming claims". Nullable: a one-step job has nothing to say here.
            $table->string('stage', 48)->nullable();
            $table->unsignedInteger('progress_done')->default(0);
            // Nullable on purpose. A job that cannot count its work says so, and the panel
            // shows an indeterminate bar, rather than inventing a denominator.
            $table->unsignedInteger('progress_total')->nullable();
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            // The liveness signal, written explicitly by the job. Not updated_at: a row
            // whose worker is healthy but whose progress has not moved for a minute still
            // has to read as alive.
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'type', 'dedupe_key']);
            // The poller's query: everything on one campaign, by state.
            $table->index(['campaign_id', 'status']);
            // The sweep's query: runs whose worker has stopped reporting.
            $table->index(['status', 'heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_tasks');
    }
};

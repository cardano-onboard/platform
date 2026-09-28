<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One generated sticker archive, described well enough to be listed without opening it.
     *
     * This is the artifact, not the run. The run lives in campaign_tasks and is finished
     * within minutes; the archive is downloaded for days afterwards, replaced when the
     * codes change, and referred to long after the worker that built it was recycled. A row
     * is written only once a build has succeeded, so a row can never advertise an archive
     * that was never made.
     *
     * ULIDs because these ids appear in the URLs an operator's browser asks for, where an
     * auto-incrementing id would count exports across every tenant on the deployment.
     */
    public function up(): void
    {
        Schema::create('qr_exports', static function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('campaign_id')
                ->constrained()
                ->cascadeOnDelete();
            // Who asked for it. Nulled rather than cascaded when the account goes, because
            // the archive is the campaign's and outlives whoever pressed the button.
            $table->foreignId('requested_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            // The hash of everything the archive's contents depend on: the settings, the
            // codes, and the claim URL printed into every sticker. Sized for the hex digest
            // it holds.
            $table->string('cache_key', 64);
            $table->string('status', 16)->default('ready');
            // What was asked for, so the history can say what a row is and a regenerate can
            // replay it without the operator setting the dialog up again.
            $table->json('settings');
            // Where it landed, recorded rather than assumed: the configured disk can be
            // unusable at write time and fall back to another one, and a download months
            // later has to look where the bytes actually went.
            $table->string('disk', 32);
            // Nullable only for the far side of its life: once the archive has been pruned
            // the row keeps the history and the settings, and a location nothing answers at
            // is worse than no location. Every ready row has one.
            $table->string('path')->nullable();
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('codes_total');
            // What is inside, so a list can show the split without fetching the archive.
            $table->json('manifest')->nullable();
            // Advertised expiry. The disk is still the truth; this is what the page can say
            // before asking it.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // One row per archive. A regenerate under the same key replaces the row rather
            // than adding one per attempt.
            $table->unique(['campaign_id', 'cache_key']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_exports');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a claim sent back for another try may be sent again. Null means now. Set when
     * the backend reports a transaction timed out, so the resend waits out a backoff even
     * when a send run for other claims comes round sooner.
     */
    public function up(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->timestamp('retry_after')->nullable()->after('retry_count');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn('retry_after');
        });
    }
};

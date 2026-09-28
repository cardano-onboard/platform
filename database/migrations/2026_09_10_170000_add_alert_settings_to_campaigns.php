<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a campaign should tell its operator it is running short, and whether it should
     * at all.
     *
     * An operator who has funded a bucket and wants to leave the campaign alone is not
     * being helped by being told about it, and somebody running an event wants to hear
     * long before the last claim fails. Neither is the right default for the other, so it
     * is theirs to set.
     *
     * The threshold is in claims rather than in money, because that is the question an
     * operator actually has and it means the same thing on both billing paths: how many
     * more people can claim before this stops working.
     */
    public function up(): void
    {
        Schema::table('campaigns', static function (Blueprint $table) {
            $table->boolean('alerts_enabled')->default(true)->after('spend_limit_micro');
            $table->unsignedInteger('alert_threshold_claims')->nullable()->after('alerts_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', static function (Blueprint $table) {
            $table->dropColumn(['alerts_enabled', 'alert_threshold_claims']);
        });
    }
};

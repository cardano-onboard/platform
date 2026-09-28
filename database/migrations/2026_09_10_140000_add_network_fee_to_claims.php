<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the chain took, as distinct from what we did.
     *
     * An operator needs to be able to say what a campaign cost them, and the two figures
     * are not the same kind of thing. The platform fee is ours and is already recorded in
     * revenue_lovelace. The network fee is paid to the chain out of the same bucket, is
     * never revenue, and had nowhere to be written down at all.
     *
     * Stamped at the moment the claim is taken, from the rate in force then, like every
     * other money column here. A later change applies forward and never rewrites what a
     * campaign was already told it spent.
     */
    public function up(): void
    {
        Schema::table('claims', static function (Blueprint $table) {
            $table->unsignedBigInteger('network_fee_lovelace')->nullable()->after('revenue_usd');
        });
    }

    public function down(): void
    {
        Schema::table('claims', static function (Blueprint $table) {
            $table->dropColumn('network_fee_lovelace');
        });
    }
};

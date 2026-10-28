<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether the claim URL also serves a claim page to desktop browsers.
     *
     * Off by default, and every campaign that exists already reads off: a code is a
     * bearer secret, and a page that takes a code and a pasted address lets anyone who
     * holds one send the reward anywhere without a wallet. The owner turns it on per
     * campaign. The wallet endpoint at the same address is unaffected either way.
     */
    public function up(): void
    {
        Schema::table('campaigns', static function (Blueprint $table) {
            $table->boolean('desktop_claims_enabled')->default(false)->after('one_per_wallet');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', static function (Blueprint $table) {
            $table->dropColumn('desktop_claims_enabled');
        });
    }
};

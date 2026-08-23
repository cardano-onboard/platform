<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index the (policy_hex, asset_hex) pair so the known-asset autocomplete can rank
     * tokens by how often they've been used as rewards without a table scan.
     */
    public function up(): void
    {
        Schema::table('rewards', static function (Blueprint $table) {
            $table->index(['policy_hex', 'asset_hex'], 'rewards_policy_asset_index');
        });
    }

    public function down(): void
    {
        Schema::table('rewards', static function (Blueprint $table) {
            $table->dropIndex('rewards_policy_asset_index');
        });
    }
};

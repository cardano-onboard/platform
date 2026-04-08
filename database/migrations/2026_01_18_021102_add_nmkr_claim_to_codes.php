<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('codes', function (Blueprint $table) {
            $table->string('nmkr_project_uid')->nullable();
            $table->integer('nmkr_count_nft')->nullable();
            $table->integer('nmkr_response_code')->nullable();
            $table->json('nmkr_response_body')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('codes', function (Blueprint $table) {
            $table->dropColumn('nmkr_project_uid');
            $table->dropColumn('nmkr_count_nft');
        });
    }
};

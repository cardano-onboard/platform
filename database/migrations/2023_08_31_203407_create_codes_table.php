<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('codes', static function (Blueprint $table) {
            $table->id();
            $table->uuid('code')->unique();
            $table->foreignUlid('campaign_id')
                  ->constrained();
            $table->unsignedBigInteger('uses')->default(1);
            $table->unsignedBigInteger('perWallet')->default(1);
            $table->unsignedBigInteger('lovelace')->default(1000000);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('codes');
    }
};

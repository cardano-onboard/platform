<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('rewards', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('code_id')
                  ->constrained();
            $table->string('policy_hex')
                  ->nullable();
            $table->string('asset_hex')
                  ->nullable();
            $table->unsignedInteger('quantity')
                  ->default(1);
            $table->boolean('is_minted')
                  ->default(false);
            $table->foreignId('policy_id')
                  ->nullable();
            $table->json('metadata')
                  ->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('rewards');
    }
};

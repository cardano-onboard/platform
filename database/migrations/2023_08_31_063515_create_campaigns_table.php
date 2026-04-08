<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::create('campaigns', static function (Blueprint $table) {
            $table->ulid('id')
                  ->primary();
            $table->foreignId('user_id')
                  ->constrained();
            $table->string('name');
            $table->text('description')
                  ->nullable();
            $table->date('start_date');
            $table->date('end_date')
                  ->nullable();
            $table->string('status')
                  ->default('draft');
            $table->boolean('one_per_wallet')
                  ->nullable();
            $table->string('network')->default('preprod');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('campaigns');
    }
};

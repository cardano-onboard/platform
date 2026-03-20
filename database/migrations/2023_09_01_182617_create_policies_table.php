<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('policies', static function (Blueprint $table) {
            $table->ulid('id')
                  ->primary();
            $table->string('name')
                  ->unique();
            $table->longText('public_key');
            $table->longText('private_key');
            $table->string('key_hash');
            $table->string('policy_id');
            $table->json('policy');
            $table->string('policy_hash');
            $table->bigInteger('ttl');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('policies');
    }
};

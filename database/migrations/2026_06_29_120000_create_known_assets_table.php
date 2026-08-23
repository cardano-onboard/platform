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
        Schema::create('known_assets', static function (Blueprint $table) {
            $table->id();
            $table->string('ticker')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('policy_id', 56);
            $table->string('asset_name')->default(''); // hex; empty for policy-only entries
            $table->string('fingerprint')->nullable()->index();
            $table->unsignedInteger('decimals')->default(0);
            $table->longText('logo')->nullable();
            $table->text('description')->nullable();
            $table->string('network')->default('mainnet')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['policy_id', 'asset_name', 'network']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('known_assets');
    }
};

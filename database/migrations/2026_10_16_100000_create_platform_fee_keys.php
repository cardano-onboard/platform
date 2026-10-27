<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one key every collected platform fee is paid to, and the script around it.
 *
 * A fee address is the hash of its script, so everything the script is made of is stored beside
 * the key and read back from here, never recomputed from configuration. A later change to the
 * owner hashes in the environment cannot move the address, and a key that was replaced would
 * strand every fees output recorded against the old one, which is why the table holds at most one
 * row and nothing updates it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fee_keys', static function (Blueprint $table) {
            $table->id();

            // The row's only name. Unique, so two writers racing to generate the key cannot each
            // insert their own: the database refuses the second rather than a check it might have
            // passed.
            $table->string('name', 16)->default('platform');

            // The signing seed wrapped under the campaign wrapping key, as campaign keys are, and
            // the version it was wrapped under so a rotation can be verified by counting rows.
            $table->longText('skey');
            $table->string('vkey', 64);
            $table->unsignedSmallInteger('wrapping_key_version');

            // The two owner hashes the script was made with, and the script's own hash. The owners
            // are the break-glass signers on every campaign script, and they are here for the same
            // reason: they are inside the address.
            $table->json('owner_key_hashes');
            $table->string('script_hash', 56);

            $table->timestamps();

            $table->unique('name', 'platform_fee_keys_name_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_fee_keys');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a campaign needs to hold its own funds rather than have somebody else hold them.
 *
 * Everything here is written once when the campaign's wallet is created and never recomputed.
 * That is not a convention, it is the only thing that works: a campaign's address is the hash of
 * a script built from these values, and there is no migration from one address to another once
 * funds have arrived at it. A column here that could be rewritten is a campaign that could be
 * made unreachable by an ordinary update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', static function (Blueprint $table) {
            // The campaign's own signing key, encrypted. Held as text rather than binary because
            // the ciphertext is a serialised envelope, not raw bytes, and it is long enough that
            // a length limit would be a trap rather than a guard.
            $table->longText('skey')->nullable()->after('key');

            // The verification key, hex. Public by nature: it is in the script that names it and
            // in every witness the campaign ever signs.
            $table->string('vkey', 64)->nullable()->after('skey');

            // Which wrapping key the secret above was encrypted under. Kept beside the ciphertext
            // rather than assumed, so a rotation can be verified complete by counting rows and so
            // a secret written before a rotation is still readable after it.
            $table->unsignedSmallInteger('wrapping_key_version')->nullable()->after('vkey');

            // Everything a recovery needs, and the reason it is here rather than derived: a
            // recovery happens when there is no application left to derive it with.
            $table->json('recovery_record')->nullable()->after('wrapping_key_version');
        });

        Schema::table('campaigns', static function (Blueprint $table) {
            // The slot after which the platform branch of the script can no longer sign. Written
            // at creation from the campaign's authorisation window and never recomputed, because
            // it is inside the script and therefore inside the address.
            $table->unsignedBigInteger('expiry_slot')->nullable()->after('network');

            // Which of the two script shapes this campaign got, which follows who paid for it.
            $table->string('funding_path', 16)->nullable()->after('expiry_slot');

            // The connecting wallet, for a campaign the customer funded themselves. The payment
            // credential is what goes in the script; the reward address is what the customer sees
            // and what anything paid back to them is sent to.
            $table->string('customer_key_hash', 56)->nullable()->after('funding_path');
            $table->string('customer_reward_address')->nullable()->after('customer_key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', static function (Blueprint $table) {
            $table->dropColumn(['skey', 'vkey', 'wrapping_key_version', 'recovery_record']);
        });

        Schema::table('campaigns', static function (Blueprint $table) {
            $table->dropColumn(['expiry_slot', 'funding_path', 'customer_key_hash', 'customer_reward_address']);
        });
    }
};

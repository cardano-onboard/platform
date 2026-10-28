<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The moment a campaign's authorisation ends, beside the slot that says the same thing.
 *
 * The slot is what goes in the script, and it is the authority. This is the same instant written
 * the way the rest of the application reasons about time, and it exists so that capping a campaign's
 * end date is arithmetic on two dates rather than a conversion.
 *
 * Without it, checking that an edited end date still sits inside the authorisation means turning
 * that date into a slot, which means asking the chain where it is. That would put a provider call
 * on the path of an ordinary edit, and make a campaign uneditable whenever the provider was down,
 * for a question whose answer has not changed since the campaign was created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', static function (Blueprint $table) {
            $table->timestamp('expiry_at')->nullable()->after('expiry_slot');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', static function (Blueprint $table) {
            $table->dropColumn('expiry_at');
        });
    }
};

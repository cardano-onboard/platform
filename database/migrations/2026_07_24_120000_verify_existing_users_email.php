<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Grandfather existing accounts when email verification is switched on.
     *
     * Accounts created before the platform enforced email verification have a
     * null email_verified_at. Now that User implements MustVerifyEmail and the
     * app routes are gated behind the `verified` middleware, those users would
     * otherwise be locked out until they re-verified. Mark them verified once,
     * at the point of rollout, so only accounts registered from here on must
     * verify their email.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    /**
     * No-op: we cannot tell which users were backfilled versus genuinely
     * verified, so we must not blindly null email_verified_at on rollback.
     */
    public function down(): void
    {
        // Intentionally irreversible — see up().
    }
};

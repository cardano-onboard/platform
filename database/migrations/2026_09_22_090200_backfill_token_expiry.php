<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every token minted before per-token expiry existed has expires_at = null, because null
 * was Sanctum's own way of saying "governed by the global expiration setting" and that
 * setting used to be 24 hours. The moment the global setting was unset in favour of
 * stamping expiry on each token, every one of those pre-existing rows stopped being
 * bounded by anything at all: a profile-page token minted months ago and forgotten about,
 * with the full '*' ability set, became valid forever. A reviewer's probe confirmed this
 * on a live 30-day-old token, which is what this migration exists to close.
 *
 * Every null expires_at is set to created_at plus 24 hours, the exact window the global
 * setting used to enforce, so a pre-existing token now expires exactly when it always
 * would have. Done in PHP rather than a raw DATE_ADD/datetime() expression so the same
 * code runs unchanged on MySQL and SQLite; chunked by id so a deployment with a large
 * token table does not load it all into memory at once.
 *
 * down() is a no-op on purpose. Restoring null here would restore the hole this closes,
 * and nothing about rolling back the schema requires it: a token this migration touched
 * keeps a real expiry either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->orderBy('id')
            ->chunkById(500, function ($tokens) {
                foreach ($tokens as $token) {
                    DB::table('personal_access_tokens')
                        ->where('id', $token->id)
                        ->update([
                            'expires_at' => Carbon::parse($token->created_at)->addHours(24),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Intentional no-op — see the class docblock.
    }
};

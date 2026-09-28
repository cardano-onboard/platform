<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes what users and the old single-asset lookup wrote into the shared token table.
 *
 * Until now any signed-in user could post a ticker, name, decimals and logo for any asset,
 * and the lookup saved every asset Koios answered for, registry token or not: hundreds of
 * one campaign's NFTs, and CIP-68 tokens frozen at the decimals they had on first sight.
 * Those rows were read ahead of everything else and fed every account's token search.
 *
 * The registry sync always sets `metadata`; the lookup and the user endpoint never did, so
 * a row without it did not come from the registry and is deleted. The sync never writes a
 * logo, so every logo in the table came from the lookup or from a user, and the two cannot
 * be told apart: all are cleared, and each registry token's logo is fetched again from the
 * registry the next time a page shows it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('known_assets')->whereNull('metadata')->delete();
        DB::table('known_assets')->whereNotNull('logo')->update(['logo' => null]);
        // The sync writes no fingerprint either, so the same holds for it.
        DB::table('known_assets')->whereNotNull('fingerprint')->update(['fingerprint' => null]);
    }

    public function down(): void
    {
        // Deleted rows and cleared logos are re-fetched from the registry on demand.
    }
};

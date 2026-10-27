<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One wallet per campaign, said by the database rather than assumed by the code.
 *
 * `Campaign::wallet()` has always been a HasOne, and the job that creates a wallet has always
 * checked whether one exists first. Neither is a guarantee. A HasOne is a statement about which
 * row is read, not about how many exist, and a check before a write is two statements with a gap
 * between them that two workers can both pass.
 *
 * It matters more now than it did. A second wallet used to mean a duplicate row pointing at the
 * same hosted account. It now means a second key and therefore a second address, where funds sent
 * to whichever one is no longer read are held by a key nothing refers to any more.
 *
 * A campaign is also locked to its backend for life, and this is the shape of that: there is no
 * migration from one address to another, so a campaign does not get a second wallet under a
 * different backend either. Moving a campaign means winding it down and starting another.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('wallets')
            ->select('campaign_id')
            ->groupBy('campaign_id')
            ->havingRaw('count(*) > 1')
            ->pluck('campaign_id');

        if ($duplicates->isNotEmpty()) {
            // Stopped rather than resolved. Picking one row to keep would be choosing which
            // address a campaign's funds are at, which is not a decision a migration should make
            // quietly at deploy time.
            throw new RuntimeException(sprintf(
                'These campaigns hold more than one wallet, so a unique constraint cannot be added until '
                .'somebody decides which wallet each one keeps: %s',
                $duplicates->implode(', '),
            ));
        }

        Schema::table('wallets', static function (Blueprint $table) {
            $table->unique('campaign_id', 'wallets_campaign_unique');
        });
    }

    public function down(): void
    {
        // The foreign key on campaign_id needs an index on MySQL, and MySQL drops the one it made
        // for the key once this unique index can serve instead. So a plain index goes on first, in
        // its own statement, and only then can the unique one come off. The other way round MySQL
        // refuses to drop an index a foreign key still needs.
        Schema::table('wallets', static function (Blueprint $table) {
            $table->index('campaign_id', 'wallets_campaign_id_index');
        });

        Schema::table('wallets', static function (Blueprint $table) {
            $table->dropUnique('wallets_campaign_unique');
        });
    }
};

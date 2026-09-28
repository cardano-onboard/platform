<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who runs the deployment, as a fact about the account rather than a match on an
     * environment variable.
     *
     * The seeded administrator was previously identifiable only by having the email in
     * ADMIN_EMAIL, which means the operator's identity changes whenever that variable does
     * and there is no way to have two. A column makes promotion a deliberate act with a
     * record of it, and gives the authorization gate something to read.
     *
     * Existing installs get false for every account, including the seeded administrator.
     * The seeder sets it on its next run, and nothing gains access by upgrading.
     */
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Rows read and updated per statement. */
    public int $chunk = 1000;

    /**
     * Completes pending and failed claims that carry a real transaction hash. The queries are
     * written out here rather than taken from the model scopes and the backfill service, so a
     * later change to those cannot change what this migration does. The service is what
     * `claims:complete-confirmed` runs after the deploy.
     *
     * A real hash is non-empty and does not start with null-tx-, which the null backend
     * writes. Rows are walked by id in chunks, and each update repeats the status test so a
     * claim changed since it was read is not overwritten.
     */
    public function up(): void
    {
        $lastId = 0;

        while (true) {
            $ids = DB::table('claims')
                ->where('id', '>', $lastId)
                ->whereIn('status', ['pending', 'failed'])
                ->whereNotNull('transaction_hash')
                ->where('transaction_hash', '!=', '')
                ->where('transaction_hash', 'not like', 'null-tx-%')
                ->orderBy('id')
                ->limit($this->chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            DB::table('claims')
                ->whereIn('id', $ids)
                ->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'completed']);

            $lastId = $ids->last();
        }
    }

    /**
     * Nothing to undo. A claim completed here cannot be told apart from one that was
     * completed by the status check, so no rows are guessed back to their old status.
     */
    public function down(): void
    {
        //
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_sync_states', function (Blueprint $table) {
            $table->text('cursor')->nullable()->change();
        });
    }

    public function down(): void
    {
        $this->clearCursorsLongerThan(512);

        Schema::table('inbox_sync_states', function (Blueprint $table) {
            $table->string('cursor', 512)->nullable()->change();
        });
    }

    /**
     * A cursor that would no longer fit is cleared, so the next poll starts
     * over from the backfill window instead of the rollback failing.
     */
    public function clearCursorsLongerThan(int $length): void
    {
        DB::table('inbox_sync_states')
            ->whereNotNull('cursor')
            ->select(['id', 'cursor'])
            ->lazyById()
            ->filter(fn (object $state): bool => mb_strlen((string) $state->cursor) > $length)
            ->each(fn (object $state) => DB::table('inbox_sync_states')->where('id', $state->id)->update(['cursor' => null]));
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PostgreSQL takes a SHARE lock for the whole of a plain CREATE INDEX, which
     * blocks every write to post_platforms while it builds. Publishing writes to
     * that table constantly, so the index is built concurrently instead — which
     * cannot run inside a transaction. MySQL builds indexes online by default.
     */
    public $withinTransaction = false;

    private const NAME = 'post_platforms_platform_post_id_index';

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::NAME.' ON post_platforms (platform_post_id)');

            return;
        }

        Schema::table('post_platforms', function (Blueprint $table): void {
            $table->index('platform_post_id', self::NAME);
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::NAME);

            return;
        }

        Schema::table('post_platforms', function (Blueprint $table): void {
            $table->dropIndex(self::NAME);
        });
    }
};

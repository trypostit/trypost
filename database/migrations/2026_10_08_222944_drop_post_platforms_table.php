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
        $unmirrored = DB::table('post_platforms')
            ->whereNotIn('id', DB::table('posts')->whereNotNull('legacy_target_id')->select('legacy_target_id'))
            ->count();

        if ($unmirrored > 0) {
            throw new RuntimeException("{$unmirrored} destinations were not copied to their posts; post_platforms was kept.");
        }

        $unlinked = DB::table('analytics_publications')->whereNotNull('post_platform_id')->whereNull('post_id')->count();

        if ($unlinked > 0) {
            throw new RuntimeException("{$unlinked} analytics publications were not linked to their posts; post_platforms was kept.");
        }

        Schema::table('analytics_publications', function (Blueprint $table): void {
            $table->dropForeign(['post_platform_id']);
            $table->dropUnique(['post_platform_id']);
            $table->dropColumn('post_platform_id');
        });

        Schema::dropIfExists('post_platforms');
    }
};

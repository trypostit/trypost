<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $unmirrored = DB::table('post_platforms')
            ->whereNotExists(fn (Builder $query) => $query->select(DB::raw(1))
                ->from('posts')
                ->whereColumn('posts.id', 'post_platforms.post_id')
                ->whereColumn('posts.platform', 'post_platforms.platform')
                ->whereColumn('posts.social_account_id', 'post_platforms.social_account_id'))
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

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
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->string('timezone')->default('UTC');
            $table->unsignedTinyInteger('posting_goal')->nullable();
            $table->json('posting_schedule')->nullable();
        });

        $pausedPostIds = DB::table('post_platforms')
            ->join('social_accounts', 'social_accounts.id', '=', 'post_platforms.social_account_id')
            ->where('post_platforms.enabled', true)
            ->where('social_accounts.is_active', false)
            ->pluck('post_platforms.post_id')
            ->unique()
            ->values();

        foreach ($pausedPostIds->chunk(500) as $chunk) {
            DB::table('posts')
                ->whereIn('id', $chunk->all())
                ->where('status', 'scheduled')
                ->update(['status' => 'draft', 'updated_at' => now()]);
        }

        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('status');
            $table->dropColumn(['timezone', 'posting_goal', 'posting_schedule']);
        });
    }
};

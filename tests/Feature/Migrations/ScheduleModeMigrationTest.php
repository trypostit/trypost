<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_09_29_183717_add_schedule_mode_to_posts_table.php');
    $this->seeded = ['posts' => [], 'workspaces' => []];

    if (Schema::hasColumn('posts', 'schedule_mode')) {
        $this->migration->down();
    }
});

afterEach(function () {
    if (! Schema::hasColumn('posts', 'schedule_mode')) {
        $this->migration->up();
    }

    $workspaces = DB::table('workspaces')->whereIn('id', $this->seeded['workspaces']);
    $userIds = (clone $workspaces)->pluck('user_id')->all();
    $accountIds = (clone $workspaces)->pluck('account_id')->all();

    foreach (['posts', 'workspaces'] as $table) {
        DB::table($table)->whereIn('id', $this->seeded[$table])->delete();
    }

    DB::table('users')->whereIn('id', $userIds)->delete();
    DB::table('accounts')->whereIn('id', $accountIds)->delete();
});

test('backfills scheduled posts as custom and leaves other posts untouched', function () {
    $workspace = Workspace::factory()->create();
    $this->seeded['workspaces'][] = $workspace->id;

    $insert = function (PostStatus $status) use ($workspace): string {
        $id = (string) Str::uuid();
        DB::table('posts')->insert([
            'id' => $id,
            'workspace_id' => $workspace->id,
            'user_id' => $workspace->user_id,
            'status' => $status->value,
            'scheduled_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->seeded['posts'][] = $id;

        return $id;
    };

    $scheduled = $insert(PostStatus::Scheduled);
    $draft = $insert(PostStatus::Draft);

    $this->migration->up();

    expect(Post::find($scheduled)->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and(Post::find($draft)->schedule_mode)->toBeNull();
});

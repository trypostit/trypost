<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_02_141102_flatten_replies_and_drop_reactions_from_post_notes_table.php');
    $this->workspaceIds = [];
});

afterEach(function () {
    if (Schema::hasColumn('post_notes', 'parent_id')) {
        $this->migration->up();
    }

    $workspaces = DB::table('workspaces')->whereIn('id', $this->workspaceIds);
    $userIds = (clone $workspaces)->pluck('user_id')->all();
    $accountIds = (clone $workspaces)->pluck('account_id')->all();

    DB::table('posts')->whereIn('workspace_id', $this->workspaceIds)->delete();
    DB::table('workspaces')->whereIn('id', $this->workspaceIds)->delete();
    DB::table('users')->whereIn('id', $userIds)->delete();
    DB::table('accounts')->whereIn('id', $accountIds)->delete();
});

test('replies become top-level notes and the reply and reaction columns are dropped', function () {
    $this->migration->down();

    $workspace = Workspace::factory()->create();
    $this->workspaceIds[] = $workspace->id;
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $workspace->user_id]);
    $parentId = (string) Str::uuid7();
    $replyId = (string) Str::uuid7();

    DB::table('post_notes')->insert([
        [
            'id' => $parentId,
            'post_id' => $post->id,
            'user_id' => $workspace->user_id,
            'parent_id' => null,
            'body' => 'Parent note',
            'reactions' => json_encode([['user_id' => $workspace->user_id, 'emoji' => '👍']]),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ],
        [
            'id' => $replyId,
            'post_id' => $post->id,
            'user_id' => $workspace->user_id,
            'parent_id' => $parentId,
            'body' => 'Reply note',
            'reactions' => null,
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ],
    ]);

    $this->migration->up();

    expect(Schema::hasColumn('post_notes', 'parent_id'))->toBeFalse()
        ->and(Schema::hasColumn('post_notes', 'reactions'))->toBeFalse()
        ->and($post->notes()->oldest()->pluck('body')->all())->toBe(['Parent note', 'Reply note']);

    DB::table('post_notes')->where('id', $parentId)->delete();

    expect(DB::table('post_notes')->where('id', $replyId)->exists())->toBeTrue();
});

test('down re-adds the nullable reply and reaction columns', function () {
    $this->migration->down();

    expect(Schema::hasColumns('post_notes', ['parent_id', 'reactions']))->toBeTrue();
});

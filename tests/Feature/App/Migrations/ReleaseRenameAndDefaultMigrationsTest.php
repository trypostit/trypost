<?php

declare(strict_types=1);

use App\Enums\Post\Origin;
use App\Models\NotificationPreference;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->renameNotes = require database_path('migrations/2026_09_25_131638_rename_post_comments_to_post_notes.php');
    $this->renamePreference = require database_path('migrations/2026_09_30_183633_rename_mentioned_in_comment_to_post_note_added_on_notification_preferences_table.php');
    $this->addGroup = require database_path('migrations/2026_10_01_182550_add_post_group_id_to_posts_table.php');
    $this->addOrigin = require database_path('migrations/2026_10_01_205103_add_origin_to_posts_table.php');
    $this->workspaceIds = [];
});

afterEach(function () {
    if (Schema::hasTable('post_comments')) {
        $this->renameNotes->up();
    }

    if (Schema::hasColumn('notification_preferences', 'mentioned_in_comment')) {
        $this->renamePreference->up();
    }

    if (! Schema::hasColumn('posts', 'post_group_id')) {
        $this->addGroup->up();
    }

    if (! Schema::hasColumn('posts', 'origin')) {
        $this->addOrigin->up();
    }

    $workspaces = DB::table('workspaces')->whereIn('id', $this->workspaceIds);
    $userIds = (clone $workspaces)->pluck('user_id')->all();
    $accountIds = (clone $workspaces)->pluck('account_id')->all();

    DB::table('post_notes')->whereIn('post_id', DB::table('posts')->whereIn('workspace_id', $this->workspaceIds)->select('id'))->delete();
    DB::table('posts')->whereIn('workspace_id', $this->workspaceIds)->delete();
    DB::table('workspaces')->whereIn('id', $this->workspaceIds)->delete();
    DB::table('notification_preferences')->whereIn('user_id', $userIds)->delete();
    DB::table('users')->whereIn('id', $userIds)->delete();
    DB::table('accounts')->whereIn('id', $accountIds)->delete();
});

test('renaming post comments to post notes keeps every row and its values', function () {
    $workspace = Workspace::factory()->create();
    $this->workspaceIds[] = $workspace->id;
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $workspace->user_id]);
    $note = PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $workspace->user_id]);
    $before = (array) DB::table('post_notes')->where('id', $note->id)->first();

    $this->renameNotes->down();

    expect(Schema::hasTable('post_notes'))->toBeFalse()
        ->and((array) DB::table('post_comments')->where('id', $note->id)->first())->toEqual($before);

    $this->renameNotes->up();

    expect((array) DB::table('post_notes')->where('id', $note->id)->first())->toEqual($before);
});

test('renaming mentioned_in_comment to post_note_added keeps each user choice', function () {
    $workspace = Workspace::factory()->create();
    $this->workspaceIds[] = $workspace->id;
    NotificationPreference::query()->where('user_id', $workspace->user_id)->delete();
    $off = NotificationPreference::factory()->create(['user_id' => $workspace->user_id, 'post_note_added' => false]);

    $this->renamePreference->down();
    $this->renamePreference->up();

    expect($off->fresh()->post_note_added)->toBeFalse();
});

test('existing posts get no group and the trypost origin, and keep everything else', function () {
    $workspace = Workspace::factory()->create();
    $this->workspaceIds[] = $workspace->id;
    $post = Post::factory()->scheduled()->create(['workspace_id' => $workspace->id, 'user_id' => $workspace->user_id, 'content' => 'Kept caption']);

    $this->addOrigin->down();
    $this->addGroup->down();
    $this->addGroup->up();
    $this->addOrigin->up();

    $fresh = $post->fresh();

    expect($fresh->post_group_id)->toBeNull()
        ->and($fresh->origin)->toBe(Origin::TryPost)
        ->and($fresh->content)->toBe('Kept caption')
        ->and($fresh->status)->toBe($post->status)
        ->and($fresh->scheduled_at->equalTo($post->scheduled_at))->toBeTrue();
});

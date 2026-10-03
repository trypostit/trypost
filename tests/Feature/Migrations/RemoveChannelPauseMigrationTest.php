<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_09_29_133421_add_posting_schedule_and_remove_is_active_on_social_accounts.php');
    $this->seeded = ['post_platforms' => [], 'posts' => [], 'social_accounts' => [], 'workspaces' => []];

    if (! Schema::hasColumn('social_accounts', 'is_active')) {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
    }

    $this->dropPostingSchedule = fn () => Schema::dropColumns('social_accounts', ['timezone', 'posting_goal', 'posting_schedule']);
});

afterEach(function () {
    if (Schema::hasColumn('social_accounts', 'is_active')) {
        Schema::hasColumn('social_accounts', 'timezone')
            ? Schema::dropColumns('social_accounts', ['is_active'])
            : $this->migration->up();
    }

    $workspaces = DB::table('workspaces')->whereIn('id', $this->seeded['workspaces']);
    $userIds = (clone $workspaces)->pluck('user_id')->all();
    $accountIds = (clone $workspaces)->pluck('account_id')->all();

    foreach (['post_platforms', 'posts', 'social_accounts', 'workspaces'] as $table) {
        DB::table($table)->whereIn('id', $this->seeded[$table])->delete();
    }

    DB::table('users')->whereIn('id', $userIds)->delete();
    DB::table('accounts')->whereIn('id', $accountIds)->delete();
});

test('turns scheduled posts on paused channels into drafts and drops the column', function () {
    $workspace = Workspace::factory()->create();
    $this->seeded['workspaces'][] = $workspace->id;
    $scheduledAt = now()->addDay()->startOfSecond();

    $seed = function (SocialAccount $account, PostStatus $status, bool $enabled = true) use ($workspace, $scheduledAt): Post {
        $post = Post::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $workspace->user_id,
            'status' => $status,
            'scheduled_at' => $scheduledAt,
        ]);
        $postPlatform = PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            'enabled' => $enabled,
        ]);
        $this->seeded['posts'][] = $post->id;
        $this->seeded['post_platforms'][] = $postPlatform->id;

        return $post;
    };

    $active = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $paused = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->seeded['social_accounts'] = [$active->id, $paused->id];
    DB::table('social_accounts')->where('id', $paused->id)->update(['is_active' => false]);

    $activeScheduled = $seed($active, PostStatus::Scheduled);
    $activeDraft = $seed($active, PostStatus::Draft);
    $pausedScheduled = $seed($paused, PostStatus::Scheduled);
    $pausedDraft = $seed($paused, PostStatus::Draft);
    $pausedPublishing = $seed($paused, PostStatus::Publishing);
    $pausedPublished = $seed($paused, PostStatus::Published);
    $pausedDisabled = $seed($paused, PostStatus::Scheduled, enabled: false);

    ($this->dropPostingSchedule)();
    $this->migration->up();

    expect(Schema::hasColumn('social_accounts', 'is_active'))->toBeFalse()
        ->and(Schema::hasColumns('social_accounts', ['timezone', 'posting_goal', 'posting_schedule']))->toBeTrue()
        ->and($pausedScheduled->fresh()->status)->toBe(PostStatus::Draft)
        ->and($pausedScheduled->fresh()->scheduled_at->equalTo($scheduledAt))->toBeTrue()
        ->and($activeScheduled->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($activeDraft->fresh()->status)->toBe(PostStatus::Draft)
        ->and($pausedDraft->fresh()->status)->toBe(PostStatus::Draft)
        ->and($pausedPublishing->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($pausedPublished->fresh()->status)->toBe(PostStatus::Published)
        ->and($pausedDisabled->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('down restores is_active and drops the posting schedule columns', function () {
    ($this->dropPostingSchedule)();
    $this->migration->up();
    $this->migration->down();

    expect(Schema::hasColumn('social_accounts', 'is_active'))->toBeTrue()
        ->and(Schema::hasColumn('social_accounts', 'timezone'))->toBeFalse()
        ->and(Schema::hasColumn('social_accounts', 'posting_goal'))->toBeFalse()
        ->and(Schema::hasColumn('social_accounts', 'posting_schedule'))->toBeFalse();
});

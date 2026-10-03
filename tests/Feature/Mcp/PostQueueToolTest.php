<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Mcp\Tools\SocialAccount\ListSocialAccountsTool;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'America/Sao_Paulo'));

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'America/Sao_Paulo',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00'),
    ]);
});

function mcpQueueDraft(object $test): Post
{
    $post = Post::factory()->create([
        'workspace_id' => $test->workspace->id,
        'user_id' => $test->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
        'content' => 'Draft',
    ]);
    PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $test->channel->id,
        'enabled' => true,
    ]);

    return $post;
}

test('create posts with queue next queues the post', function () {
    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Queued',
        'destinations' => [['social_account_id' => $this->channel->id, 'content_type' => ContentType::LinkedInPost->value]],
    ])->assertOk();

    $post = Post::query()->where('workspace_id', $this->workspace->id)->sole();
    expect($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at)->not->toBeNull();
});

test('update post with queue next queues the post', function () {
    $post = mcpQueueDraft($this);

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'status' => 'scheduled',
        'queue' => 'next',
    ])->assertOk();

    expect($post->fresh()->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->fresh()->scheduled_at)->not->toBeNull();
});

test('publish post with queue top queues the post', function () {
    $post = mcpQueueDraft($this);

    TryPostServer::actingAs($this->user)->tool(PublishPostTool::class, [
        'post_id' => $post->id,
        'queue' => 'top',
    ])->assertOk();

    $post->refresh();
    expect($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at)->not->toBeNull();
});

test('publish post with queue next keeps the slot of an already queued post', function () {
    $post = mcpQueueDraft($this);
    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'status' => 'scheduled',
        'queue' => 'next',
    ])->assertOk();
    $slot = $post->refresh()->scheduled_at->toIso8601String();

    TryPostServer::actingAs($this->user)->tool(PublishPostTool::class, [
        'post_id' => $post->id,
        'queue' => 'next',
    ])->assertOk();

    $post->refresh();
    expect($post->scheduled_at->toIso8601String())->toBe($slot)
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->status)->toBe(PostStatus::Scheduled);
});

test('a busy channel queue returns the queue busy error', function () {
    $this->travelBack();
    $post = mcpQueueDraft($this);
    $lock = Cache::lock("queue:{$this->channel->id}", 10);
    expect($lock->get())->toBeTrue();

    TryPostServer::actingAs($this->user)->tool(PublishPostTool::class, [
        'post_id' => $post->id,
        'queue' => 'next',
    ])->assertHasErrors([__('posts.errors.queue_busy')]);

    $lock->release();

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
});

test('queue combined with scheduled_at is rejected by the tools', function () {
    $post = mcpQueueDraft($this);
    $message = __('posts.errors.queue_with_scheduled_at');

    TryPostServer::actingAs($this->user)->tool(PublishPostTool::class, [
        'post_id' => $post->id,
        'queue' => 'next',
        'scheduled_at' => '2027-01-01T10:00:00Z',
    ])->assertHasErrors([$message]);

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'status' => 'scheduled',
        'queue' => 'next',
        'scheduled_at' => '2027-01-01T10:00:00Z',
    ])->assertHasErrors([$message]);

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->schedule_mode)->toBe($post->getOriginal('schedule_mode'))
        ->and($post->scheduled_at)->toBeNull();
});

test('update post with queue on a channel without posting times is rejected', function () {
    $bare = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
    $post = mcpQueueDraft($this);
    $post->postPlatforms()->update(['social_account_id' => $bare->id]);
    $mode = $post->fresh()->schedule_mode;

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'status' => 'scheduled',
        'queue' => 'next',
    ])->assertHasErrors([__('posts.errors.queue_requires_schedule')]);

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->schedule_mode)->toBe($mode)
        ->and($post->scheduled_at)->toBeNull();
});

test('list social accounts shows has_posting_schedule', function () {
    TryPostServer::actingAs($this->user)
        ->tool(ListSocialAccountsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('social_accounts.0.has_posting_schedule', true)->etc();
        });
});

test('list social accounts shows false for an account without posting times', function () {
    $this->channel->delete();
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(ListSocialAccountsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('social_accounts.0.has_posting_schedule', false)->etc();
        });
});

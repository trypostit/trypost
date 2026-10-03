<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'America/Sao_Paulo'));

    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token']];

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'America/Sao_Paulo',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00'),
    ]);
    $this->bareChannel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

test('single create with queue next queues the post on a slot', function () {
    $response = $this->withHeaders($this->headers)->postJson(route('api.posts.store'), [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Queued',
        'platforms' => [['social_account_id' => $this->channel->id, 'content_type' => ContentType::LinkedInPost->value]],
    ]);

    $response->assertCreated()->assertJsonPath('schedule_mode', ScheduleMode::Queue->value);
    expect($response->json('scheduled_at'))->not->toBeNull();
});

test('batch create with queue next queues each post', function () {
    $response = $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Queued',
        'destinations' => [['social_account_id' => $this->channel->id, 'content_type' => ContentType::LinkedInPost->value]],
    ]);

    $response->assertCreated()->assertJsonPath('posts.0.schedule_mode', ScheduleMode::Queue->value);
    expect($response->json('posts.0.scheduled_at'))->not->toBeNull();
});

test('update with queue next queues a draft', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
        'content' => 'Draft',
    ]);
    PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->channel->id,
        'enabled' => true,
    ]);

    $response = $this->withHeaders($this->headers)->putJson(route('api.posts.update', $post), [
        'status' => 'scheduled',
        'queue' => 'next',
    ]);

    $response->assertOk()->assertJsonPath('schedule_mode', ScheduleMode::Queue->value);
    expect($response->json('scheduled_at'))->not->toBeNull();
});

test('update with queue next and a null scheduled_at keeps the slot of a queued post', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
        'content' => 'Draft',
    ]);
    PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->channel->id,
        'enabled' => true,
    ]);
    $this->withHeaders($this->headers)->putJson(route('api.posts.update', $post), [
        'status' => 'scheduled',
        'queue' => 'next',
    ])->assertOk();
    $slot = $post->refresh()->scheduled_at->toIso8601String();

    $response = $this->withHeaders($this->headers)->putJson(route('api.posts.update', $post), [
        'status' => 'scheduled',
        'queue' => 'next',
        'scheduled_at' => null,
    ]);

    $response->assertOk()->assertJsonPath('schedule_mode', ScheduleMode::Queue->value);
    $post->refresh();
    expect($post->scheduled_at->toIso8601String())->toBe($slot)
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->status)->toBe(PostStatus::Scheduled);
});

test('queue combined with scheduled_at is rejected', function () {
    $this->withHeaders($this->headers)->postJson(route('api.posts.store'), [
        'status' => 'scheduled',
        'queue' => 'next',
        'scheduled_at' => '2027-01-01T10:00:00Z',
        'content' => 'Queued',
        'platforms' => [['social_account_id' => $this->channel->id, 'content_type' => ContentType::LinkedInPost->value]],
    ])->assertUnprocessable()->assertJsonValidationErrorFor('queue');

    $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), [
        'status' => 'scheduled',
        'queue' => 'next',
        'scheduled_at' => '2027-01-01T10:00:00Z',
        'content' => 'Queued',
        'destinations' => [['social_account_id' => $this->channel->id, 'content_type' => ContentType::LinkedInPost->value]],
    ])->assertUnprocessable()->assertJsonValidationErrorFor('queue');
});

test('queue on a channel without posting times is rejected', function () {
    $this->withHeaders($this->headers)->postJson(route('api.posts.store'), [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Queued',
        'platforms' => [['social_account_id' => $this->bareChannel->id, 'content_type' => ContentType::LinkedInPost->value]],
    ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)->assertJsonValidationErrorFor('destinations.0.social_account_id');

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(0);

    $this->withHeaders($this->headers)->postJson(route('api.posts.batch.store'), [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Queued',
        'destinations' => [['social_account_id' => $this->bareChannel->id, 'content_type' => ContentType::LinkedInPost->value]],
    ])->assertUnprocessable()->assertJsonValidationErrorFor('destinations.0.social_account_id');
});

test('social accounts expose has_posting_schedule', function () {
    $response = $this->withHeaders($this->headers)->getJson(route('api.social-accounts.index'))->assertOk();

    $byId = collect($response->json())->keyBy('id');
    expect($byId[$this->channel->id]['has_posting_schedule'])->toBeTrue()
        ->and($byId[$this->bareChannel->id]['has_posting_schedule'])->toBeFalse();
});

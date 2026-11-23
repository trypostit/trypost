<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\PostingSchedule;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

/** An X draft whose channel was connected without the scope X needs to publish. */
function postOnChannelWithoutPublishScope(): Post
{
    $post = pollParityPost(ContentType::XPost);
    $post->socialAccount->update(['scopes' => ['tweet.read', 'users.read']]);

    return $post;
}

test('a channel without the publish scope cannot schedule on the web, api and mcp, and the refusal names the network', function (string $surface) {
    $post = postOnChannelWithoutPublishScope();
    $message = __('channels.permissions.publish.message', ['network' => Platform::X->label()]);

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    match ($surface) {
        'mcp' => $response->assertHasErrors([$message]),
        default => $response->assertUnprocessable()->assertJsonValidationErrors(['social_account_id' => $message]),
    };
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp']);

test('a channel without the publish scope still saves a draft on the web, api and mcp', function (string $surface) {
    $post = postOnChannelWithoutPublishScope();

    $response = pollParityUpdate($surface, $post, ['content' => 'Kept as a draft']);

    match ($surface) {
        'mcp' => $response->assertOk(),
        default => $response->assertSessionHasNoErrors(),
    };
    expect($post->fresh()->content)->toContain('Kept as a draft');
})->with(['web', 'api', 'mcp']);

test('publishing a stored post on a channel without the publish scope is refused before anything goes out', function () {
    $post = postOnChannelWithoutPublishScope();

    TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id])
        ->assertHasErrors([__('channels.permissions.publish.message', ['network' => Platform::X->label()])]);

    expect($post->fresh()->status)->toBe(Status::Draft);
});

test('scheduling to several channels refuses only the one that lacks the publish scope, naming its destination', function () {
    $granted = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $lacking = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'scopes' => ['tweet.read', 'users.read']]);

    TryPostServer::actingAs($this->user)->tool(CreatePostsTool::class, [
        'content' => 'Two channels',
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'destinations' => [
            ['social_account_id' => $granted->id, 'content_type' => ContentType::XPost->value],
            ['social_account_id' => $lacking->id, 'content_type' => ContentType::XPost->value],
        ],
    ])->assertHasErrors([__('channels.permissions.publish.message', ['network' => Platform::X->label()])]);

    expect($this->workspace->posts()->onChannels([$granted->id, $lacking->id])->exists())->toBeFalse();
});

test('a channel missing only analytics scopes still schedules on the web, api and mcp', function (string $surface) {
    $post = pollParityPost(ContentType::XPost);
    $post->socialAccount->update(['scopes' => ['tweet.write']]);

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    match ($surface) {
        'mcp' => $response->assertOk(),
        default => $response->assertSessionHasNoErrors(),
    };
    expect($post->fresh()->status)->toBe(Status::Scheduled);
})->with(['web', 'api', 'mcp']);

test('the web composer refuses only the channel that lacks the publish scope, on its own destination', function () {
    $granted = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $lacking = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'scopes' => ['tweet.read', 'users.read']]);

    $this->actingAs($this->user)->postJson(route('app.posts.store'), [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Two channels',
        'destinations' => [
            ['social_account_id' => $granted->id, 'content_type' => ContentType::XPost->value],
            ['social_account_id' => $lacking->id, 'content_type' => ContentType::XPost->value],
        ],
    ])->assertJsonValidationErrors(['destinations.1.social_account_id'])
        ->assertJsonMissingValidationErrors(['destinations.0.social_account_id']);
});

test('a thread on a channel without the replies scope is refused on its replies on the web, api and mcp', function (string $surface) {
    gateFeature(Platform::X, GatedFeature::ThreadReplies, ['tweet.moderate.write']);
    $post = pollParityPost(ContentType::XPost);
    $message = __('channels.permissions.thread_replies.message', ['network' => Platform::X->label()]);

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'meta' => ['thread_replies' => [['text' => 'Second post', 'media' => []]]],
    ]);

    match ($surface) {
        'mcp' => $response->assertHasErrors([$message]),
        default => $response->assertUnprocessable()->assertJsonValidationErrors(['meta.thread_replies' => $message]),
    };
    expect($post->fresh()->status)->toBe(Status::Draft);
})->with(['web', 'api', 'mcp']);

test('a member who cannot reconnect the channel is told an admin needs to, on the web and mcp', function (string $surface) {
    $member = workspaceMember($this->workspace);
    $post = postOnChannelWithoutPublishScope();
    $post->update(['user_id' => $member->id]);
    $this->user = $member;
    $message = __('channels.permissions.publish.member_message', ['network' => Platform::X->label()]);

    $response = pollParityUpdate($surface, $post, [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ]);

    match ($surface) {
        'mcp' => $response->assertHasErrors([$message]),
        default => $response->assertUnprocessable()->assertJsonValidationErrors(['social_account_id' => $message]),
    };
})->with(['web', 'mcp']);

test('publishing now or rescheduling a post on a channel without the publish scope is refused and leaves it as it was', function (array $action) {
    $post = postOnChannelWithoutPublishScope();
    $at = now()->addDays(2)->startOfHour();
    $post->update(['status' => Status::Scheduled, 'scheduled_at' => $at]);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), $action)
        ->assertSessionHasErrors(['social_account_id' => __('channels.permissions.publish.message', ['network' => Platform::X->label()])]);

    $post->refresh();
    expect($post->status)->toBe(Status::Scheduled)
        ->and($post->scheduled_at->equalTo($at))->toBeTrue();
})->with([
    'publish now' => [['action' => 'publish_now']],
    'reschedule' => [fn (): array => ['action' => 'reschedule', 'scheduled_at' => now()->addDays(3)->startOfHour()->toIso8601String()]],
]);

test('a member creating a post on a channel without the publish scope is told an admin needs to reconnect it', function () {
    $member = workspaceMember($this->workspace);
    $lacking = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'scopes' => ['tweet.read', 'users.read']]);

    TryPostServer::actingAs($member)->tool(CreatePostsTool::class, [
        'content' => 'From a member',
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'destinations' => [['social_account_id' => $lacking->id, 'content_type' => ContentType::XPost->value]],
    ])->assertHasErrors([__('channels.permissions.publish.member_message', ['network' => Platform::X->label()])]);
});

test('adding a post on a channel without the publish scope to the queue is refused and leaves it a draft', function (string $action) {
    $post = postOnChannelWithoutPublishScope();
    $schedule = collect(range(0, 6))->reduce(fn (PostingSchedule $schedule, int $day): PostingSchedule => $schedule->withTime($day, '12:00'), PostingSchedule::empty());
    $post->socialAccount->update(['posting_schedule' => $schedule]);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => $action])
        ->assertSessionHasErrors(['social_account_id' => __('channels.permissions.publish.message', ['network' => Platform::X->label()])]);

    expect($post->fresh())
        ->status->toBe(Status::Draft)
        ->scheduled_at->toBeNull();
})->with(['queue_next', 'queue_top']);

test('a member whose posts need approval is told an admin needs to reconnect the channel, and no request goes to approval', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $lacking = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'scopes' => ['tweet.read', 'users.read']]);

    TryPostServer::actingAs($member)->tool(CreatePostsTool::class, [
        'content' => 'From a member who needs approval',
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'destinations' => [['social_account_id' => $lacking->id, 'content_type' => ContentType::XPost->value]],
    ])->assertHasErrors([__('channels.permissions.publish.member_message', ['network' => Platform::X->label()])]);

    expect($lacking->posts()->exists())->toBeFalse();
});

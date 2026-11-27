<?php

declare(strict_types=1);

use App\Console\Commands\ProcessScheduledPosts;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->owner->id, 'account_id' => $this->owner->account_id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->account->update(['trial_ends_at' => now()->addWeek()]);
    $this->member = workspaceMember($this->workspace);
    $this->channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $this->draft = Post::factory()->forAccount($this->channel)->create([
        'user_id' => $this->member->id,
        'content' => 'A caption',
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
    ]);

    $this->owner->forceFill(['blocked_at' => now(), 'blocked_reason' => 'Spam'])->save();
});

test('publishing now by another member waits while the account owner is blocked', function () {
    $this->actingAs($this->member)
        ->put(route('app.posts.schedule.update', $this->draft), ['action' => 'publish_now'])
        ->assertSessionHasNoErrors();

    $post = $this->draft->fresh();

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->publish_status)->toBe(PublishStatus::Pending)
        ->and($post->scheduled_at)->not->toBeNull();
    Http::assertNothingSent();
});

test('publishing now through the API waits while the account owner is blocked', function () {
    $token = passportToken(workspaceMember($this->workspace, 'admin'), $this->workspace);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->putJson(route('api.posts.update', $this->draft), ['status' => 'publishing'])
        ->assertOk();

    expect($this->draft->fresh()->status)->toBe(PostStatus::Scheduled);
    Http::assertNothingSent();
});

test('a post left waiting for a blocked owner publishes through the scheduler once unblocked', function () {
    Queue::fake();
    $this->draft->forceFill(['status' => PostStatus::Publishing, 'scheduled_at' => now()])->save();

    (new PublishToSocialPlatform($this->draft->fresh()))->handle();

    expect($this->draft->fresh()->status)->toBe(PostStatus::Scheduled);

    $this->owner->forceFill(['blocked_at' => null, 'blocked_reason' => null])->save();
    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    expect($this->draft->fresh()->status)->toBe(PostStatus::Publishing);
});

test('a limit retry waits while the account owner is blocked', function () {
    Queue::fake();
    $this->draft->forceFill(['status' => PostStatus::Publishing, 'scheduled_at' => now()->subHour()])->save();
    $this->draft->writePublication(['publish_status' => PublishStatus::Retrying, 'retry_at' => now()->subMinute()]);

    expect(Post::query()->dueForLimitRetry()->exists())->toBeFalse();

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertNotPushed(PublishToSocialPlatform::class);
    expect($this->draft->fresh()->retry_at)->not->toBeNull();

    $this->owner->forceFill(['blocked_at' => null])->save();

    expect(Post::query()->dueForLimitRetry()->whereKey($this->draft->id)->exists())->toBeTrue();
});

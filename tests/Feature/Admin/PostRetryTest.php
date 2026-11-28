<?php

declare(strict_types=1);

use App\Actions\Admin\RetryPost;
use App\Enums\Activity\LogName;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as SocialAccountStatus;
use App\Http\Controllers\Admin\PostRetryController;
use App\Http\Requests\Admin\Post\RetryPostRequest;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Activity;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Social\PublishCheckpoint;
use App\Support\Social\ThreadProgress;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->admin = admin();
});

function adminFailedPostOn(Platform $platform = Platform::X, array $attributes = []): Post
{
    $owner = workspaceOwner(['name' => 'Owner'], ['name' => 'Acme']);
    $channel = SocialAccount::factory()->create(['workspace_id' => $owner->current_workspace_id, 'platform' => $platform]);

    return Post::factory()->forAccount($channel)->failed()->create(['user_id' => $owner->id, ...$attributes]);
}

test('lists failed posts with their channel, workspace and error, newest first', function () {
    $older = adminFailedPostOn(Platform::X, ['error_message' => 'Older']);
    $this->travel(1)->minute();
    $newer = adminFailedPostOn(Platform::LinkedIn, ['error_message' => 'Newer']);
    Post::query()->whereKey($newer->id)->toBase()->update(['publication_updated_at' => now()]);
    adminFailedPostOn(Platform::X)->forceFill(['status' => PostStatus::Published])->save();

    $this->actingAs($this->admin)
        ->get(route('admin.posts.failed.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/posts/Failed')
            ->has('posts.data', 2)
            ->where('posts.data.0.id', $newer->id)
            ->where('posts.data.0.error_message', 'Newer')
            ->where('posts.data.0.workspace_name', 'Acme')
            ->where('posts.data.0.owner_name', 'Owner')
            ->where('posts.data.0.platform', 'linkedin')
            ->where('posts.data.1.id', $older->id),
        );
});

test('filters failed posts by one or more networks, every way of connecting them', function () {
    adminFailedPostOn(Platform::X);
    adminFailedPostOn(Platform::LinkedIn);
    adminFailedPostOn(Platform::LinkedInPage);
    adminFailedPostOn(Platform::Threads);

    $this->actingAs($this->admin)
        ->get(route('admin.posts.failed.index', ['networks' => ['linkedin', 'threads']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 3)
            ->where('filters.networks', ['linkedin', 'threads']),
        );
});

test('filters failed posts by error category and counts each, an uncategorized or unknown-category failure as unknown', function () {
    adminFailedPostOn(Platform::X, ['error_context' => ['category' => 'rate_limit']]);
    adminFailedPostOn(Platform::X, ['error_context' => ['category' => 'rate_limit']]);
    adminFailedPostOn(Platform::X, ['error_context' => ['category' => 'unknown']]);
    adminFailedPostOn(Platform::X, ['error_context' => null]);
    adminFailedPostOn(Platform::GoogleBusiness, ['error_context' => ['category' => 'review_unconfirmed']]);

    $this->actingAs($this->admin)
        ->get(route('admin.posts.failed.index', ['category' => 'rate_limit']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 2)
            ->where('posts.data.0.error_category', 'rate_limit')
            ->where('categories', ['unknown' => 3, 'rate_limit' => 2])
            ->where('filters.category', 'rate_limit'),
        );

    $this->actingAs($this->admin)
        ->get(route('admin.posts.failed.index', ['category' => 'unknown']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('posts.data', 3));
});

test('a failed post carries its content, how it was made and its channel status', function () {
    $post = adminFailedPostOn(Platform::X, ['content' => '<p>Hello <strong>world</strong></p>']);
    $post->socialAccount->update(['status' => SocialAccountStatus::TokenExpired]);

    $this->actingAs($this->admin)
        ->get(route('admin.posts.failed.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('posts.data.0.content', 'Hello world')
            ->where('posts.data.0.channel_status', 'token_expired')
            ->has('posts.data.0.media')
            ->has('posts.data.0.scheduled_at'),
        );
});

test('an admin retries a failed post and it is recorded', function () {
    Queue::fake();
    $post = adminFailedPostOn();

    $this->actingAs($this->admin)
        ->post(route('admin.posts.retry.store', $post))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Queue::assertPushed(PublishToSocialPlatform::class, 1);
    expect($post->fresh()->status)->toBe(PostStatus::Publishing)
        ->and(Activity::query()->inLog(LogName::Admin)->forSubject($post)->sole()->event)->toBe('post.retried');
});

test('a post that is no longer failed is not retried twice', function () {
    Queue::fake();
    $post = adminFailedPostOn();

    $this->actingAs($this->admin)->post(route('admin.posts.retry.store', $post));
    $this->actingAs($this->admin)
        ->post(route('admin.posts.retry.store', $post))
        ->assertSessionHasErrors(['post' => 'Only a failed post with a channel can be retried.']);

    Queue::assertPushed(PublishToSocialPlatform::class, 1);
});

test('an instagram post whose first comment may be live needs the admin to confirm', function () {
    Queue::fake();
    $post = adminFailedPostOn(Platform::Instagram, ['error_context' => [
        PublishCheckpoint::INSTAGRAM_COMMENT_PENDING => true,
        ThreadProgress::KEY => [['id' => 'ig-media-1', 'hash' => 'root-hash']],
    ]]);

    $this->actingAs($this->admin)
        ->post(route('admin.posts.retry.store', $post))
        ->assertSessionHasErrors(['instagram_comment_checked' => 'Check on Instagram that the first comment is missing, then confirm.']);

    Queue::assertNotPushed(PublishToSocialPlatform::class);

    $this->actingAs($this->admin)
        ->post(route('admin.posts.retry.store', $post), ['instagram_comment_checked' => true])
        ->assertRedirect();

    Queue::assertPushed(PublishToSocialPlatform::class, 1);
});

test('a user who is not an admin cannot list or retry', function () {
    $user = User::factory()->create();
    $post = adminFailedPostOn();

    $this->actingAs($user)->get(route('admin.posts.failed.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.posts.retry.store', $post))->assertForbidden();
});

test('an instagram post whose first comment may be live while the post is not cannot be retried', function () {
    Queue::fake();
    $post = adminFailedPostOn(Platform::Instagram, ['error_context' => [
        PublishCheckpoint::INSTAGRAM_COMMENT_PENDING => true,
    ]]);

    $this->actingAs($this->admin)
        ->post(route('admin.posts.retry.store', $post), ['instagram_comment_checked' => true])
        ->assertSessionHasErrors(['post' => __('posts.errors.instagram.comment_unconfirmed')]);

    Queue::assertNotPushed(PublishToSocialPlatform::class);
    expect($post->fresh()->status)->toBe(PostStatus::Failed)
        ->and(Activity::query()->inLog(LogName::Admin)->forSubject($post)->exists())->toBeFalse();
});

test('a post whose account owner is blocked is not retried', function () {
    Queue::fake();
    $post = adminFailedPostOn();
    $post->user->forceFill(['blocked_at' => now(), 'blocked_reason' => 'Spam'])->save();

    $this->actingAs($this->admin)
        ->post(route('admin.posts.retry.store', $post))
        ->assertSessionHasErrors(['post' => 'The account owner is blocked: unblock them to retry this post.']);

    Queue::assertNotPushed(PublishToSocialPlatform::class);
    expect($post->fresh()->status)->toBe(PostStatus::Failed)
        ->and(Activity::query()->inLog(LogName::Admin)->forSubject($post)->exists())->toBeFalse();
});

test('a post that changed while the retry was prepared is not retried', function () {
    Queue::fake();
    $post = adminFailedPostOn(Platform::X, ['error_context' => ['category' => 'rate_limit']]);
    $stale = $post->fresh();
    Post::query()->whereKey($post->id)->toBase()->update(['error_context' => json_encode(['category' => 'server_error'])]);
    $request = RetryPostRequest::create(route('admin.posts.retry.store', $post), 'POST');
    $request->setUserResolver(fn (): User => $this->admin);

    $response = app(PostRetryController::class)->store($request, $stale, app(RetryPost::class));

    expect($response->getSession()->get('errors')->first('post'))->toBe('The post changed meanwhile; nothing was retried.')
        ->and($post->fresh()->status)->toBe(PostStatus::Failed)
        ->and(Activity::query()->inLog(LogName::Admin)->forSubject($post)->exists())->toBeFalse();
    Queue::assertNotPushed(PublishToSocialPlatform::class);
});

test('a failed post is offered for retry only while its account owner is not blocked', function () {
    $post = adminFailedPostOn();

    $this->actingAs($this->admin)
        ->get(route('admin.posts.failed.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.data.0.retryable', true));

    $post->workspace->account->owner->forceFill(['blocked_at' => now(), 'blocked_reason' => 'Spam'])->save();

    $this->actingAs($this->admin)
        ->get(route('admin.posts.failed.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('posts.data.0.retryable', false));
});

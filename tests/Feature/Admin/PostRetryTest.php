<?php

declare(strict_types=1);

use App\Enums\Activity\LogName;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as SocialAccountStatus;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Activity;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PublishCheckpoint;
use App\Support\Social\ThreadProgress;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
    $this->admin = User::factory()->create(['email' => 'admin@trypost.test']);
});

function failedPostOn(Platform $platform = Platform::X, array $attributes = []): Post
{
    $owner = User::factory()->create(['name' => 'Owner']);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id, 'account_id' => $owner->account_id, 'name' => 'Acme']);
    $channel = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => $platform]);

    return Post::factory()->forAccount($channel)->failed()->create(['user_id' => $owner->id, ...$attributes]);
}

test('lists failed posts with their channel, workspace and error, newest first', function () {
    $older = failedPostOn(Platform::X, ['error_message' => 'Older']);
    $this->travel(1)->minute();
    $newer = failedPostOn(Platform::LinkedIn, ['error_message' => 'Newer']);
    Post::query()->whereKey($newer->id)->toBase()->update(['publication_updated_at' => now()]);
    failedPostOn(Platform::X)->forceFill(['status' => PostStatus::Published])->save();

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
    failedPostOn(Platform::X);
    failedPostOn(Platform::LinkedIn);
    failedPostOn(Platform::LinkedInPage);
    failedPostOn(Platform::Threads);

    $this->actingAs($this->admin)
        ->get(route('admin.posts.failed.index', ['networks' => ['linkedin', 'threads']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 3)
            ->where('filters.networks', ['linkedin', 'threads']),
        );
});

test('filters failed posts by error category and counts each, an uncategorized failure as unknown', function () {
    failedPostOn(Platform::X, ['error_context' => ['category' => 'rate_limit']]);
    failedPostOn(Platform::X, ['error_context' => ['category' => 'rate_limit']]);
    failedPostOn(Platform::X, ['error_context' => ['category' => 'unknown']]);
    failedPostOn(Platform::X, ['error_context' => null]);

    $this->actingAs($this->admin)
        ->get(route('admin.posts.failed.index', ['category' => 'rate_limit']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 2)
            ->where('posts.data.0.error_category', 'rate_limit')
            ->where('categories', ['rate_limit' => 2, 'unknown' => 2])
            ->where('filters.category', 'rate_limit'),
        );

    $this->actingAs($this->admin)
        ->get(route('admin.posts.failed.index', ['category' => 'unknown']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('posts.data', 2));
});

test('a failed post carries its content, how it was made and its channel status', function () {
    $post = failedPostOn(Platform::X, ['content' => '<p>Hello <strong>world</strong></p>']);
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
    $post = failedPostOn();

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
    $post = failedPostOn();

    $this->actingAs($this->admin)->post(route('admin.posts.retry.store', $post));
    $this->actingAs($this->admin)
        ->post(route('admin.posts.retry.store', $post))
        ->assertSessionHasErrors('post');

    Queue::assertPushed(PublishToSocialPlatform::class, 1);
});

test('an instagram post whose first comment may be live needs the admin to confirm', function () {
    Queue::fake();
    $post = failedPostOn(Platform::Instagram, ['error_context' => [
        PublishCheckpoint::INSTAGRAM_COMMENT_PENDING => true,
        ThreadProgress::KEY => [['id' => 'ig-media-1', 'hash' => 'root-hash']],
    ]]);

    $this->actingAs($this->admin)
        ->post(route('admin.posts.retry.store', $post))
        ->assertSessionHasErrors('instagram_comment_checked');

    Queue::assertNotPushed(PublishToSocialPlatform::class);

    $this->actingAs($this->admin)
        ->post(route('admin.posts.retry.store', $post), ['instagram_comment_checked' => true])
        ->assertRedirect();

    Queue::assertPushed(PublishToSocialPlatform::class, 1);
});

test('a user who is not an admin cannot list or retry', function () {
    $user = User::factory()->create();
    $post = failedPostOn();

    $this->actingAs($user)->get(route('admin.posts.failed.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.posts.retry.store', $post))->assertForbidden();
});

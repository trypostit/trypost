<?php

declare(strict_types=1);

use App\Actions\Post\DeletePost;
use App\Actions\Post\ImportExternalPosts;
use App\Enums\Post\Origin;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Events\PostDeleted;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
});

function importedActionPost(SocialAccount $account): Post
{
    AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'remote_id' => 'ig-actions',
        'excerpt' => 'Straight from the app',
    ]);
    ImportExternalPosts::execute($account);

    return Post::query()->imported()->sole();
}

test('an imported post opens in its read only post details', function () {
    $post = importedActionPost($this->account);

    $this->actingAs($this->user)
        ->get(route('app.posts.edit', $post))
        ->assertRedirect(route('app.posts.index', ['post' => $post->id]));

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['post' => $post->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tab', 'sent')
            ->where('openPostDetailsId', $post->id)
            ->where('posts.data.0.id', $post->id)
        );
});

test('duplicating an imported post creates a trypost draft with its text', function () {
    $post = importedActionPost($this->account);

    $this->actingAs($this->user)->post(route('app.posts.duplicate', $post))->assertRedirect();

    $copy = Post::query()->createdInTryPost()->sole();

    expect($copy->status)->toBe(PostStatus::Draft)
        ->and($copy->origin)->toBe(Origin::TryPost)
        ->and($copy->content)->toBe('Straight from the app')
        ->and($copy->user_id)->toBe($this->user->id)
        ->and($copy->postPlatforms()->sole()->content_type)->toBe(ContentType::InstagramFeed);
});

test('a deleted imported post is never imported again', function () {
    $post = importedActionPost($this->account);
    $publication = AnalyticsPublication::query()->where('remote_id', 'ig-actions')->sole();

    DeletePost::execute($post);
    ImportExternalPosts::execute($this->account);

    expect($publication->fresh()->post_dismissed_at)->not->toBeNull()
        ->and($publication->fresh()->post_platform_id)->toBeNull()
        ->and(Post::query()->imported()->count())->toBe(0);
});

test('a deleted trypost post is never imported back', function () {
    $post = Post::factory()->published()->create(['workspace_id' => $this->workspace->id]);
    $target = PostPlatform::factory()->instagram()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->account->id,
        'platform_post_id' => 'ig-trypost',
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->account->id,
        'social_account_key' => $this->account->id,
        'post_platform_id' => $target->id,
        'platform' => $this->account->platform,
        'network' => $this->account->platform->network(),
        'remote_id' => 'ig-trypost',
    ]);

    DeletePost::execute($post);
    ImportExternalPosts::execute($this->account);

    expect($publication->fresh()->post_dismissed_at)->not->toBeNull()
        ->and($publication->fresh()->post_platform_id)->toBeNull()
        ->and(Post::query()->count())->toBe(0);
});

test('deleting an imported post does not dispatch PostDeleted', function () {
    $post = importedActionPost($this->account);
    Event::fake([PostDeleted::class]);

    DeletePost::execute($post);

    Event::assertNotDispatched(PostDeleted::class);
});

test('deleting a trypost post still dispatches PostDeleted', function () {
    $post = Post::factory()->published()->create(['workspace_id' => $this->workspace->id]);
    Event::fake([PostDeleted::class]);

    DeletePost::execute($post);

    Event::assertDispatched(PostDeleted::class);
});

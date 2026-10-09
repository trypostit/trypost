<?php

declare(strict_types=1);

use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Actions\Post\ImportExternalPosts;
use App\Dto\Analytics\DiscoveredPublication;
use App\Enums\Analytics\PublicationContentType;
use App\Events\PostDeleted;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\SendNotification;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class, SendNotification::class]);
    config()->set('trypost.external_posts.import_limit', 50);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

/**
 * @return array{0: Post, 1: Media}
 */
function channelPost(SocialAccount $account, ?string $state = null): array
{
    $post = Post::factory()
        ->forAccount($account)
        ->when($state, fn ($factory) => $factory->{$state}())
        ->create();
    $media = Media::factory()->ownedByPost($post)->create();
    Storage::put($media->path, 'bytes');

    return [$post, $media];
}

test('disconnecting deletes every post of the channel, whatever its status or origin, with its media', function () {
    Event::fake([PostDeleted::class]);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    $posts = collect([
        channelPost($account, 'draft'),
        channelPost($account, 'scheduled'),
        channelPost($account, 'pendingApproval'),
        channelPost($account, 'failed'),
        channelPost($account, 'published'),
        channelPost($account, 'imported'),
    ]);
    [$importedPost] = $posts->last();
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $account->id,
        'post_id' => $importedPost->id,
    ]);
    $snapshot = AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id]);

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account))->assertRedirect();

    expect(SocialAccount::query()->find($account->id))->toBeNull()
        ->and(Post::query()->whereKey($posts->map(fn (array $row): string => $row[0]->id))->count())->toBe(0)
        ->and(Post::query()->whereNull('social_account_id')->whereNotNull('platform')->count())->toBe(0)
        ->and(Media::query()->whereKey($posts->map(fn (array $row): string => $row[1]->id))->count())->toBe(0)
        ->and($publication->fresh()->post_id)->toBeNull()
        ->and($publication->fresh()->post_dismissed_at)->toBeNull()
        ->and(AnalyticsPublicationDailySnapshot::query()->whereKey($snapshot->id)->exists())->toBeTrue();

    $posts->each(fn (array $row) => Storage::assertMissing($row[1]->path));
    Event::assertNotDispatched(PostDeleted::class);
    Queue::assertNotPushed(SendNotification::class);
});

test('disconnecting leaves other channels and other workspaces alone', function () {
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $sibling = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $elsewhere = SocialAccount::factory()->instagram()->create();

    [$gone] = channelPost($account, 'published');
    [$siblingPost] = channelPost($sibling, 'published');
    [$otherWorkspacePost] = channelPost($elsewhere, 'published');

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account));

    expect(Post::query()->whereKey($gone->id)->exists())->toBeFalse()
        ->and(Post::query()->whereKey($siblingPost->id)->exists())->toBeTrue()
        ->and(Post::query()->whereKey($otherWorkspacePost->id)->exists())->toBeTrue()
        ->and($siblingPost->fresh()->social_account_id)->toBe($sibling->id);
});

test('disconnecting prunes the google business image of a post still waiting for review', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->pendingReview()->create([
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($post);
    Storage::put($path, 'image');

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account));

    expect(Post::query()->whereKey($post->id)->exists())->toBeFalse();
    Storage::assertMissing($path);
    Queue::assertNotPushed(SendNotification::class);
});

test('reconnecting the same identity imports its posts again without duplicates', function () {
    CarbonImmutable::setTestNow('2026-10-01 12:00:00');
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $discovered = new DiscoveredPublication(
        providerPostId: 'reel-1',
        publishedAt: now()->subDay()->toImmutable(),
        contentType: PublicationContentType::Reel,
        permalink: 'https://www.instagram.com/reel/reel-1/',
        excerpt: 'Our reel',
    );
    $publication = app(UpsertAnalyticsPublication::class)->external($account, $discovered);
    ImportExternalPosts::execute($account);

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account));

    expect(Post::query()->imported()->count())->toBe(0)
        ->and($publication->fresh()->post_id)->toBeNull();

    $reconnected = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => $account->platform_user_id,
    ]);
    app(UpsertAnalyticsPublication::class)->external($reconnected, $discovered);
    ImportExternalPosts::execute($reconnected);
    ImportExternalPosts::execute($reconnected);

    $imported = Post::query()->imported()->sole();

    expect(AnalyticsPublication::query()->count())->toBe(1)
        ->and($imported->social_account_id)->toBe($reconnected->id)
        ->and($publication->fresh()->post_id)->toBe($imported->id);
});

test('a disconnect that fails to delete the channel keeps its posts, media and images', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create();
    $media = Media::factory()->ownedByPost($post)->create();
    Storage::put($media->path, 'bytes');
    Storage::put(GoogleBusinessDerivativeCleaner::pathFor($post), 'jpeg');
    Event::listen('eloquent.deleting: '.SocialAccount::class, fn () => throw new RuntimeException('delete failed'));

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account))->assertServerError();

    expect(SocialAccount::query()->find($account->id))->not->toBeNull()
        ->and(Post::query()->find($post->id))->not->toBeNull()
        ->and($post->fresh()->social_account_id)->toBe($account->id)
        ->and(Media::query()->find($media->id))->not->toBeNull();
    Storage::assertExists($media->path);
    Storage::assertExists(GoogleBusinessDerivativeCleaner::pathFor($post));
});

test('a disconnect waits for the channel queue lock and changes nothing while a save holds it', function () {
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    [$post] = channelPost($account, 'scheduled');
    $lock = Cache::lock("queue:{$account->id}", 10);
    expect($lock->get())->toBeTrue();

    try {
        $this->actingAs($this->user)
            ->delete(route('app.channels.disconnect', $account))
            ->assertRedirect()
            ->assertSessionHas('flash.error', __('posts.errors.queue_busy'));
    } finally {
        $lock->release();
    }

    expect(SocialAccount::query()->find($account->id))->not->toBeNull()
        ->and(Post::query()->find($post->id))->not->toBeNull();
});

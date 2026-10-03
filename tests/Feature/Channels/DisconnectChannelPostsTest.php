<?php

declare(strict_types=1);

use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Actions\Post\ImportExternalPosts;
use App\Dto\Analytics\DiscoveredPublication;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Events\PostDeleted;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\SendNotification;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Carbon\CarbonImmutable;
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
 * @return array{0: Post, 1: PostPlatform, 2: Media}
 */
function channelPost(SocialAccount $account, Post $post, ?PlatformStatus $status = null): array
{
    $target = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'status' => $status ?? PlatformStatus::Pending,
        'platform_post_id' => $status === PlatformStatus::Published ? fake()->uuid() : null,
    ]);
    $media = Media::factory()->ownedByPost($post)->create();
    Storage::put($media->path, 'bytes');

    return [$post, $target, $media];
}

test('disconnecting deletes every post of the channel, whatever its status or origin, with its media', function () {
    Event::fake([PostDeleted::class]);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $in = ['workspace_id' => $this->workspace->id];

    $posts = collect([
        channelPost($account, Post::factory()->draft()->create($in)),
        channelPost($account, Post::factory()->scheduled()->create($in)),
        channelPost($account, Post::factory()->pendingApproval()->create($in)),
        channelPost($account, Post::factory()->failed()->create($in), PlatformStatus::Failed),
        channelPost($account, Post::factory()->published()->create($in), PlatformStatus::Published),
        channelPost($account, Post::factory()->imported()->create($in), PlatformStatus::Published),
    ]);
    [, $importedTarget] = $posts->last();
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $account->id,
        'post_platform_id' => $importedTarget->id,
    ]);
    $snapshot = AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id]);

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account))->assertRedirect();

    expect(SocialAccount::query()->find($account->id))->toBeNull()
        ->and(Post::query()->whereKey($posts->map(fn (array $row): string => $row[0]->id))->count())->toBe(0)
        ->and(PostPlatform::query()->whereNull('social_account_id')->count())->toBe(0)
        ->and(Media::query()->whereKey($posts->map(fn (array $row): string => $row[2]->id))->count())->toBe(0)
        ->and($publication->fresh()->post_platform_id)->toBeNull()
        ->and($publication->fresh()->post_dismissed_at)->toBeNull()
        ->and(AnalyticsPublicationDailySnapshot::query()->whereKey($snapshot->id)->exists())->toBeTrue();

    $posts->each(fn (array $row) => Storage::assertMissing($row[2]->path));
    Event::assertNotDispatched(PostDeleted::class);
    Queue::assertNotPushed(SendNotification::class);
});

test('disconnecting leaves other channels and other workspaces alone', function () {
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $sibling = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $elsewhere = SocialAccount::factory()->instagram()->create();

    [$gone] = channelPost($account, Post::factory()->published()->create(['workspace_id' => $this->workspace->id]), PlatformStatus::Published);
    [$siblingPost] = channelPost($sibling, Post::factory()->published()->create(['workspace_id' => $this->workspace->id]), PlatformStatus::Published);
    [$otherWorkspacePost] = channelPost($elsewhere, Post::factory()->published()->create(['workspace_id' => $elsewhere->workspace_id]), PlatformStatus::Published);

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account));

    expect(Post::query()->whereKey($gone->id)->exists())->toBeFalse()
        ->and(Post::query()->whereKey($siblingPost->id)->exists())->toBeTrue()
        ->and(Post::query()->whereKey($otherWorkspacePost->id)->exists())->toBeTrue()
        ->and($siblingPost->postPlatforms()->sole()->social_account_id)->toBe($sibling->id);
});

test('a legacy post on several channels keeps its other channels and settles once the disconnected one is gone', function () {
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $other = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $settled = Post::factory()->published()->create(['workspace_id' => $this->workspace->id]);
    channelPost($account, $settled, PlatformStatus::Published);
    $kept = PostPlatform::factory()->published()->create(['post_id' => $settled->id, 'social_account_id' => $other->id]);

    $inFlight = Post::factory()->create(['workspace_id' => $this->workspace->id, 'status' => Status::Publishing]);
    PostPlatform::factory()->instagram()->create(['post_id' => $inFlight->id, 'social_account_id' => $account->id, 'status' => PlatformStatus::Publishing]);
    PostPlatform::factory()->published()->create(['post_id' => $inFlight->id, 'social_account_id' => $other->id]);

    $placeholderOnly = Post::factory()->published()->create(['workspace_id' => $this->workspace->id]);
    channelPost($account, $placeholderOnly, PlatformStatus::Published);
    PostPlatform::factory()->disabled()->create(['post_id' => $placeholderOnly->id, 'social_account_id' => $other->id]);

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $account));

    expect($settled->fresh())->not->toBeNull()
        ->and($settled->postPlatforms()->pluck('id')->all())->toBe([$kept->id])
        ->and($inFlight->fresh()->status)->toBe(Status::Published)
        ->and($inFlight->postPlatforms()->count())->toBe(1)
        ->and(Post::query()->whereKey($placeholderOnly->id)->exists())->toBeFalse();
});

test('disconnecting prunes the google business image of a post still waiting for review', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'status' => Status::Publishing]);
    $target = PostPlatform::factory()->googleBusiness()->pendingReview()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($target->id);
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
        ->and($publication->fresh()->post_platform_id)->toBeNull();

    $reconnected = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => $account->platform_user_id,
    ]);
    app(UpsertAnalyticsPublication::class)->external($reconnected, $discovered);
    ImportExternalPosts::execute($reconnected);
    ImportExternalPosts::execute($reconnected);

    $imported = Post::query()->imported()->sole();

    expect(AnalyticsPublication::query()->count())->toBe(1)
        ->and($imported->postPlatforms()->sole()->social_account_id)->toBe($reconnected->id)
        ->and($publication->fresh()->post_platform_id)->toBe($imported->postPlatforms()->sole()->id);
});

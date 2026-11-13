<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;

function facebookPublication(SocialAccount $account, array $attributes = []): AnalyticsPublication
{
    return AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'content_type' => PublicationContentType::Reel,
        ...$attributes,
    ]);
}

/**
 * @return array{0: SocialAccount, 1: Post, 2: AnalyticsPublication, 3: AnalyticsPublication}
 */
function facebookReelCountedTwice(string $alias = 'video_id'): array
{
    $account = SocialAccount::factory()->facebook()->create();
    $post = Post::factory()->forAccount($account)->facebookReel()->published()->create(['platform_post_id' => 'media-9']);
    $tracked = facebookPublication($account, ['remote_id' => 'media-9', 'post_id' => $post->id, 'origin' => PublicationOrigin::TryPost]);
    $discovered = facebookPublication($account, [
        'remote_id' => 'page_post-1',
        'provider_metadata' => [$alias => 'media-9'],
        'preview_metadata' => ['thumbnail_url' => 'https://scontent.xx.fbcdn.net/expired.jpg'],
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $discovered->id, 'comments_count' => 2]);

    return [$account, $post, $tracked, $discovered];
}

test('a dry run counts the duplicates and changes nothing', function () {
    [, $post, $tracked, $discovered] = facebookReelCountedTwice();

    $this->artisan('analytics:merge-facebook-duplicates', ['--dry-run' => true])
        ->expectsOutputToContain('Would link 1 publication(s) to their TryPost post.')
        ->assertSuccessful();

    expect($discovered->fresh())->not->toBeNull()
        ->and($tracked->fresh()->remote_id)->toBe('media-9')
        ->and($post->fresh()->platform_post_id)->toBe('media-9');
});

test('a facebook post counted twice is merged into its trypost publication without queueing anything', function (string $alias) {
    [$account, $post, $tracked, $discovered] = facebookReelCountedTwice($alias);
    Queue::fake();

    $this->artisan('analytics:merge-facebook-duplicates', ['--force' => true])
        ->expectsOutputToContain('Linked 1 publication(s) to their TryPost post.')
        ->assertSuccessful();

    expect($discovered->fresh())->toBeNull()
        ->and(AnalyticsPublication::query()->where('social_account_id', $account->id)->sole()->id)->toBe($tracked->id)
        ->and($tracked->fresh()->remote_id)->toBe('page_post-1')
        ->and($tracked->fresh()->provider_metadata)->toEqual([$alias => 'media-9'])
        ->and($tracked->dailySnapshots()->sole()->comments_count)->toBe(2)
        ->and($post->fresh()->platform_post_id)->toBe('media-9');
    Queue::assertNothingPushed();
})->with(['a video or reel' => 'video_id', 'a single photo' => 'photo_id']);

test('a facebook reel published outside trypost is left alone', function () {
    $account = SocialAccount::factory()->facebook()->create();
    $external = facebookPublication($account, ['remote_id' => 'page_post-2', 'provider_metadata' => ['video_id' => 'video-other']]);

    $this->artisan('analytics:merge-facebook-duplicates', ['--force' => true])
        ->expectsOutputToContain('Linked 0 publication(s) to their TryPost post.')
        ->assertSuccessful();

    expect($external->fresh()->post_id)->toBeNull();
});

test('a dismissed publication is left alone, as the import leaves it', function () {
    [, , $tracked, $discovered] = facebookReelCountedTwice();
    $discovered->forceFill(['post_dismissed_at' => now()])->save();

    $this->artisan('analytics:merge-facebook-duplicates', ['--force' => true])
        ->expectsOutputToContain('Linked 0 publication(s) to their TryPost post.')
        ->assertSuccessful();

    expect($discovered->fresh())->not->toBeNull()
        ->and($tracked->fresh()->remote_id)->toBe('media-9');
});

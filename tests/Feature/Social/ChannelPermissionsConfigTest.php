<?php

declare(strict_types=1);

use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Support\ChannelPermissions;
use App\Support\OAuthScopes;

test('by default every feature is on', function (Platform $platform) {
    config(['trypost.platforms' => data_get(trypostConfigWith(['LINKEDIN_SCOPES' => '', 'LINKEDIN_PAGE_SCOPES' => '', 'X_SCOPES' => '', 'TIKTOK_SCOPES' => '', 'YOUTUBE_SCOPES' => '', 'FACEBOOK_SCOPES' => '', 'INSTAGRAM_SCOPES' => '', 'INSTAGRAM_FACEBOOK_SCOPES' => '', 'THREADS_SCOPES' => '', 'PINTEREST_SCOPES' => '', 'MASTODON_SCOPES' => '', 'DISCORD_SCOPES' => '', 'GOOGLE_BUSINESS_SCOPES' => '']), 'platforms')]);

    expect(ChannelPermissions::disabledFeatures($platform))->toBe([]);
})->with(Platform::cases());

/**
 * The scopes each network needs per feature, written out, so a scope dropped
 * or renamed in the map fails here instead of silently changing what
 * connecting, publishing and analytics require. Change both together.
 */
test('each network needs exactly these scopes for each feature', function () {
    $map = collect(Platform::cases())
        ->mapWithKeys(fn (Platform $platform): array => [$platform->value => collect(GatedFeature::cases())
            ->mapWithKeys(fn (GatedFeature $feature): array => [$feature->value => ChannelPermissions::scopes($platform, $feature)])
            ->filter()
            ->all()])
        ->filter()
        ->all();

    expect($map)->toBe([
        Platform::LinkedIn->value => [
            'publish' => ['w_member_social'],
        ],
        Platform::LinkedInPage->value => [
            'publish' => ['w_organization_social'],
        ],
        Platform::X->value => [
            'publish' => ['tweet.write'],
            'followers' => ['tweet.read', 'users.read'],
            'post_insights' => ['tweet.read', 'users.read'],
            'post_history' => ['tweet.read', 'users.read'],
        ],
        Platform::TikTok->value => [
            'publish' => ['video.publish'],
            'followers' => ['user.info.stats'],
            'post_insights' => ['video.list'],
            'post_history' => ['video.list'],
        ],
        Platform::YouTube->value => [
            'publish' => [['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl', 'https://www.googleapis.com/auth/youtubepartner']],
            'followers' => [['https://www.googleapis.com/auth/youtube.readonly', 'https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl', 'https://www.googleapis.com/auth/youtubepartner']],
            'post_insights' => [['https://www.googleapis.com/auth/youtube.readonly', 'https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl', 'https://www.googleapis.com/auth/youtubepartner']],
            'post_insights_extended' => [['https://www.googleapis.com/auth/yt-analytics.readonly', 'https://www.googleapis.com/auth/yt-analytics-monetary.readonly']],
            'post_history' => [['https://www.googleapis.com/auth/youtube.readonly', 'https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl', 'https://www.googleapis.com/auth/youtubepartner']],
        ],
        Platform::Facebook->value => [
            'publish' => ['pages_manage_posts'],
            'followers' => ['pages_read_engagement'],
            'post_insights' => ['pages_read_engagement'],
            'post_insights_extended' => ['read_insights'],
            'post_history' => ['pages_read_engagement'],
        ],
        Platform::Instagram->value => [
            'publish' => ['instagram_business_content_publish'],
            'thread_replies' => ['instagram_business_manage_comments', 'instagram_business_basic'],
            'followers' => ['instagram_business_basic'],
            'post_insights' => ['instagram_business_basic', 'instagram_business_manage_insights'],
            'post_history' => ['instagram_business_basic'],
        ],
        Platform::InstagramFacebook->value => [
            'publish' => ['instagram_content_publish'],
            'thread_replies' => ['instagram_manage_comments', 'instagram_basic', 'pages_read_engagement'],
            'followers' => ['instagram_basic', 'pages_read_engagement'],
            'post_insights' => ['instagram_basic', 'instagram_manage_insights', 'pages_read_engagement'],
            'post_history' => ['instagram_basic'],
        ],
        Platform::Threads->value => [
            'publish' => ['threads_content_publish'],
            'thread_replies' => ['threads_manage_replies'],
            'followers' => ['threads_basic', 'threads_manage_insights'],
            'post_insights' => ['threads_basic', 'threads_manage_insights'],
            'post_history' => ['threads_basic'],
        ],
        Platform::Pinterest->value => [
            'publish' => ['pins:write'],
            'followers' => ['user_accounts:read'],
            'post_insights' => ['pins:read', 'boards:read'],
            'post_history' => ['pins:read', 'boards:read'],
        ],
        Platform::Mastodon->value => [
            'publish' => [['write:statuses', 'write']],
            'private_post_read' => [['read:statuses', 'read']],
        ],
        Platform::GoogleBusiness->value => [
            'publish' => ['https://www.googleapis.com/auth/business.manage'],
        ],
    ]);
});

test('a network scopes env is read comma or space separated, and a blank one falls back to the default', function () {
    expect(data_get(trypostConfigWith(['X_SCOPES' => ' tweet.read , tweet.write,,users.read ']), 'platforms.x.scopes'))
        ->toBe(['tweet.read', 'tweet.write', 'users.read'])
        ->and(data_get(trypostConfigWith(['MASTODON_SCOPES' => 'read:accounts write:statuses']), 'platforms.mastodon.scopes'))
        ->toBe(['read:accounts', 'write:statuses'])
        ->and(data_get(trypostConfigWith(['X_SCOPES' => '  ']), 'platforms.x.scopes'))
        ->toBe(data_get(trypostConfigWith(['X_SCOPES' => '']), 'platforms.x.scopes'))
        ->toContain('tweet.write', 'offline.access');
});

test('instagram first comments are on by default and can be turned off on each connection path', function (Platform $platform, string $env, string $basic, string $publish, string $comments) {
    $key = "trypost.platforms.{$platform->value}";
    config([$key => data_get(trypostConfigWith([$env => '']), "platforms.{$platform->value}")]);

    expect(ChannelPermissions::requestedScopes($platform))->toContain($basic, $publish)
        ->and(ChannelPermissions::enabled($platform, GatedFeature::ThreadReplies))->toBeTrue()
        ->and(ChannelPermissions::missingFrom([$basic, $publish], $platform, GatedFeature::Publish))->toBe([]);

    $commentScopes = OAuthScopes::commaSeparated([$publish, ...ChannelPermissions::missingFrom([], $platform, GatedFeature::ThreadReplies)]);
    config([$key => data_get(trypostConfigWith([$env => $commentScopes]), "platforms.{$platform->value}")]);
    expect(ChannelPermissions::enabled($platform, GatedFeature::ThreadReplies))->toBeTrue();

    config([$key => data_get(trypostConfigWith([$env => "$basic,$publish"]), "platforms.{$platform->value}")]);

    expect(ChannelPermissions::requestedScopes($platform))->not->toContain($comments)
        ->and(ChannelPermissions::enabled($platform, GatedFeature::ThreadReplies))->toBeFalse()
        ->and(ChannelPermissions::enabled($platform, GatedFeature::Publish))->toBeTrue();
})->with([
    'instagram login' => [Platform::Instagram, 'INSTAGRAM_SCOPES', 'instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_comments'],
    'facebook login' => [Platform::InstagramFacebook, 'INSTAGRAM_FACEBOOK_SCOPES', 'instagram_basic', 'instagram_content_publish', 'instagram_manage_comments'],
]);

<?php

declare(strict_types=1);

use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Auth\FacebookController;
use App\Http\Controllers\Auth\GoogleBusinessController;
use App\Http\Controllers\Auth\InstagramController;
use App\Http\Controllers\Auth\InstagramFacebookController;
use App\Http\Controllers\Auth\MastodonController;
use App\Http\Controllers\Auth\PinterestController;
use App\Http\Controllers\Auth\ThreadsController;
use App\Http\Controllers\Auth\XController;
use App\Http\Controllers\Auth\YouTubeController;
use App\Support\ChannelPermissions;
use Illuminate\Support\Str;

/**
 * The scopes a network's connect asks for: its controller's `$scopes`, or its
 * configured list where the scopes are configurable.
 *
 * @return list<string>
 */
function scopesRequestedOnConnect(Platform $platform): array
{
    $fromController = fn (string $controller): array => (new ReflectionProperty($controller, 'scopes'))->getDefaultValue();

    return match ($platform) {
        Platform::X => $fromController(XController::class),
        Platform::Facebook => $fromController(FacebookController::class),
        Platform::Instagram => $fromController(InstagramController::class),
        Platform::InstagramFacebook => $fromController(InstagramFacebookController::class),
        Platform::Threads => $fromController(ThreadsController::class),
        Platform::YouTube => $fromController(YouTubeController::class),
        Platform::Pinterest => $fromController(PinterestController::class),
        Platform::GoogleBusiness => $fromController(GoogleBusinessController::class),
        Platform::Mastodon => Str::of((new ReflectionClassConstant(MastodonController::class, 'SCOPES'))->getValue())->explode(' ')->all(),
        Platform::LinkedIn, Platform::LinkedInPage, Platform::TikTok, Platform::Discord => config("trypost.platforms.{$platform->value}.scopes"),
        Platform::Bluesky, Platform::Telegram => [],
    };
}

test('every scope a network needs for a feature is one its connect asks for, so a reconnect can grant it', function (Platform $platform) {
    $requested = scopesRequestedOnConnect($platform);

    foreach (GatedFeature::cases() as $feature) {
        expect(ChannelPermissions::missingFrom($requested, $platform, $feature))
            ->toBe([], "{$platform->value} {$feature->value} needs a scope its connect never asks for");
    }
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
            'publish' => ['https://www.googleapis.com/auth/youtube.upload'],
            'followers' => [['https://www.googleapis.com/auth/youtube.readonly', 'https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl']],
            'post_insights' => [['https://www.googleapis.com/auth/youtube.readonly', 'https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl']],
            'post_insights_extended' => [['https://www.googleapis.com/auth/yt-analytics.readonly', 'https://www.googleapis.com/auth/yt-analytics-monetary.readonly']],
            'post_history' => [['https://www.googleapis.com/auth/youtube.readonly', 'https://www.googleapis.com/auth/youtube', 'https://www.googleapis.com/auth/youtube.force-ssl']],
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
            'followers' => ['instagram_business_basic'],
            'post_insights' => ['instagram_business_basic', 'instagram_business_manage_insights'],
            'post_history' => ['instagram_business_basic'],
        ],
        Platform::InstagramFacebook->value => [
            'publish' => ['instagram_content_publish'],
            'followers' => ['instagram_basic', 'pages_read_engagement'],
            'post_insights' => ['instagram_basic', 'instagram_manage_insights', 'pages_read_engagement'],
            'post_history' => ['instagram_basic'],
        ],
        Platform::Threads->value => [
            'publish' => ['threads_content_publish'],
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
            'publish' => ['write:statuses'],
        ],
        Platform::GoogleBusiness->value => [
            'publish' => ['https://www.googleapis.com/auth/business.manage'],
        ],
    ]);
});

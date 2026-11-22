<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
});

/**
 * The scopes a connect redirect asks for, however the network separates them.
 *
 * @return list<string>
 */
function connectRedirectScopes(string $location): array
{
    return Str::of((string) Uri::of($location)->query()->get('scope'))
        ->split('/[\s,+]+/')
        ->filter()
        ->values()
        ->all();
}

test('connect asks for required scopes even when the env leaves them out', function (Platform $platform, string $route, array $required, ?string $leftOut = null, array $settings = []) {
    config(["trypost.platforms.{$platform->value}.scopes" => ['trypost.sentinel'], ...$settings]);

    $scopes = connectRedirectScopes($this->actingAs($this->user)->get(route($route))->headers->get('Location'));

    expect($scopes)->toContain('trypost.sentinel', ...$required);

    if (filled($leftOut)) {
        expect($scopes)->not->toContain($leftOut);
    }
})->with([
    'x' => [Platform::X, 'app.social.x.connect', ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access']],
    'facebook' => [Platform::Facebook, 'app.social.facebook.connect', ['pages_manage_posts', 'pages_show_list', 'pages_read_engagement', 'business_management'], 'read_insights'],
    'youtube' => [Platform::YouTube, 'app.social.youtube.connect', ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly'], 'https://www.googleapis.com/auth/yt-analytics.readonly'],
    'pinterest' => [Platform::Pinterest, 'app.social.pinterest.connect', ['pins:write', 'boards:read', 'boards:write', 'user_accounts:read'], 'pins:read'],
    'google business' => [Platform::GoogleBusiness, 'app.social.google-business.connect', ['https://www.googleapis.com/auth/business.manage'], 'https://www.googleapis.com/auth/userinfo.email'],
    'instagram' => [Platform::Instagram, 'app.social.instagram.connect', ['instagram_business_basic', 'instagram_business_content_publish'], 'instagram_business_manage_insights'],
    'instagram through facebook' => [Platform::InstagramFacebook, 'app.social.instagram-facebook.connect', ['pages_show_list', 'pages_read_engagement', 'business_management', 'instagram_basic', 'instagram_content_publish'], 'instagram_manage_insights'],
    'threads' => [Platform::Threads, 'app.social.threads.connect', ['threads_basic', 'threads_content_publish'], 'threads_manage_insights'],
    'tiktok' => [Platform::TikTok, 'app.social.tiktok.connect', ['user.info.basic', 'video.publish', 'video.upload'], 'video.list'],
    'linkedin profile' => [Platform::LinkedIn, 'app.social.linkedin.connect', ['openid', 'profile', 'email', 'w_member_social'], 'w_organization_social', ['trypost.platforms.linkedin-page.enabled' => false]],
    'linkedin page' => [Platform::LinkedInPage, 'app.social.linkedin.connect', ['openid', 'profile', 'email', 'w_organization_social', 'rw_organization_admin'], 'w_member_social', ['trypost.platforms.linkedin.enabled' => false]],
    'discord' => [Platform::Discord, 'app.social.discord.connect', ['bot', 'identify', 'guilds']],
]);

<?php

declare(strict_types=1);

use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ChannelPermissions;
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

test('every connect asks for the scopes the install configures, plus publishing, and not the defaults it left out', function (Platform $platform, string $route, ?string $leftOut, array $settings = []) {
    $publish = ChannelPermissions::missingFrom([], $platform, GatedFeature::Publish);
    config(["trypost.platforms.{$platform->value}.scopes" => ['trypost.sentinel'], ...$settings]);

    $scopes = connectRedirectScopes($this->actingAs($this->user)->get(route($route))->headers->get('Location'));

    expect($scopes)->toContain('trypost.sentinel', ...$publish);

    if (filled($leftOut)) {
        expect($scopes)->not->toContain($leftOut);
    }
})->with([
    'x' => [Platform::X, 'app.social.x.connect', 'media.write'],
    'facebook' => [Platform::Facebook, 'app.social.facebook.connect', 'read_insights'],
    'youtube' => [Platform::YouTube, 'app.social.youtube.connect', 'https://www.googleapis.com/auth/yt-analytics.readonly'],
    'pinterest' => [Platform::Pinterest, 'app.social.pinterest.connect', 'boards:write'],
    'google business' => [Platform::GoogleBusiness, 'app.social.google-business.connect', 'https://www.googleapis.com/auth/userinfo.email'],
    'threads' => [Platform::Threads, 'app.social.threads.connect', 'threads_manage_insights'],
    'tiktok' => [Platform::TikTok, 'app.social.tiktok.connect', 'video.list'],
    'linkedin, whose provider always adds openid, profile and email' => [Platform::LinkedIn, 'app.social.linkedin.connect', null, ['trypost.platforms.linkedin-page.enabled' => false]],
    'discord, whose provider always adds its bot scopes' => [Platform::Discord, 'app.social.discord.connect', null],
]);

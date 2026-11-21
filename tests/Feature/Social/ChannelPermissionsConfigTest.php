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

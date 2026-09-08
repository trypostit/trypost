<?php

declare(strict_types=1);

use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;

test('platform has correct labels', function () {
    expect(Platform::LinkedIn->label())->toBe('LinkedIn');
    expect(Platform::LinkedInPage->label())->toBe('LinkedIn Page');
    expect(Platform::X->label())->toBe('X');
    expect(Platform::TikTok->label())->toBe('TikTok');
    expect(Platform::YouTube->label())->toBe('YouTube Shorts');
    expect(Platform::Facebook->label())->toBe('Facebook Page');
    expect(Platform::Instagram->label())->toBe('Instagram');
    expect(Platform::InstagramFacebook->label())->toBe('Instagram (Facebook Business)');
    expect(Platform::Threads->label())->toBe('Threads');
    expect(Platform::Pinterest->label())->toBe('Pinterest');
    expect(Platform::Bluesky->label())->toBe('Bluesky');
    expect(Platform::Mastodon->label())->toBe('Mastodon');
});

test('platform has correct colors', function () {
    expect(Platform::LinkedIn->color())->toBe('#0A66C2');
    expect(Platform::LinkedInPage->color())->toBe('#0A66C2');
    expect(Platform::X->color())->toBe('#000000');
    expect(Platform::TikTok->color())->toBe('#000000');
    expect(Platform::YouTube->color())->toBe('#FF0000');
    expect(Platform::Facebook->color())->toBe('#1877F2');
    expect(Platform::Instagram->color())->toBe('#E4405F');
    expect(Platform::Threads->color())->toBe('#000000');
    expect(Platform::Pinterest->color())->toBe('#E60023');
    expect(Platform::Bluesky->color())->toBe('#0085FF');
    expect(Platform::Mastodon->color())->toBe('#6364FF');
});

test('platform has correct allowed media types', function () {
    expect(Platform::LinkedIn->allowedMediaTypes())->toContain(MediaType::Image, MediaType::Video, MediaType::Document);
    expect(Platform::LinkedInPage->allowedMediaTypes())->toContain(MediaType::Image, MediaType::Video, MediaType::Document);
    expect(Platform::X->allowedMediaTypes())->toContain(MediaType::Image, MediaType::Video);
    expect(Platform::X->allowedMediaTypes())->not->toContain(MediaType::Document);
    expect(Platform::TikTok->allowedMediaTypes())->toBe([MediaType::Video]);
    expect(Platform::YouTube->allowedMediaTypes())->toBe([MediaType::Video]);
    expect(Platform::Instagram->allowedMediaTypes())->toContain(MediaType::Image, MediaType::Video);
});

test('platform has correct max images', function () {
    expect(Platform::LinkedIn->maxImages())->toBe(10);
    expect(Platform::X->maxImages())->toBe(4);
    expect(Platform::TikTok->maxImages())->toBe(0);
    expect(Platform::YouTube->maxImages())->toBe(0);
    expect(Platform::Facebook->maxImages())->toBe(10);
    expect(Platform::Instagram->maxImages())->toBe(10);
    expect(Platform::Threads->maxImages())->toBe(10);
    expect(Platform::Pinterest->maxImages())->toBe(5);
    expect(Platform::Bluesky->maxImages())->toBe(4);
    expect(Platform::Mastodon->maxImages())->toBe(4);
});

test('platform has correct max content length', function () {
    expect(Platform::LinkedIn->maxContentLength())->toBe(3000);
    expect(Platform::X->maxContentLength())->toBe(280);
    expect(Platform::TikTok->maxContentLength())->toBe(2200);
    expect(Platform::YouTube->maxContentLength())->toBe(100);
    expect(Platform::Facebook->maxContentLength())->toBe(10000);
    expect(Platform::Instagram->maxContentLength())->toBe(2200);
    expect(Platform::Threads->maxContentLength())->toBe(500);
    expect(Platform::Pinterest->maxContentLength())->toBe(800);
    expect(Platform::Bluesky->maxContentLength())->toBe(300);
    expect(Platform::Mastodon->maxContentLength())->toBe(500);
});

test('platform supports text only correctly', function () {
    expect(Platform::LinkedIn->supportsTextOnly())->toBeTrue();
    expect(Platform::LinkedInPage->supportsTextOnly())->toBeTrue();
    expect(Platform::X->supportsTextOnly())->toBeTrue();
    expect(Platform::Facebook->supportsTextOnly())->toBeTrue();
    expect(Platform::Threads->supportsTextOnly())->toBeTrue();
    expect(Platform::Bluesky->supportsTextOnly())->toBeTrue();
    expect(Platform::Mastodon->supportsTextOnly())->toBeTrue();

    expect(Platform::TikTok->supportsTextOnly())->toBeFalse();
    expect(Platform::YouTube->supportsTextOnly())->toBeFalse();
    expect(Platform::Instagram->supportsTextOnly())->toBeFalse();
    expect(Platform::Pinterest->supportsTextOnly())->toBeFalse();
});

test('platform exposes the correct default token TTL fallback', function () {
    // X access tokens live 2 hours.
    expect(Platform::X->defaultTokenTtlSeconds())->toBe(7200);

    // Instagram and Threads use Meta's 60-day long-lived token.
    expect(Platform::Instagram->defaultTokenTtlSeconds())->toBe(5184000);
    expect(Platform::Threads->defaultTokenTtlSeconds())->toBe(5184000);

    // Networks that always return expires_in, set a fixed lifetime directly, or
    // never expire have no fallback here.
    expect(Platform::LinkedIn->defaultTokenTtlSeconds())->toBeNull();
    expect(Platform::TikTok->defaultTokenTtlSeconds())->toBeNull();
    expect(Platform::YouTube->defaultTokenTtlSeconds())->toBeNull();
    expect(Platform::Pinterest->defaultTokenTtlSeconds())->toBeNull();
    expect(Platform::Bluesky->defaultTokenTtlSeconds())->toBeNull();
    expect(Platform::Facebook->defaultTokenTtlSeconds())->toBeNull();
});

test('platform is enabled by default for every platform', function (Platform $platform) {
    expect($platform->isEnabled())->toBeTrue();
})->with([
    Platform::LinkedIn,
    Platform::LinkedInPage,
    Platform::X,
    Platform::TikTok,
    Platform::YouTube,
    Platform::Facebook,
    Platform::Instagram,
    Platform::InstagramFacebook,
    Platform::Threads,
    Platform::Pinterest,
    Platform::Bluesky,
    Platform::Mastodon,
    Platform::Telegram,
    Platform::Discord,
]);

test('each platform can be disabled via config', function (Platform $platform) {
    config(["trypost.platforms.{$platform->value}.enabled" => false]);

    expect($platform->isEnabled())->toBeFalse();
})->with([
    Platform::LinkedIn,
    Platform::LinkedInPage,
    Platform::X,
    Platform::TikTok,
    Platform::YouTube,
    Platform::Facebook,
    Platform::Instagram,
    Platform::InstagramFacebook,
    Platform::Threads,
    Platform::Pinterest,
    Platform::Bluesky,
    Platform::Mastodon,
    Platform::Telegram,
    Platform::Discord,
]);

test('each platform maps to its publishing queue', function (Platform $platform, string $queue) {
    expect($platform->queue())->toBe($queue);
})->with([
    [Platform::LinkedIn, 'social-linkedin'],
    [Platform::LinkedInPage, 'social-linkedin-page'],
    [Platform::X, 'social-x'],
    [Platform::TikTok, 'social-tiktok'],
    [Platform::YouTube, 'social-youtube'],
    [Platform::Facebook, 'social-facebook'],
    [Platform::Instagram, 'social-instagram'],
    [Platform::InstagramFacebook, 'social-instagram-facebook'],
    [Platform::Threads, 'social-threads'],
    [Platform::Pinterest, 'social-pinterest'],
    [Platform::Bluesky, 'social-bluesky'],
    [Platform::Mastodon, 'social-mastodon'],
    [Platform::Telegram, 'social-telegram'],
    [Platform::Discord, 'social-discord'],
]);

test('allQueues lists every platform publishing queue in enum order', function () {
    expect(Platform::allQueues())->toBe([
        'social-linkedin',
        'social-linkedin-page',
        'social-x',
        'social-tiktok',
        'social-youtube',
        'social-facebook',
        'social-instagram',
        'social-instagram-facebook',
        'social-threads',
        'social-pinterest',
        'social-bluesky',
        'social-mastodon',
        'social-telegram',
        'social-discord',
    ])->and(Platform::allQueues())->toHaveCount(count(Platform::cases()));
});

test('enabledQueues matches allQueues when every platform is enabled', function () {
    foreach (Platform::cases() as $platform) {
        config(["trypost.platforms.{$platform->value}.enabled" => true]);
    }

    expect(Platform::enabledQueues())->toBe(Platform::allQueues());
});

test('disabling a platform removes only its queue from enabledQueues', function (Platform $disabled) {
    foreach (Platform::cases() as $platform) {
        config(["trypost.platforms.{$platform->value}.enabled" => true]);
    }

    config(["trypost.platforms.{$disabled->value}.enabled" => false]);

    $enabledQueues = Platform::enabledQueues();

    expect($enabledQueues)
        ->not->toContain($disabled->queue())
        ->toHaveCount(count(Platform::cases()) - 1);

    foreach (Platform::cases() as $platform) {
        if ($platform === $disabled) {
            continue;
        }

        expect($enabledQueues)->toContain($platform->queue());
    }

    expect(Platform::allQueues())->toContain($disabled->queue());
})->with([
    Platform::LinkedIn,
    Platform::LinkedInPage,
    Platform::X,
    Platform::TikTok,
    Platform::YouTube,
    Platform::Facebook,
    Platform::Instagram,
    Platform::InstagramFacebook,
    Platform::Threads,
    Platform::Pinterest,
    Platform::Bluesky,
    Platform::Mastodon,
    Platform::Telegram,
    Platform::Discord,
]);

test('disabling every platform yields no enabled queues', function () {
    foreach (Platform::cases() as $platform) {
        config(["trypost.platforms.{$platform->value}.enabled" => false]);
    }

    expect(Platform::enabledQueues())->toBe([]);
});

test('enabledQueues is always a subset of allQueues', function () {
    config([
        'trypost.platforms.linkedin.enabled' => true,
        'trypost.platforms.linkedin-page.enabled' => false,
        'trypost.platforms.x.enabled' => false,
        'trypost.platforms.tiktok.enabled' => true,
        'trypost.platforms.youtube.enabled' => false,
        'trypost.platforms.facebook.enabled' => true,
        'trypost.platforms.instagram.enabled' => false,
        'trypost.platforms.instagram-facebook.enabled' => true,
        'trypost.platforms.threads.enabled' => false,
        'trypost.platforms.pinterest.enabled' => true,
        'trypost.platforms.bluesky.enabled' => false,
        'trypost.platforms.mastodon.enabled' => true,
        'trypost.platforms.telegram.enabled' => false,
        'trypost.platforms.discord.enabled' => true,
    ]);

    $enabledQueues = Platform::enabledQueues();

    expect($enabledQueues)->toEqual(array_values(array_intersect(Platform::allQueues(), $enabledQueues)))
        ->and(array_diff($enabledQueues, Platform::allQueues()))->toBe([]);
});

test('horizon social publishing queues match enabledQueues', function () {
    expect(config('horizon.defaults.social-publishing.queue'))->toBe(Platform::enabledQueues());
});

test('isEnabled falls back to env when enabled config is missing', function (Platform $platform, string $envKey) {
    $platforms = config('trypost.platforms');
    unset($platforms[$platform->value]['enabled']);
    config(['trypost.platforms' => $platforms]);

    $original = getenv($envKey);
    putenv("{$envKey}=false");

    try {
        expect($platform->isEnabled())->toBeFalse();
    } finally {
        if ($original === false) {
            putenv($envKey);
        } else {
            putenv("{$envKey}={$original}");
        }
    }
})->with([
    [Platform::LinkedIn, 'LINKEDIN_ENABLED'],
    [Platform::LinkedInPage, 'LINKEDIN_PAGE_ENABLED'],
    [Platform::X, 'X_ENABLED'],
    [Platform::InstagramFacebook, 'INSTAGRAM_FACEBOOK_ENABLED'],
    [Platform::Telegram, 'TELEGRAM_ENABLED'],
    [Platform::Discord, 'DISCORD_ENABLED'],
]);

test('linkedin pages and instagram facebook are not directly connectable', function () {
    expect(Platform::LinkedInPage->isConnectable())->toBeFalse();
    expect(Platform::LinkedIn->isConnectable())->toBeTrue();
    expect(Platform::InstagramFacebook->isConnectable())->toBeFalse();
    expect(Platform::Instagram->isConnectable())->toBeTrue();
});

test('the linkedin card is connectable while either capability is enabled', function () {
    config(['trypost.platforms.linkedin.enabled' => true, 'trypost.platforms.linkedin-page.enabled' => false]);
    expect(Platform::LinkedIn->isConnectable())->toBeTrue();

    config(['trypost.platforms.linkedin.enabled' => false, 'trypost.platforms.linkedin-page.enabled' => true]);
    expect(Platform::LinkedIn->isConnectable())->toBeTrue();

    config(['trypost.platforms.linkedin.enabled' => false, 'trypost.platforms.linkedin-page.enabled' => false]);
    expect(Platform::LinkedIn->isConnectable())->toBeFalse();

    // A company page never gets its own card regardless of toggles.
    config(['trypost.platforms.linkedin-page.enabled' => true]);
    expect(Platform::LinkedInPage->isConnectable())->toBeFalse();
});

test('the instagram card is connectable while either capability is enabled', function () {
    config(['trypost.platforms.instagram.enabled' => true, 'trypost.platforms.instagram-facebook.enabled' => false]);
    expect(Platform::Instagram->isConnectable())->toBeTrue();

    config(['trypost.platforms.instagram.enabled' => false, 'trypost.platforms.instagram-facebook.enabled' => true]);
    expect(Platform::Instagram->isConnectable())->toBeTrue();

    config(['trypost.platforms.instagram.enabled' => false, 'trypost.platforms.instagram-facebook.enabled' => false]);
    expect(Platform::Instagram->isConnectable())->toBeFalse();

    // Via-Facebook never gets its own card regardless of toggles.
    config(['trypost.platforms.instagram-facebook.enabled' => true]);
    expect(Platform::InstagramFacebook->isConnectable())->toBeFalse();
});

test('connectable options are sorted alphabetically by label', function () {
    $labels = array_column(Platform::connectableOptions(), 'label');

    $sorted = $labels;
    natcasesort($sorted);

    expect($labels)->toBe(array_values($sorted));
});

test('connectable options include a single instagram card and omit the facebook variant', function () {
    $values = array_column(Platform::connectableOptions(), 'value');

    expect($values)->toContain(Platform::Instagram->value)
        ->and($values)->not->toContain(Platform::InstagramFacebook->value);
});

test('instagram connectable option lists only enabled connect methods', function () {
    config(['trypost.platforms.instagram.enabled' => true, 'trypost.platforms.instagram-facebook.enabled' => false]);

    expect(Platform::instagramConnectMethods())->toBe([Platform::Instagram->value]);

    $instagram = collect(Platform::connectableOptions())->firstWhere('value', Platform::Instagram->value);

    expect($instagram['connect_methods'])->toBe([Platform::Instagram->value]);

    config(['trypost.platforms.instagram.enabled' => false, 'trypost.platforms.instagram-facebook.enabled' => true]);

    expect(Platform::instagramConnectMethods())->toBe([Platform::InstagramFacebook->value]);

    config(['trypost.platforms.instagram.enabled' => true, 'trypost.platforms.instagram-facebook.enabled' => true]);

    expect(Platform::instagramConnectMethods())->toBe([
        Platform::Instagram->value,
        Platform::InstagramFacebook->value,
    ]);
});

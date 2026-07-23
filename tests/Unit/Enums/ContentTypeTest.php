<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;

test('content type has correct labels', function () {
    expect(ContentType::InstagramFeed->label())->toBe('Feed Post');
    expect(ContentType::InstagramReel->label())->toBe('Reel');
    expect(ContentType::InstagramStory->label())->toBe('Story');
    expect(ContentType::LinkedInPost->label())->toBe('Post');
    expect(ContentType::YouTubeShort->label())->toBe('Short');
    expect(ContentType::XPost->label())->toBe('Post');
    expect(ContentType::TikTokVideo->label())->toBe('Video');
    expect(ContentType::PinterestPin->label())->toBe('Pin');
    expect(ContentType::PinterestVideoPin->label())->toBe('Video Pin');
    expect(ContentType::BlueskyPost->label())->toBe('Post');
    expect(ContentType::MastodonPost->label())->toBe('Post');
});

test('content type has correct descriptions', function () {
    expect(ContentType::InstagramFeed->description())->toContain('feed');
    expect(ContentType::InstagramReel->description())->toContain('90 seconds');
    expect(ContentType::InstagramStory->description())->toContain('24 hours');
    expect(ContentType::YouTubeShort->description())->toContain('3 minutes');
});

test('content type maps to correct platform', function () {
    expect(ContentType::InstagramFeed->platform())->toBe(Platform::Instagram);
    expect(ContentType::InstagramReel->platform())->toBe(Platform::Instagram);
    expect(ContentType::LinkedInPost->platform())->toBe(Platform::LinkedIn);
    expect(ContentType::LinkedInPagePost->platform())->toBe(Platform::LinkedInPage);
    expect(ContentType::FacebookPost->platform())->toBe(Platform::Facebook);
    expect(ContentType::TikTokVideo->platform())->toBe(Platform::TikTok);
    expect(ContentType::YouTubeShort->platform())->toBe(Platform::YouTube);
    expect(ContentType::XPost->platform())->toBe(Platform::X);
    expect(ContentType::ThreadsPost->platform())->toBe(Platform::Threads);
    expect(ContentType::PinterestPin->platform())->toBe(Platform::Pinterest);
    expect(ContentType::BlueskyPost->platform())->toBe(Platform::Bluesky);
    expect(ContentType::MastodonPost->platform())->toBe(Platform::Mastodon);
});

test('content type has correct aspect ratios', function () {
    expect(ContentType::InstagramFeed->aspectRatio())->toBe('4:5');
    expect(ContentType::InstagramReel->aspectRatio())->toBe('9:16');
    expect(ContentType::InstagramStory->aspectRatio())->toBe('9:16');
    expect(ContentType::YouTubeShort->aspectRatio())->toBe('9:16');
    expect(ContentType::TikTokVideo->aspectRatio())->toBe('9:16');
    expect(ContentType::PinterestPin->aspectRatio())->toBe('2:3');
    expect(ContentType::LinkedInPost->aspectRatio())->toBeNull();
    expect(ContentType::XPost->aspectRatio())->toBeNull();
});

test('instagram_carousel is not a content type — it is an AI generation format only', function () {
    expect(ContentType::tryFrom('instagram_carousel'))->toBeNull();
});

test('content type has correct max media count', function () {
    expect(ContentType::InstagramFeed->maxMediaCount())->toBe(10);
    expect(ContentType::InstagramReel->maxMediaCount())->toBe(1);
    expect(ContentType::LinkedInPost->maxMediaCount())->toBe(10);
    expect(ContentType::XPost->maxMediaCount())->toBe(4);
    expect(ContentType::PinterestCarousel->maxMediaCount())->toBe(5);
    expect(ContentType::BlueskyPost->maxMediaCount())->toBe(4);
});

test('content type supports video correctly', function () {
    expect(ContentType::InstagramFeed->supportsVideo())->toBeTrue();
    expect(ContentType::InstagramReel->supportsVideo())->toBeTrue();
    expect(ContentType::TikTokVideo->supportsVideo())->toBeTrue();
    expect(ContentType::YouTubeShort->supportsVideo())->toBeTrue();
    expect(ContentType::LinkedInPost->supportsVideo())->toBeTrue();
    expect(ContentType::PinterestPin->supportsVideo())->toBeFalse();
});

test('content type supports image correctly', function () {
    expect(ContentType::InstagramFeed->supportsImage())->toBeTrue();
    expect(ContentType::InstagramStory->supportsImage())->toBeTrue();
    expect(ContentType::LinkedInPost->supportsImage())->toBeTrue();
    expect(ContentType::InstagramReel->supportsImage())->toBeFalse();
    expect(ContentType::FacebookReel->supportsImage())->toBeFalse();
    expect(ContentType::FacebookStory->supportsImage())->toBeFalse();
    expect(ContentType::TikTokVideo->supportsImage())->toBeFalse();
    expect(ContentType::YouTubeShort->supportsImage())->toBeFalse();
});

test('content type supports mixed media correctly', function () {
    // A Bluesky embed is images XOR video, so it can't carry both.
    expect(ContentType::BlueskyPost->supportsMixedMedia())->toBeFalse();
    // Multi-attachment messengers can mix an image and a video.
    expect(ContentType::DiscordMessage->supportsMixedMedia())->toBeTrue();
    expect(ContentType::TelegramPost->supportsMixedMedia())->toBeTrue();
});

test('content type requires media correctly', function () {
    expect(ContentType::InstagramReel->requiresMedia())->toBeTrue();
    expect(ContentType::TikTokVideo->requiresMedia())->toBeTrue();
    expect(ContentType::YouTubeShort->requiresMedia())->toBeTrue();
    expect(ContentType::PinterestPin->requiresMedia())->toBeTrue();
    expect(ContentType::InstagramFeed->requiresMedia())->toBeFalse();
    expect(ContentType::LinkedInPost->requiresMedia())->toBeFalse();
    expect(ContentType::XPost->requiresMedia())->toBeFalse();
    expect(ContentType::ThreadsPost->requiresMedia())->toBeFalse();
    expect(ContentType::BlueskyPost->requiresMedia())->toBeFalse();
    expect(ContentType::MastodonPost->requiresMedia())->toBeFalse();
});

test('can get content types for platform', function () {
    $instagramTypes = ContentType::forPlatform(Platform::Instagram);

    expect($instagramTypes)->toContain(ContentType::InstagramFeed);
    expect($instagramTypes)->toContain(ContentType::InstagramReel);
    expect($instagramTypes)->toContain(ContentType::InstagramStory);
    expect($instagramTypes)->not->toContain(ContentType::LinkedInPost);
});

test('can get default content type for platform', function () {
    expect(ContentType::defaultFor(Platform::Instagram))->toBe(ContentType::InstagramFeed);
    expect(ContentType::defaultFor(Platform::LinkedIn))->toBe(ContentType::LinkedInPost);
    expect(ContentType::defaultFor(Platform::YouTube))->toBe(ContentType::YouTubeShort);
    expect(ContentType::defaultFor(Platform::X))->toBe(ContentType::XPost);
    expect(ContentType::defaultFor(Platform::TikTok))->toBe(ContentType::TikTokVideo);
    expect(ContentType::defaultFor(Platform::Pinterest))->toBe(ContentType::PinterestPin);
    expect(ContentType::defaultFor(Platform::Bluesky))->toBe(ContentType::BlueskyPost);
    expect(ContentType::defaultFor(Platform::Mastodon))->toBe(ContentType::MastodonPost);
    expect(ContentType::defaultFor(Platform::LinkedInPage))->toBe(ContentType::LinkedInPagePost);
    expect(ContentType::defaultFor(Platform::Facebook))->toBe(ContentType::FacebookPost);
    expect(ContentType::defaultFor(Platform::Threads))->toBe(ContentType::ThreadsPost);
});

test('content type has complete descriptions', function () {
    expect(ContentType::TikTokVideo->description())->toContain('video');
    expect(ContentType::XPost->description())->toContain('Tweet');
    expect(ContentType::ThreadsPost->description())->toContain('Text post');
    expect(ContentType::PinterestPin->description())->toContain('image pin');
    expect(ContentType::PinterestVideoPin->description())->toContain('Video pin');
    expect(ContentType::PinterestCarousel->description())->toContain('carousel');
    expect(ContentType::BlueskyPost->description())->toContain('images');
    expect(ContentType::MastodonPost->description())->toContain('media');
});

test('pinterest video pin supports video', function () {
    expect(ContentType::PinterestVideoPin->supportsVideo())->toBeTrue();
    expect(ContentType::PinterestVideoPin->supportsImage())->toBeFalse();
});

test('bluesky and mastodon support video', function () {
    expect(ContentType::BlueskyPost->supportsVideo())->toBeTrue();
    expect(ContentType::MastodonPost->supportsVideo())->toBeTrue();
});

test('linkedin post content types accept images, videos and documents but never mixed', function () {
    foreach ([ContentType::LinkedInPost, ContentType::LinkedInPagePost] as $type) {
        expect($type->supportsImage())->toBeTrue();
        expect($type->supportsVideo())->toBeTrue();
        expect($type->supportsDocument())->toBeTrue();
        expect($type->supportsMixedMedia())->toBeFalse();
        expect($type->requiresMedia())->toBeFalse();
        expect($type->maxMediaCount())->toBe(10);
    }
});

test('only linkedin post content types support documents', function () {
    expect(ContentType::LinkedInPost->supportsDocument())->toBeTrue();
    expect(ContentType::LinkedInPagePost->supportsDocument())->toBeTrue();
    expect(ContentType::InstagramFeed->supportsDocument())->toBeFalse();
    expect(ContentType::XPost->supportsDocument())->toBeFalse();
});

test('linkedin exposes a single post content type per account kind', function () {
    expect(ContentType::forPlatform(Platform::LinkedIn))->toHaveCount(1)->toContain(ContentType::LinkedInPost);
    expect(ContentType::forPlatform(Platform::LinkedInPage))->toHaveCount(1)->toContain(ContentType::LinkedInPagePost);
});

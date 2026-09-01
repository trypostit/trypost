<?php

declare(strict_types=1);

use App\Enums\Media\Type as MediaType;
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
    expect(ContentType::InstagramReel->description())->toContain('15 minutes');
    expect(ContentType::FacebookReel->description())->toContain('90 seconds');
    expect(ContentType::InstagramStory->description())->toContain('24 hours');
    expect(ContentType::YouTubeShort->description())->toContain('3 minutes');
});

test('content type exposes max video duration in seconds', function () {
    expect(ContentType::InstagramReel->maxVideoDurationSec())->toBe(15 * 60);
    expect(ContentType::FacebookReel->maxVideoDurationSec())->toBe(90);
    expect(ContentType::YouTubeShort->maxVideoDurationSec())->toBe(3 * 60);
    expect(ContentType::TikTokVideo->maxVideoDurationSec())->toBeNull();
});

test('media rules for frontend expose the full editor rule set keyed by content type', function () {
    $rules = ContentType::mediaRulesForFrontend();

    expect($rules)->toHaveCount(count(ContentType::cases()));

    expect($rules['instagram_reel'])->toMatchArray([
        'max_files' => 1,
        'accept_images' => false,
        'accept_videos' => true,
        'requires_media' => true,
        'max_video_bytes' => 1 * 1024 * 1024 * 1024,
        'max_video_duration_sec' => 900,
        'aspect_ratio_min' => 0.5,
        'aspect_ratio_max' => 0.6,
    ]);

    expect($rules['facebook_reel']['max_video_duration_sec'])->toBe(90);
    expect($rules['tiktok_video']['max_video_duration_sec'])->toBeNull();
    expect($rules['linkedin_post']['max_document_bytes'])->toBe(100 * 1024 * 1024);
    expect($rules['pinterest_carousel']['min_files'])->toBe(2);
    expect($rules['x_post']['accepts_gif'])->toBeTrue();
    expect($rules['instagram_feed']['requires_media'])->toBeTrue();
    expect($rules['discord_message']['accepts_gif'])->toBeTrue();
    expect($rules['telegram_post']['accepts_gif'])->toBeTrue();
});

test('listing array mirrors media capability fields for api and mcp', function () {
    $listing = ContentType::PinterestCarousel->toListingArray();

    expect($listing)->toMatchArray([
        'value' => 'pinterest_carousel',
        'max_media_count' => 5,
        'min_media_count' => 2,
        'requires_media' => true,
        'accept_images' => true,
        'accept_videos' => false,
    ]);

    expect(ContentType::InstagramReel->toListingArray()['accept_images'])->toBeFalse();
});

test('media rules reuse enum capability helpers', function () {
    $rules = ContentType::InstagramStory->mediaRules();

    expect($rules['accept_images'])->toBe(ContentType::InstagramStory->supportsImage())
        ->and($rules['accept_videos'])->toBe(ContentType::InstagramStory->supportsVideo())
        ->and($rules['auto_fits_image'])->toBeTrue()
        ->and($rules['max_files'])->toBe(ContentType::InstagramStory->maxMediaCount());
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
    expect(ContentType::InstagramFeed->requiresMedia())->toBeTrue();
    expect(ContentType::TikTokVideo->requiresMedia())->toBeTrue();
    expect(ContentType::YouTubeShort->requiresMedia())->toBeTrue();
    expect(ContentType::PinterestPin->requiresMedia())->toBeTrue();
    expect(ContentType::LinkedInPost->requiresMedia())->toBeFalse();
    expect(ContentType::XPost->requiresMedia())->toBeFalse();
    expect(ContentType::ThreadsPost->requiresMedia())->toBeFalse();
    expect(ContentType::BlueskyPost->requiresMedia())->toBeFalse();
    expect(ContentType::MastodonPost->requiresMedia())->toBeFalse();
});

test('media rules keep editor parity for gif and requires_media flags', function () {
    expect(ContentType::DiscordMessage->acceptsGif())->toBeTrue();
    expect(ContentType::TelegramPost->acceptsGif())->toBeTrue();
    expect(ContentType::InstagramFeed->mediaRules()['requires_media'])->toBeTrue();
    expect(ContentType::DiscordMessage->mediaRules()['accepts_gif'])->toBeTrue();
});

/**
 * Lock the flags / limits that used to live in the Vue CONTENT_TYPE_RULES map
 * so a future centralization drift cannot silently flip editor behavior again.
 * Byte caps are the post-clamp values (min(platform, trypost.media hard limit)).
 */
test('media rules preserve pre-centralization editor limits for mapped types', function () {
    $mb = 1024 * 1024;
    $gb = 1024 * $mb;
    $hardImage = MediaType::Image->maxSizeInBytes();
    $hardVideo = MediaType::Video->maxSizeInBytes();
    $hardDocument = MediaType::Document->maxSizeInBytes();

    $expected = [
        'instagram_feed' => [
            'requires_media' => true,
            'accepts_gif' => false,
            'max_files' => 10,
            'max_video_duration_sec' => 60,
            'max_image_bytes' => min(8 * $mb, $hardImage),
            'max_video_bytes' => min(100 * $mb, $hardVideo),
        ],
        'instagram_reel' => [
            'requires_media' => true,
            'accepts_gif' => false,
            'max_files' => 1,
            'max_video_duration_sec' => 900,
            'max_video_bytes' => min(1 * $gb, $hardVideo),
        ],
        'youtube_short' => [
            'requires_media' => true,
            'accepts_gif' => false,
            'max_files' => 1,
            'max_video_duration_sec' => 180,
            // Platform advertises 256GB; editor must not exceed upload hard cap.
            'max_video_bytes' => $hardVideo,
        ],
        'pinterest_pin' => [
            'requires_media' => true,
            'accepts_gif' => false,
            'max_files' => 1,
            // Platform advertises 20MB; hard image cap is typically 10MB.
            'max_image_bytes' => $hardImage,
        ],
        'facebook_post' => [
            'requires_media' => false,
            'accepts_gif' => false,
            'max_files' => 10,
            'max_video_duration_sec' => 240 * 60,
            'max_video_bytes' => $hardVideo,
        ],
        'linkedin_post' => [
            'requires_media' => false,
            'accepts_gif' => false,
            'max_files' => 10,
            'max_document_bytes' => min(100 * $mb, $hardDocument),
            'max_video_bytes' => $hardVideo,
        ],
        'x_post' => [
            'requires_media' => false,
            'accepts_gif' => true,
            'max_files' => 4,
            'max_video_duration_sec' => 140,
            'max_video_bytes' => min(512 * $mb, $hardVideo),
        ],
        'discord_message' => [
            'requires_media' => false,
            'accepts_gif' => true,
            'max_files' => 10,
        ],
        'telegram_post' => [
            'requires_media' => false,
            'accepts_gif' => true,
            'max_files' => 10,
        ],
    ];

    foreach ($expected as $type => $fields) {
        $rules = ContentType::from($type)->mediaRules();

        foreach ($fields as $key => $value) {
            expect($rules[$key])->toBe($value, "{$type}.{$key}");
        }
    }
});

test('media byte caps never exceed the global upload hard limits', function () {
    $hardImage = MediaType::Image->maxSizeInBytes();
    $hardVideo = MediaType::Video->maxSizeInBytes();
    $hardDocument = MediaType::Document->maxSizeInBytes();

    foreach (ContentType::cases() as $type) {
        $image = $type->maxImageBytes();
        $video = $type->maxVideoBytes();
        $document = $type->maxDocumentBytes();

        if ($image !== null) {
            expect($image)->toBeLessThanOrEqual($hardImage, "{$type->value}.max_image_bytes");
        }

        if ($video !== null) {
            expect($video)->toBeLessThanOrEqual($hardVideo, "{$type->value}.max_video_bytes");
        }

        if ($document !== null) {
            expect($document)->toBeLessThanOrEqual($hardDocument, "{$type->value}.max_document_bytes");
        }
    }

    expect(ContentType::YouTubeShort->maxVideoBytes())->toBe($hardVideo)
        ->and(ContentType::PinterestPin->maxImageBytes())->toBe($hardImage)
        ->and(ContentType::FacebookPost->maxVideoBytes())->toBe($hardVideo);
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

test('google business post maps to the google business platform', function () {
    expect(ContentType::GoogleBusinessPost->platform())->toBe(Platform::GoogleBusiness);
});

test('google business post media rules allow at most one image, no video', function () {
    expect(ContentType::GoogleBusinessPost->maxMediaCount())->toBe(1);
    expect(ContentType::GoogleBusinessPost->supportsVideo())->toBeFalse();
    expect(ContentType::GoogleBusinessPost->supportsImage())->toBeTrue();
    expect(ContentType::GoogleBusinessPost->requiresMedia())->toBeFalse();
});

test('google business post is the default content type for the platform', function () {
    expect(ContentType::defaultFor(Platform::GoogleBusiness))->toBe(ContentType::GoogleBusinessPost);
});

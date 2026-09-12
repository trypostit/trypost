<?php

declare(strict_types=1);

use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Rules\ContentTypeCompatibleWithMedia;

function runMediaRule(string $contentType, array $media): array
{
    $errors = [];
    $rule = (new ContentTypeCompatibleWithMedia)->setData(['media' => $media]);
    $rule->validate('platforms.0.content_type', $contentType, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    return $errors;
}

test('passes when content type does not require media and none provided', function () {
    expect(runMediaRule(ContentType::LinkedInPost->value, []))->toBe([]);
    expect(runMediaRule(ContentType::FacebookPost->value, []))->toBe([]);
    expect(runMediaRule(ContentType::XPost->value, []))->toBe([]);
});

test('fails when content type requires media and none provided', function () {
    $errors = runMediaRule(ContentType::InstagramReel->value, []);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('requires at least one media file');
});

test('fails when content type does not support images and an image is present', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg']];

    $errors = runMediaRule(ContentType::TikTokVideo->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('does not support images');
});

test('fails when content type does not support video and a video is present', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4']];

    $errors = runMediaRule(ContentType::PinterestPin->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('does not support videos');
});

test('youtube short rejects images', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg']];

    $errors = runMediaRule(ContentType::YouTubeShort->value, $media);

    expect($errors[0])->toContain('does not support images');
});

test('passes when image-only content type receives an image', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/png']];

    expect(runMediaRule(ContentType::PinterestPin->value, $media))->toBe([]);
});

test('passes when video-only content type receives a video', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4']];

    expect(runMediaRule(ContentType::TikTokVideo->value, $media))->toBe([]);
    expect(runMediaRule(ContentType::YouTubeShort->value, $media))->toBe([]);
    expect(runMediaRule(ContentType::InstagramReel->value, $media))->toBe([]);
    expect(runMediaRule(ContentType::FacebookStory->value, $media))->toBe([]);
});

test('facebook story rejects images', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg']];

    $errors = runMediaRule(ContentType::FacebookStory->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('does not support images');
});

test('instagram story accepts images', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg']];

    expect(runMediaRule(ContentType::InstagramStory->value, $media))->toBe([]);
});

test('detects media type from mime when type field is missing', function () {
    $media = [['mime_type' => 'image/jpeg']];

    $errors = runMediaRule(ContentType::TikTokVideo->value, $media);

    expect($errors)->toHaveCount(1);
});

test('bluesky rejects an image and a video in the same post', function () {
    $media = [
        ['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg'],
        ['type' => MediaType::Video->value, 'mime_type' => 'video/mp4'],
    ];

    $errors = runMediaRule(ContentType::BlueskyPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain("can't combine an image and a video");
});

test('bluesky still accepts an image-only or video-only post', function () {
    $image = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg']];
    $video = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4']];

    expect(runMediaRule(ContentType::BlueskyPost->value, $image))->toBe([]);
    expect(runMediaRule(ContentType::BlueskyPost->value, $video))->toBe([]);
});

test('bluesky rejects a mov video', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/quicktime']];

    $errors = runMediaRule(ContentType::BlueskyPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('does not accept MOV');
});

test('bluesky rejects a mov video identified only by filename', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'original_filename' => 'clip.mov']];

    $errors = runMediaRule(ContentType::BlueskyPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('does not accept MOV');
});

test('x still accepts a mov video', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/quicktime']];

    expect(runMediaRule(ContentType::XPost->value, $media))->toBe([]);
});

test('a gif is rejected on content types that do not accept gifs', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/gif']];

    foreach ([ContentType::InstagramFeed, ContentType::LinkedInPost, ContentType::PinterestPin, ContentType::FacebookPost] as $type) {
        $errors = runMediaRule($type->value, $media);

        expect($errors)->toHaveCount(1, $type->value);
        expect($errors[0])->toContain('does not accept GIF');
    }
});

test('a gif passes on content types that accept gifs', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/gif']];

    foreach ([ContentType::XPost, ContentType::BlueskyPost, ContentType::MastodonPost, ContentType::DiscordMessage, ContentType::TelegramPost] as $type) {
        expect(runMediaRule($type->value, $media))->toBe([], $type->value);
    }
});

test('an image over the content type cap is rejected by size', function () {
    // 2 000 001 bytes: one byte over Bluesky's lexicon maxSize, and under 2 MiB — proves decimal, not MiB.
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg', 'size' => 2_000_001]];

    $errors = runMediaRule(ContentType::BlueskyPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('accepts an image of up to');
    expect($errors[0])->toContain('2 MB');
});

test('decimal caps are reported in decimal units for both the cap and the file', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'size' => 305_000_000]];

    $errors = runMediaRule(ContentType::BlueskyPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('up to 300 MB (yours is 305.0 MB)');
});

test('binary caps keep binary units', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'size' => 320 * 1024 * 1024]];

    $errors = runMediaRule(ContentType::InstagramReel->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('up to 300 MB (yours is 320.0 MB)');
});

test('an image exactly at the content type cap passes', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg', 'size' => 2_000_000]];

    expect(runMediaRule(ContentType::BlueskyPost->value, $media))->toBe([]);
});

test('a video over the content type cap is rejected by size', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'size' => 300 * 1024 * 1024 + 1]];

    $errors = runMediaRule(ContentType::InstagramReel->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('accepts a video of up to');
});

test('a pdf over the content type cap is rejected by size', function () {
    $max = ContentType::LinkedInPost->maxDocumentBytes();
    $media = [['type' => MediaType::Document->value, 'mime_type' => 'application/pdf', 'size' => $max + 1]];

    $errors = runMediaRule(ContentType::LinkedInPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('accepts a PDF of up to');
});

test('media without a size is not checked against byte caps', function () {
    // Legacy snapshots written before `size` was recorded, and external URLs
    // the API has not downloaded yet, carry no size — we cannot judge them.
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4']];

    expect(runMediaRule(ContentType::InstagramReel->value, $media))->toBe([]);
});

test('a video longer than the content type cap is rejected by duration', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'meta' => ['duration' => 61.4]]];

    $errors = runMediaRule(ContentType::InstagramStory->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('accepts videos of up to 1 min');
    expect($errors[0])->toContain('1 min 2s');
});

test('a video within the duration cap passes and a video without duration is not checked', function () {
    $within = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'meta' => ['duration' => 60]]];
    $unknown = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'meta' => []]];

    expect(runMediaRule(ContentType::InstagramStory->value, $within))->toBe([]);
    expect(runMediaRule(ContentType::InstagramStory->value, $unknown))->toBe([]);
});

test('duration is ignored on content types without a duration cap', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'meta' => ['duration' => 3 * 60 * 60]]];

    expect(ContentType::DiscordMessage->maxVideoDurationSec())->toBeNull();
    expect(runMediaRule(ContentType::DiscordMessage->value, $media))->toBe([]);
});

test('bluesky rejects an animated gif combined with a video', function () {
    // A GIF counts as an image, so gif + video is still mixed media.
    $media = [
        ['type' => MediaType::Image->value, 'mime_type' => 'image/gif'],
        ['type' => MediaType::Video->value, 'mime_type' => 'video/mp4'],
    ];

    $errors = runMediaRule(ContentType::BlueskyPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain("can't combine an image and a video");
});

test('a mixed-media content type accepts an image and a video together', function () {
    $media = [
        ['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg'],
        ['type' => MediaType::Video->value, 'mime_type' => 'video/mp4'],
    ];

    expect(runMediaRule(ContentType::DiscordMessage->value, $media))->toBe([]);
});

test('linkedin post accepts a pdf on its own', function () {
    $media = [['type' => MediaType::Document->value, 'mime_type' => 'application/pdf']];

    expect(runMediaRule(ContentType::LinkedInPost->value, $media))->toBe([]);
    expect(runMediaRule(ContentType::LinkedInPagePost->value, $media))->toBe([]);
});

test('linkedin post detects a pdf from mime when type field is missing', function () {
    $media = [['mime_type' => 'application/pdf']];

    expect(runMediaRule(ContentType::LinkedInPost->value, $media))->toBe([]);
});

test('a pdf must be the only attachment on linkedin', function () {
    $media = [
        ['type' => MediaType::Document->value, 'mime_type' => 'application/pdf'],
        ['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg'],
    ];

    $errors = runMediaRule(ContentType::LinkedInPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('must be the only attachment');
});

test('linkedin rejects mixing an image and a video', function () {
    $media = [
        ['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg'],
        ['type' => MediaType::Video->value, 'mime_type' => 'video/mp4'],
    ];

    $errors = runMediaRule(ContentType::LinkedInPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain("can't combine an image and a video");
});

test('a pdf is rejected on content types that do not support documents', function () {
    $media = [['type' => MediaType::Document->value, 'mime_type' => 'application/pdf']];

    $errors = runMediaRule(ContentType::XPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('does not support PDF documents');
});

test('falls back to stored media when the request omits the media key', function () {
    // A PDF fallback on a document-capable type passes.
    $errors = [];
    (new ContentTypeCompatibleWithMedia([['type' => 'document', 'mime_type' => 'application/pdf']]))
        ->setData([]) // no 'media' key in the request -> use the fallback
        ->validate('platforms.0.content_type', ContentType::LinkedInPost->value, function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

    expect($errors)->toBe([]);

    // The same PDF fallback on X (no document support) is rejected — proving the fallback is used.
    $xErrors = [];
    (new ContentTypeCompatibleWithMedia([['type' => 'document', 'mime_type' => 'application/pdf']]))
        ->setData([])
        ->validate('platforms.0.content_type', ContentType::XPost->value, function (string $message) use (&$xErrors): void {
            $xErrors[] = $message;
        });

    expect($xErrors)->toHaveCount(1);
    expect($xErrors[0])->toContain('does not support PDF documents');
});

test('request media takes precedence over the stored fallback', function () {
    // Fallback is a lone PDF (would pass), but the request carries a PDF + image,
    // which must be rejected — proving the request media is used over the fallback.
    $errors = [];
    (new ContentTypeCompatibleWithMedia([['type' => 'document', 'mime_type' => 'application/pdf']]))
        ->setData(['media' => [
            ['type' => 'document', 'mime_type' => 'application/pdf'],
            ['type' => 'image', 'mime_type' => 'image/jpeg'],
        ]])
        ->validate('platforms.0.content_type', ContentType::LinkedInPost->value, function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('must be the only attachment');
});

test('does nothing for invalid content type values', function () {
    expect(runMediaRule('not_a_real_content_type', []))->toBe([]);
});

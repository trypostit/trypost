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

test('rejects more files than the content type allows', function () {
    $media = array_fill(0, ContentType::XPost->maxMediaCount() + 1, [
        'type' => MediaType::Image->value, 'mime_type' => 'image/jpeg',
    ]);

    $errors = runMediaRule(ContentType::XPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('allows at most');
});

test('rejects fewer files than the content type requires', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg']];

    $errors = runMediaRule(ContentType::PinterestCarousel->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('requires at least 2 media files');
});

test('rejects an animated gif when the content type does not accept gifs', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/gif']];

    $errors = runMediaRule(ContentType::LinkedInPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('does not support animated GIFs');
});

test('accepts an animated gif when the content type allows it', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/gif']];

    expect(runMediaRule(ContentType::XPost->value, $media))->toBe([]);
});

test('rejects an image over the content type size limit', function () {
    $media = [[
        'type' => MediaType::Image->value,
        'mime_type' => 'image/jpeg',
        'size' => ContentType::FacebookPost->maxImageBytes() + 1,
    ]];

    $errors = runMediaRule(ContentType::FacebookPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('images must be under');
});

test('rejects a video over the content type size limit', function () {
    $media = [[
        'type' => MediaType::Video->value,
        'mime_type' => 'video/mp4',
        'size' => ContentType::InstagramReel->maxVideoBytes() + 1,
    ]];

    $errors = runMediaRule(ContentType::InstagramReel->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('videos must be under');
});

test('rejects a video longer than the content type max duration', function () {
    $media = [[
        'type' => MediaType::Video->value,
        'mime_type' => 'video/mp4',
        'meta' => ['duration' => ContentType::FacebookReel->maxVideoDurationSec() + 10],
    ]];

    $errors = runMediaRule(ContentType::FacebookReel->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('videos must be under');
});

test('accepts a video with unknown duration rather than rejecting it', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4']];

    expect(runMediaRule(ContentType::FacebookReel->value, $media))->toBe([]);
});

test('rejects media outside the content type aspect ratio window', function () {
    $media = [[
        'type' => MediaType::Video->value,
        'mime_type' => 'video/mp4',
        'meta' => ['width' => 1000, 'height' => 1000],
    ]];

    $errors = runMediaRule(ContentType::InstagramReel->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('aspect ratio between 0.5 and 0.6');
});

test('youtube short accepts a square video', function () {
    $media = [[
        'type' => MediaType::Video->value,
        'mime_type' => 'video/mp4',
        'meta' => ['width' => 1000, 'height' => 1000],
    ]];

    expect(runMediaRule(ContentType::YouTubeShort->value, $media))->toBe([]);
});

test('instagram story auto-fits images so aspect ratio is not enforced for them', function () {
    $media = [[
        'type' => MediaType::Image->value,
        'mime_type' => 'image/jpeg',
        'meta' => ['width' => 2000, 'height' => 200],
    ]];

    expect(runMediaRule(ContentType::InstagramStory->value, $media))->toBe([]);
});

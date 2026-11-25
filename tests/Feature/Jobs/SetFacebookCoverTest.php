<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Http\Resources\App\PostCardResource;
use App\Jobs\SetFacebookCover;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

const FACEBOOK_COVER_URL = 'https://cdn.test/medias/cover.png';

beforeEach(function () {
    Queue::fake();

    $this->account = SocialAccount::factory()->facebook()->create([
        'platform_user_id' => 'page_123',
        'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_read_user_content', 'pages_manage_engagement'],
    ]);
    $this->post = Post::factory()->forAccount($this->account, ContentType::FacebookReel)->published()->create([
        'platform_post_id' => 'video_123',
        'media' => [[
            'id' => 'video-1',
            'path' => 'medias/video.mp4',
            'url' => 'https://cdn.test/medias/video.mp4',
            'type' => 'video',
            'mime_type' => 'video/mp4',
            'meta' => ['cover_source' => 'image', 'cover' => [
                'id' => 'cover-1',
                'path' => 'medias/cover.png',
                'url' => FACEBOOK_COVER_URL,
                'type' => 'image',
                'mime_type' => 'image/png',
            ]],
        ]],
    ]);
});

function facebookThumbnailsUrl(): string
{
    return config('trypost.platforms.facebook.graph_api').'/video_123/thumbnails';
}

function facebookCoverPng(): string
{
    return (string) (new ImageManager(Driver::class))->createImage(180, 320)->fill('336699')->encodeUsingMediaType('image/png');
}

function runSetFacebookCover(Post $post): SetFacebookCover
{
    $job = (new SetFacebookCover($post))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

test('a published reel gets its cover image as the preferred thumbnail', function () {
    Http::fake([
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['success' => true]),
    ]);

    runSetFacebookCover($this->post)->assertNotReleased();

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== facebookThumbnailsUrl()) {
            return false;
        }

        $parts = collect($request->data())->keyBy('name');

        return $request->isMultipart()
            && $request->hasFile('source')
            && str_starts_with((string) data_get($parts, 'source.contents'), "\xFF\xD8")
            && data_get($parts, 'is_preferred.contents') === 'true';
    });
    expect($this->post->fresh()->hasFacebookCoverWarning())->toBeFalse();
});

test('a cover facebook refuses leaves a warning and the reel published', function () {
    Http::fake([
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['error' => ['message' => 'Invalid parameter', 'type' => 'OAuthException', 'code' => 100]], 400),
    ]);

    runSetFacebookCover($this->post)->assertNotReleased();

    $post = $this->post->fresh();
    expect($post->hasFacebookCoverWarning())->toBeTrue()
        ->and($post->status->value)->toBe('published')
        ->and(PostCardResource::make($post)->resolve()['facebook_cover_warning'])->toBeTrue();
});

test('a channel without the reel cover scopes leaves a warning without asking facebook', function () {
    Http::fake();
    $this->account->update(['scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts']]);

    runSetFacebookCover($this->post);

    Http::assertNothingSent();
    expect($this->post->fresh()->hasFacebookCoverWarning())->toBeTrue();
});

test('a reel without a cover asks facebook nothing', function () {
    Http::fake();
    $this->post->update(['media' => [[...$this->post->media[0], 'meta' => []]]]);

    runSetFacebookCover($this->post);

    Http::assertNothingSent();
    expect($this->post->fresh()->hasFacebookCoverWarning())->toBeFalse();
});

test('a facebook server error tries the cover again', function () {
    Http::fake([
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'type' => 'OAuthException', 'code' => 2, 'is_transient' => true]], 500),
    ]);

    runSetFacebookCover($this->post)->assertReleased(SetFacebookCover::CHECK_EVERY_SECONDS);

    expect($this->post->fresh()->hasFacebookCoverWarning())->toBeFalse();
});

test('a cover that never went on records a warning when the job gives up', function () {
    (new SetFacebookCover($this->post))->failed(null);

    expect($this->post->fresh()->hasFacebookCoverWarning())->toBeTrue();
});

test('a cover that cannot be made a jpeg leaves a warning at once instead of trying again', function () {
    Http::fake([
        FACEBOOK_COVER_URL => Http::response('not an image', 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['success' => true]),
    ]);

    runSetFacebookCover($this->post)->assertNotReleased();

    Http::assertNotSent(fn (Request $request): bool => $request->url() === facebookThumbnailsUrl());
    expect($this->post->fresh()->hasFacebookCoverWarning())->toBeTrue();
});

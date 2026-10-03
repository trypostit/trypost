<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Support\PostStatusRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake(null, ['url' => 'https://cdn.example.com']);
    Queue::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
});

/**
 * Uploads a real image through the composer's chunked upload so the row
 * carries the dimensions the server measured.
 */
function uploadAspectTestImage(User $user, int $width, int $height): Media
{
    return uploadAspectTestBytes($user, UploadedFile::fake()->image('photo.jpg', $width, $height)->getContent());
}

function uploadAspectTestBytes(User $user, string $content): Media
{
    $size = strlen($content);

    test()->actingAs($user)->call('POST', route('app.media.store-chunked'), [], [], [], [
        'HTTP_CONTENT_RANGE' => 'bytes 0-'.($size - 1).'/'.$size,
        'HTTP_X_FILE_NAME' => 'photo.jpg',
        'HTTP_X_UPLOAD_ID' => Str::uuid()->toString(),
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $content)->assertSuccessful()->assertJson(['done' => true]);

    return Media::query()->latest('created_at')->latest('id')->firstOrFail();
}

/**
 * A workspace asset whose stored file is a real image of the given size.
 *
 * @param  array<string, mixed>  $meta
 */
function aspectTestAsset(Workspace $workspace, int $width, int $height, array $meta = []): Media
{
    $asset = Media::factory()->temporaryUpload($workspace)->create(['meta' => $meta]);
    Storage::put($asset->path, UploadedFile::fake()->image('photo.jpg', $width, $height)->getContent());

    return $asset;
}

/**
 * A JPEG stored `$rawWidth`×`$rawHeight` whose EXIF orientation 6 tells
 * viewers to rotate it a quarter turn, as phones save portrait photos.
 */
function sidewaysJpeg(int $rawWidth, int $rawHeight): string
{
    $image = imagecreatetruecolor($rawWidth, $rawHeight);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06\x00\x00\x00\x00\x00\x00";
    $payload = "Exif\x00\x00{$tiff}";

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
}

/**
 * @param  array<string, mixed>  $platformMeta
 */
function aspectTestPost(Workspace $workspace, User $user, SocialAccount $account, Media $asset, ContentType $contentType, array $platformMeta = []): array
{
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => Status::Draft,
        'scheduled_at' => null,
        'media' => [MediaItem::fromMedia($asset)->toArray()],
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => $contentType,
        'enabled' => true,
        'meta' => $platformMeta,
    ]);

    return [$post, $platform];
}

/**
 * @return list<string>
 */
function sessionErrorMessages(): array
{
    $errors = session('errors');

    return collect(is_array($errors) ? $errors : ($errors?->getBag('default')->toArray() ?? []))->flatten()->values()->all();
}

function tooWideForInstagramFeed(): string
{
    return trans('posts.form.warnings.aspect_ratio_too_wide', [
        'destination' => ContentType::InstagramFeed->destinationLabel(),
        'current' => '2.00',
        'max' => '1.91',
    ]);
}

test('the web schedule rejects an instagram feed image wider than 1.91 with a per-network message', function (string $status) {
    $upload = uploadAspectTestImage($this->user, 2000, 1000);
    [$post, $platform] = aspectTestPost($this->workspace, $this->user, $this->instagram, $upload, ContentType::InstagramFeed);

    $response = $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => $status,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [MediaItem::fromMedia($upload)->toArray()],
        'platforms' => [['id' => $platform->id, 'content_type' => ContentType::InstagramFeed->value]],
    ]);

    $errors = sessionErrorMessages();

    expect($errors)->toContain(tooWideForInstagramFeed())
        ->and(tooWideForInstagramFeed())->toContain('Instagram')
        ->and($post->fresh()->status)->toBe(Status::Draft);
})->with([Status::Scheduled->value, Status::Publishing->value]);

test('the web schedule accepts a 4:5 instagram feed image', function () {
    $upload = uploadAspectTestImage($this->user, 1080, 1350);
    [$post, $platform] = aspectTestPost($this->workspace, $this->user, $this->instagram, $upload, ContentType::InstagramFeed);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [MediaItem::fromMedia($upload)->toArray()],
        'platforms' => [['id' => $platform->id, 'content_type' => ContentType::InstagramFeed->value]],
    ])->assertSessionHasNoErrors();

    expect($post->fresh()->status)->toBe(Status::Scheduled);
});

test('client-sent dimensions never override the stored row', function () {
    $upload = uploadAspectTestImage($this->user, 2000, 1000);
    [$post, $platform] = aspectTestPost($this->workspace, $this->user, $this->instagram, $upload, ContentType::InstagramFeed);
    $item = MediaItem::fromMedia($upload)->toArray();
    $item['meta'] = [...($item['meta'] ?? []), 'width' => 1080, 'height' => 1350];

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [$item],
        'platforms' => [['id' => $platform->id, 'content_type' => ContentType::InstagramFeed->value]],
    ]);

    expect(sessionErrorMessages())->toContain(tooWideForInstagramFeed())
        ->and($post->fresh()->status)->toBe(Status::Draft);
});

test('an instagram story fits any image into the frame', function () {
    $upload = uploadAspectTestImage($this->user, 2000, 1000);
    [$post, $platform] = aspectTestPost($this->workspace, $this->user, $this->instagram, $upload, ContentType::InstagramStory);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [MediaItem::fromMedia($upload)->toArray()],
        'platforms' => [['id' => $platform->id, 'content_type' => ContentType::InstagramStory->value]],
    ])->assertSessionHasNoErrors();

    expect($post->fresh()->status)->toBe(Status::Scheduled);
});

test('the rest api rejects a too-wide instagram feed image on create and update', function () {
    $headers = ['Authorization' => 'Bearer '.createApiTestToken(['workspace' => $this->workspace])['plain_token']];
    $wide = aspectTestAsset($this->workspace, 2000, 1000, ['width' => 2000, 'height' => 1000]);

    $this->withHeaders($headers)->postJson(route('api.posts.store'), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [['id' => $wide->id]],
        'platforms' => [['social_account_id' => $this->instagram->id, 'content_type' => ContentType::InstagramFeed->value]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['destinations.0.content_type' => tooWideForInstagramFeed()]);

    [$post] = aspectTestPost($this->workspace, $this->user, $this->instagram, $wide, ContentType::InstagramFeed);

    $this->withHeaders($headers)->putJson(route('api.posts.update', $post), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertUnprocessable()->assertJsonFragment([tooWideForInstagramFeed()]);

    expect($post->fresh()->status)->toBe(Status::Draft);
});

test('the rest api accepts a 4:5 instagram feed image', function () {
    $headers = ['Authorization' => 'Bearer '.createApiTestToken(['workspace' => $this->workspace])['plain_token']];
    $portrait = aspectTestAsset($this->workspace, 1080, 1350, ['width' => 1080, 'height' => 1350]);

    $this->withHeaders($headers)->postJson(route('api.posts.store'), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [['id' => $portrait->id]],
        'platforms' => [['social_account_id' => $this->instagram->id, 'content_type' => ContentType::InstagramFeed->value]],
    ])->assertCreated();
});

test('mcp scheduling and publishing reject the stored too-wide image with the same message', function () {
    $wide = aspectTestAsset($this->workspace, 2000, 1000, ['width' => 2000, 'height' => 1000]);
    [$post] = aspectTestPost($this->workspace, $this->user, $this->instagram, $wide, ContentType::InstagramFeed);

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertHasErrors([tooWideForInstagramFeed()]);

    TryPostServer::actingAs($this->user)->tool(PublishPostTool::class, ['post_id' => $post->id])
        ->assertHasErrors([tooWideForInstagramFeed()]);

    expect($post->fresh()->status)->toBe(Status::Draft);
});

test('the stored-post publish guard rejects the too-wide image', function () {
    $wide = aspectTestAsset($this->workspace, 2000, 1000, ['width' => 2000, 'height' => 1000]);
    [$post] = aspectTestPost($this->workspace, $this->user, $this->instagram, $wide, ContentType::InstagramFeed);

    expect(fn () => PostStatusRules::assertStoredPostPublishable($post))
        ->toThrow(ValidationException::class, tooWideForInstagramFeed());
});

test('a row without dimensions is measured from its file and written back', function () {
    $wide = aspectTestAsset($this->workspace, 2000, 1000);
    [$post] = aspectTestPost($this->workspace, $this->user, $this->instagram, $wide, ContentType::InstagramFeed);

    expect(fn () => PostStatusRules::assertStoredPostPublishable($post))
        ->toThrow(ValidationException::class, tooWideForInstagramFeed());

    expect($wide->fresh()->meta)->toMatchArray(['width' => 2000, 'height' => 1000]);
});

test('an unreadable row skips the dimension check', function () {
    $broken = Media::factory()->temporaryUpload($this->workspace)->create(['meta' => []]);
    [$post] = aspectTestPost($this->workspace, $this->user, $this->instagram, $broken, ContentType::InstagramFeed);

    PostStatusRules::assertStoredPostPublishable($post);

    expect($broken->fresh()->meta)->toBe([]);
});

test('google business rejects an image under 250 px on its short edge', function () {
    $account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $small = aspectTestAsset($this->workspace, 200, 200, ['width' => 200, 'height' => 200]);
    [$post] = aspectTestPost($this->workspace, $this->user, $account, $small, ContentType::GoogleBusinessPost);

    $message = trans('posts.form.warnings.image_too_small_dimensions', [
        'destination' => ContentType::GoogleBusinessPost->destinationLabel(),
        'current' => '200×200',
        'min' => '250×250',
    ]);

    expect(fn () => PostStatusRules::assertStoredPostPublishable($post))
        ->toThrow(ValidationException::class, $message);
});

test('the destination names the network and the post type', function () {
    expect(ContentType::InstagramFeed->destinationLabel())->toBe('Instagram · Feed Post')
        ->and(ContentType::GoogleBusinessPost->destinationLabel())->toBe('Google Business Profile · Post');
});

test('an instagram feed image the publisher crops to a set aspect ratio passes on web, api and mcp', function () {
    $upload = uploadAspectTestImage($this->user, 2000, 1000);
    [$post, $platform] = aspectTestPost($this->workspace, $this->user, $this->instagram, $upload, ContentType::InstagramFeed, ['aspect_ratio' => '1:1']);

    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => Status::Draft->value,
        'media' => [MediaItem::fromMedia($upload)->toArray()],
        'platforms' => [['id' => $platform->id, 'content_type' => ContentType::InstagramFeed->value, 'meta' => ['aspect_ratio' => '1:1']]],
    ]);
    $this->actingAs($this->user)->put(route('app.posts.update', $post), [
        'status' => Status::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [MediaItem::fromMedia($upload)->toArray()],
        'platforms' => [['id' => $platform->id, 'content_type' => ContentType::InstagramFeed->value, 'meta' => ['aspect_ratio' => '1:1']]],
    ]);

    expect(sessionErrorMessages())->not->toContain(tooWideForInstagramFeed());

    $headers = ['Authorization' => 'Bearer '.createApiTestToken(['workspace' => $this->workspace])['plain_token']];
    $wide = aspectTestAsset($this->workspace, 2000, 1000, ['width' => 2000, 'height' => 1000]);

    $this->withHeaders($headers)->postJson(route('api.posts.store'), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'media' => [['id' => $wide->id]],
        'platforms' => [[
            'social_account_id' => $this->instagram->id,
            'content_type' => ContentType::InstagramFeed->value,
            'meta' => ['aspect_ratio' => '1:1'],
        ]],
    ])->assertCreated();

    [$stored] = aspectTestPost($this->workspace, $this->user, $this->instagram, $wide, ContentType::InstagramFeed, ['aspect_ratio' => '4:5']);

    $this->withHeaders($headers)->putJson(route('api.posts.update', $stored), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertOk();

    [$mcpPost] = aspectTestPost($this->workspace, $this->user, $this->instagram, $wide, ContentType::InstagramFeed, ['aspect_ratio' => '1:1']);

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $mcpPost->id,
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ])->assertOk();

    expect($mcpPost->fresh()->status)->toBe(Status::Scheduled);
});

test('an instagram feed image set to its original aspect ratio is still checked', function () {
    $wide = aspectTestAsset($this->workspace, 2000, 1000, ['width' => 2000, 'height' => 1000]);
    [$post] = aspectTestPost($this->workspace, $this->user, $this->instagram, $wide, ContentType::InstagramFeed, ['aspect_ratio' => 'original']);

    expect(fn () => PostStatusRules::assertStoredPostPublishable($post))
        ->toThrow(ValidationException::class, tooWideForInstagramFeed());

    $headers = ['Authorization' => 'Bearer '.createApiTestToken(['workspace' => $this->workspace])['plain_token']];

    $this->withHeaders($headers)->putJson(route('api.posts.update', $post), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'meta' => ['aspect_ratio' => 'original'],
    ])->assertUnprocessable()->assertJsonFragment([tooWideForInstagramFeed()]);
});

test('a sideways phone photo is measured as people see it', function () {
    $tall = uploadAspectTestBytes($this->user, sidewaysJpeg(1350, 1080));
    $wide = uploadAspectTestBytes($this->user, sidewaysJpeg(1000, 2000));

    expect($tall->meta)->toMatchArray(['width' => 1080, 'height' => 1350])
        ->and($wide->meta)->toMatchArray(['width' => 2000, 'height' => 1000]);

    [$accepted] = aspectTestPost($this->workspace, $this->user, $this->instagram, $tall, ContentType::InstagramFeed);
    PostStatusRules::assertStoredPostPublishable($accepted);

    [$rejected] = aspectTestPost($this->workspace, $this->user, $this->instagram, $wide, ContentType::InstagramFeed);
    expect(fn () => PostStatusRules::assertStoredPostPublishable($rejected))
        ->toThrow(ValidationException::class, tooWideForInstagramFeed());
});

test('a row measured on demand applies the exif orientation', function () {
    $asset = Media::factory()->temporaryUpload($this->workspace)->create(['meta' => []]);
    Storage::put($asset->path, sidewaysJpeg(1000, 2000));
    [$post] = aspectTestPost($this->workspace, $this->user, $this->instagram, $asset, ContentType::InstagramFeed);

    expect(fn () => PostStatusRules::assertStoredPostPublishable($post))
        ->toThrow(ValidationException::class, tooWideForInstagramFeed());

    expect($asset->fresh()->meta)->toMatchArray(['width' => 2000, 'height' => 1000]);
});

test('the media kind comes from the stored row, not the request', function () {
    $wide = aspectTestAsset($this->workspace, 2000, 1000, ['width' => 2000, 'height' => 1000]);
    $item = [...MediaItem::fromMedia($wide)->toArray(), 'type' => null, 'mime_type' => 'application/x-unknown', 'original_filename' => 'blob', 'path' => 'blob'];

    expect(ContentTypeCompatibleWithMedia::errorsFor(
        [['key' => 'media', 'content_type' => ContentType::InstagramFeed->value]],
        [$item],
        $this->workspace,
    ))->toBe(['media' => tooWideForInstagramFeed()]);
});

test('telegram limits the ratio of photos only', function () {
    $account = SocialAccount::factory()->telegram()->create(['workspace_id' => $this->workspace->id]);
    $strip = aspectTestAsset($this->workspace, 3000, 100, ['width' => 3000, 'height' => 100]);
    $video = Media::factory()->video()->temporaryUpload($this->workspace)->create(['meta' => ['width' => 3000, 'height' => 100, 'duration' => 5]]);

    [$photoPost] = aspectTestPost($this->workspace, $this->user, $account, $strip, ContentType::TelegramPost);
    [$videoPost] = aspectTestPost($this->workspace, $this->user, $account, $video, ContentType::TelegramPost);

    expect(fn () => PostStatusRules::assertStoredPostPublishable($photoPost))->toThrow(ValidationException::class);

    PostStatusRules::assertStoredPostPublishable($videoPost);
});

test('an upload stores the dimensions measured from its bytes', function () {
    expect(uploadAspectTestImage($this->user, 2000, 1000)->meta)
        ->toMatchArray(['width' => 2000, 'height' => 1000]);
});

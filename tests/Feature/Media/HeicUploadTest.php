<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\HeicConverter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Storage::fake();
    Cache::flush();
    HeicConverter::flush();

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->account);
});

afterEach(fn () => HeicConverter::flush());

function heicChunkedUpload(User $user, string $content, string $fileName): TestResponse
{
    $size = strlen($content);

    return test()->actingAs($user)->call(
        'POST',
        route('app.media.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => 'bytes 0-'.($size - 1)."/{$size}",
            'HTTP_X_FILE_NAME' => $fileName,
            'HTTP_X_UPLOAD_ID' => Str::uuid()->toString(),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        $content,
    );
}

function heicSample(): string
{
    return file_get_contents(base_path('tests/fixtures/sample.heic'));
}

test('a chunked heic upload is stored as an upright jpeg', function () {
    if (! HeicConverter::available()) {
        $this->markTestSkipped('Imagick with HEIC support is not available');
    }

    heicChunkedUpload($this->user, heicSample(), 'IMG_0001.HEIC')
        ->assertOk()
        ->assertJson(['done' => true, 'type' => 'image', 'mime_type' => 'image/jpeg']);

    $media = Media::query()->sole();

    expect($media->mime_type)->toBe('image/jpeg')
        ->and($media->path)->toEndWith('.jpg')
        ->and(Storage::exists($media->path))->toBeTrue()
        ->and(getimagesizefromstring(Storage::get($media->path))[2])->toBe(IMAGETYPE_JPEG)
        ->and(data_get($media->meta, 'width'))->toBe(40)
        ->and(data_get($media->meta, 'height'))->toBe(80);
});

test('a heic upload is refused with a clear message when conversion is off', function () {
    config(['trypost.media.heic_conversion' => false]);
    HeicConverter::flush();

    heicChunkedUpload($this->user, heicSample(), 'photo.heic')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file_name'])
        ->assertJsonPath('errors.file_name.0', __('posts.composer.upload_errors.heic_unavailable'));

    expect(Media::query()->count())->toBe(0);
});

test('the api media and signed upload routes refuse heic with the same message when conversion is off', function () {
    config(['trypost.media.heic_conversion' => false]);
    HeicConverter::flush();

    $message = __('posts.composer.upload_errors.heic_unavailable');
    $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('photo.heic', heicSample());

    $api = createApiTestToken();
    $post = Post::factory()->create(['workspace_id' => $api['workspace']->id, 'user_id' => $api['user']->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$api['plain_token'], 'Accept' => 'application/json'])
        ->post(route('api.posts.store-media', $post), ['media' => $file()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.media.0', $message);

    $signed = URL::temporarySignedRoute(
        'api.uploads.store',
        now()->addMinutes(15),
        ['token' => (string) Str::uuid(), 'workspace_id' => $api['workspace']->id],
    );

    $this->postJson($signed, ['media' => $file()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.media.0', $message);

    expect(Media::query()->count())->toBe(0);
});

test('the shared upload limits tell the frontend whether heic is accepted', function (bool $enabled) {
    config(['trypost.media.heic_conversion' => $enabled]);
    HeicConverter::flush();

    $expected = $enabled && HeicConverter::available();

    $this->actingAs($this->user)
        ->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page->where('mediaUploadLimits.heic', $expected));
})->with([true, false]);

function heicCorruptBytes(): string
{
    return "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00heicmif1".str_repeat("\x07", 200);
}

test('the converted upload is named .jpg', function () {
    if (! HeicConverter::available()) {
        $this->markTestSkipped('Imagick with HEIC support is not available');
    }

    heicChunkedUpload($this->user, heicSample(), 'IMG_0001.HEIC')->assertOk()->assertJsonPath('original_filename', 'img_0001.jpg');
});

test('a corrupt heic is refused as unreadable, not as unavailable', function () {
    if (! HeicConverter::available()) {
        $this->markTestSkipped('Imagick with HEIC support is not available');
    }

    heicChunkedUpload($this->user, heicCorruptBytes(), 'broken.heic')
        ->assertUnprocessable()
        ->assertJsonPath('errors.media.0', __('posts.composer.upload_errors.heic_invalid'));

    expect(Media::query()->count())->toBe(0);
});

test('a heic sequence is refused as unreadable on every path that sniffs it', function () {
    $api = createApiTestToken();
    $post = Post::factory()->create(['workspace_id' => $api['workspace']->id, 'user_id' => $api['user']->id]);
    $file = UploadedFile::fake()->createWithContent('burst.heic', heicSample());
    $file->mimeTypeToReport = 'image/heic-sequence';

    $this->withHeaders(['Authorization' => 'Bearer '.$api['plain_token'], 'Accept' => 'application/json'])
        ->post(route('api.posts.store-media', $post), ['media' => $file])
        ->assertUnprocessable()
        ->assertJsonPath('errors.media.0', __('posts.composer.upload_errors.heic_invalid'));

    expect(fn () => $this->workspace->addMediaFromPath(base_path('tests/fixtures/sample.heic'), 'burst.heic', mimeType: 'image/heif-sequence'))
        ->toThrow(ValidationException::class);
    expect(Media::query()->count())->toBe(0);
});

test('the decode runs under the configured imagick limits and puts the previous ones back', function () {
    if (! HeicConverter::available()) {
        $this->markTestSkipped('Imagick with HEIC support is not available');
    }

    $before = [Imagick::getResourceLimit(Imagick::RESOURCETYPE_WIDTH), Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY)];
    config(['trypost.media.heic_limits.width_px' => 20]);

    expect(fn () => $this->workspace->addMediaFromPath(base_path('tests/fixtures/sample.heic'), 'wide.heic', mimeType: 'image/heic'))
        ->toThrow(ValidationException::class);

    config(['trypost.media.heic_limits.width_px' => 16384]);
    HeicConverter::toJpeg(base_path('tests/fixtures/sample.heic'));

    $after = new Imagick;
    $after->readImage(base_path('tests/fixtures/sample.heic'));

    expect([Imagick::getResourceLimit(Imagick::RESOURCETYPE_WIDTH), Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY)])->toEqual($before)
        ->and($after->getImageWidth())->toBe(40);
});

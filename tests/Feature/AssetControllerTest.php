<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Services\UnsplashService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);
});

test('assets index shows assets page', function () {
    $response = $this->actingAs($this->user)->get(route('app.assets.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('assets/Index', false));
});

test('assets index requires authentication', function () {
    $response = $this->get(route('app.assets.index'));

    $response->assertRedirect(route('login'));
});

test('assets search returns paginated json filtered by name', function () {
    $matching = $this->workspace->addMedia(UploadedFile::fake()->image('vacation-beach.jpg'), 'assets');
    $this->workspace->addMedia(UploadedFile::fake()->image('office-shot.jpg'), 'assets');

    $response = $this->actingAs($this->user)
        ->getJson(route('app.assets.search', ['search' => 'vacation']));

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $matching->id);
});

test('assets search filters by type', function () {
    $this->workspace->addMedia(UploadedFile::fake()->image('photo.jpg'), 'assets');
    $this->workspace->addMedia(UploadedFile::fake()->create('clip.mp4', 100, 'video/mp4'), 'assets');

    $response = $this->actingAs($this->user)
        ->getJson(route('app.assets.search', ['type' => 'video']));

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.type', 'video');
});

test('assets search only returns the current workspace assets', function () {
    $this->workspace->addMedia(UploadedFile::fake()->image('mine.jpg'), 'assets');

    $otherAccount = Account::factory()->create();
    $otherUser = User::factory()->create(['account_id' => $otherAccount->id]);
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $otherAccount->id,
        'user_id' => $otherUser->id,
    ]);
    $otherWorkspace->addMedia(UploadedFile::fake()->image('theirs.jpg'), 'assets');

    $response = $this->actingAs($this->user)
        ->getJson(route('app.assets.search'));

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
});

test('can upload an image asset', function () {
    $file = UploadedFile::fake()->image('photo.jpg', 800, 600);

    $response = $this->actingAs($this->user)
        ->postJson(route('app.assets.store'), ['media' => $file]);

    $response->assertCreated();
    $response->assertJsonStructure(['id', 'url', 'type', 'original_filename', 'size']);

    expect($this->workspace->getMedia('assets')->count())->toBe(1);

    $media = $this->workspace->getMedia('assets')->first();
    expect($media->original_filename)->toBe('photo.jpg');
    expect($media->collection)->toBe('assets');
});

test('can delete an asset', function () {
    $file = UploadedFile::fake()->image('photo.jpg');
    $media = $this->workspace->addMedia($file, 'assets');

    $response = $this->actingAs($this->user)
        ->delete(route('app.assets.destroy', $media));

    $response->assertRedirect();
    expect(Media::find($media->id))->toBeNull();
});

test('cannot delete asset from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);

    $file = UploadedFile::fake()->image('photo.jpg');
    $media = $otherWorkspace->addMedia($file, 'assets');

    $response = $this->actingAs($this->user)
        ->delete(route('app.assets.destroy', $media));

    $response->assertForbidden();
});

test('can store asset from url', function () {
    $fakeImage = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $imageContent = file_get_contents($fakeImage->getPathname());

    Http::fake([
        'images.unsplash.com/*' => Http::response(
            $imageContent,
            200,
            ['Content-Type' => 'image/jpeg']
        ),
    ]);

    $unsplash = $this->mock(UnsplashService::class);
    $unsplash->shouldReceive('trackDownload')->once();

    $response = $this->actingAs($this->user)
        ->postJson(route('app.assets.store-from-url'), [
            'url' => 'https://images.unsplash.com/photo-test',
            'filename' => 'unsplash-test.jpg',
            'download_location' => 'https://api.unsplash.com/photos/test/download',
        ]);

    $response->assertCreated();
    $response->assertJsonStructure(['id', 'path', 'url', 'type', 'mime_type', 'original_filename', 'size']);

    expect($this->workspace->getMedia('assets')->count())->toBe(1);

    $media = $this->workspace->getMedia('assets')->first();
    $response->assertJsonPath('id', $media->id);
    $response->assertJsonPath('type', 'image');
});

test('rejects a raw private-network url before it reaches the controller', function () {
    // StoreAssetFromUrlRequest already allow-lists the host to
    // images.unsplash.com / media*.giphy.com, so a bare private-IP url like
    // 127.0.0.1 never reaches the controller — it 422s at the FormRequest
    // layer. The SSRF guard below is defense-in-depth against a redirect
    // (or DNS rebind) from one of the allow-listed hosts to an internal one.
    $response = $this->actingAs($this->user)
        ->postJson(route('app.assets.store-from-url'), [
            'url' => 'http://127.0.0.1/evil.jpg',
            'filename' => 'evil.jpg',
        ]);

    $response->assertUnprocessable();
});

test('blocks store asset from url when an allow-listed host redirects to a private ip', function () {
    Http::fake([
        'images.unsplash.com/*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
        'http://127.0.0.1/*' => Http::response('internal secret', 200),
    ]);

    $unsplash = $this->mock(UnsplashService::class);
    $unsplash->shouldReceive('trackDownload')->once();

    $response = $this->actingAs($this->user)
        ->postJson(route('app.assets.store-from-url'), [
            'url' => 'https://images.unsplash.com/photo-test',
            'filename' => 'unsplash-test.jpg',
            'download_location' => 'https://api.unsplash.com/photos/test/download',
        ]);

    $response->assertStatus(400);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '127.0.0.1'));
    expect(Media::count())->toBe(0);
});

test('chunked upload completes with single chunk', function () {
    // Use real PNG bytes so mime_content_type detects image/png. The MIME
    // is sniffed from content magic bytes, not the X-File-Name header.
    $content = file_get_contents(__DIR__.'/../fixtures/1x1.png');
    $size = strlen($content);

    $response = $this->actingAs($this->user)->call(
        'POST',
        route('app.assets.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => 'bytes 0-'.($size - 1).'/'.$size,
            'HTTP_X_FILE_NAME' => 'test.png',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        $content,
    );

    $response->assertSuccessful();
    $response->assertJson(['done' => true]);
    $response->assertJsonStructure(['done', 'id', 'path', 'url', 'type', 'mime_type', 'original_filename', 'size']);
    expect($this->workspace->getMedia('assets')->count())->toBe(1);
});

test('chunked upload completes for a pdf document', function () {
    // Real %PDF magic bytes so mime_content_type detects application/pdf.
    $content = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
    $size = strlen($content);

    $response = $this->actingAs($this->user)->call(
        'POST',
        route('app.assets.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => 'bytes 0-'.($size - 1).'/'.$size,
            'HTTP_X_FILE_NAME' => 'deck.pdf',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        $content,
    );

    $response->assertSuccessful();
    $response->assertJson(['done' => true]);
    expect($this->workspace->getMedia('assets')->first()->type->value)->toBe('document');
});

test('chunked upload reports progress on intermediate chunks', function () {
    $response = $this->actingAs($this->user)->call(
        'POST',
        route('app.assets.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => 'bytes 0-499/1000',
            'HTTP_X_FILE_NAME' => 'test-video.mp4',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        str_repeat('a', 500),
    );

    $response->assertSuccessful();
    $response->assertJson(['done' => false, 'progress' => 50]);
    expect(Media::count())->toBe(0);
});

test('chunked upload rejects unsupported file extension', function () {
    $response = $this->actingAs($this->user)->call(
        'POST',
        route('app.assets.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => 'bytes 0-99/100',
            'HTTP_X_FILE_NAME' => 'malware.exe',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        str_repeat('x', 100),
    );

    $response->assertUnprocessable();
});

test('chunked upload rejects invalid Content-Range header', function () {
    $response = $this->actingAs($this->user)->call(
        'POST',
        route('app.assets.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => 'invalid',
            'HTTP_X_FILE_NAME' => 'test.jpg',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        'data',
    );

    // FormRequest validation surfaces parse failures as 422
    // (range_start / range_end / total_size all required).
    $response->assertUnprocessable();
});

test('chunked upload rejects unauthenticated', function () {
    $response = $this->call(
        'POST',
        route('app.assets.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => 'bytes 0-99/100',
            'HTTP_X_FILE_NAME' => 'test.jpg',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        str_repeat('x', 100),
    );

    $response->assertUnauthorized();
});

test('unsplash search returns results', function () {
    $this->mock(UnsplashService::class)
        ->shouldReceive('search')
        ->with('nature', 1)
        ->once()
        ->andReturn([
            'results' => [
                ['id' => 'abc', 'url_small' => 'https://example.com/small.jpg'],
            ],
            'total' => 1,
            'total_pages' => 1,
        ]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.assets.unsplash.search', ['query' => 'nature']));

    $response->assertOk();
    $response->assertJsonPath('total', 1);
});

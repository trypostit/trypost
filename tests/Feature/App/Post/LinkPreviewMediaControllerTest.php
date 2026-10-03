<?php

declare(strict_types=1);

use App\Enums\Media\Type as MediaType;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\LinkCard\LinkCardFetcher;
use App\Services\Social\LinkCard\LinkCardMetadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    fakePublicDns();

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

function linkPreviewMediaCard(?string $image): void
{
    test()->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->with('https://example.com/article')
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: 'The Article',
            description: 'A great read',
            imageUrl: $image,
        ));
}

test('the card image is imported as a temporary upload', function () {
    linkPreviewMediaCard('https://93.184.216.34/card.jpg');
    $fake = UploadedFile::fake()->image('card.jpg', 1200, 630);
    $image = file_get_contents($fake->getPathname());
    Http::fake(['https://93.184.216.34/card.jpg' => Http::response($image, 200, ['Content-Type' => 'image/jpeg'])]);

    $response = $this->actingAs($this->user)
        ->postJson(route('app.posts.link-preview-media'), ['url' => 'https://example.com/article'])
        ->assertCreated()
        ->assertJsonPath('type', MediaType::Image->value);

    $media = Media::query()->sole();

    expect($response->json('upload_token'))->toBe($media->upload_token)
        ->and($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->workspace_id)->toBe($this->workspace->id)
        ->and($media->post_id)->toBeNull()
        ->and(Storage::get($media->path))->toBe($image);
});

test('a card without an image is rejected', function () {
    linkPreviewMediaCard(null);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.link-preview-media'), ['url' => 'https://example.com/article'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['url' => __('posts.composer.link_preview.no_image')]);

    expect(Media::query()->count())->toBe(0);
});

test('an image the importer refuses is rejected', function () {
    linkPreviewMediaCard('https://93.184.216.34/card.jpg');
    Http::fake(['https://93.184.216.34/card.jpg' => Http::response('not an image', 200, ['Content-Type' => 'text/html'])]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.link-preview-media'), ['url' => 'https://example.com/article'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['url' => __('posts.composer.media_sources.errors.import_failed')]);

    expect(Media::query()->count())->toBe(0);
});

test('the url is required and guests are refused', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.posts.link-preview-media'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    auth()->logout();

    $this->postJson(route('app.posts.link-preview-media'), ['url' => 'https://example.com/article'])
        ->assertUnauthorized();
});

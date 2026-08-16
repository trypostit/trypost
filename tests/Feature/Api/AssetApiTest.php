<?php

declare(strict_types=1);

use App\Enums\Media\Type as MediaType;
use App\Enums\UserWorkspace\Role;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];

    Storage::fake();
});

test('lists current workspace asset library media', function () {
    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'original_filename' => 'hero.jpg',
    ]);

    Media::factory()->logo()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    $other = Workspace::factory()->create();
    Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $other->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.assets.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $asset->id)
        ->assertJsonPath('data.0.original_filename', 'hero.jpg')
        ->assertJsonPath('data.0.type', MediaType::Image->value)
        ->assertJsonPath('data.0.url', $asset->url)
        ->assertJsonMissingPath('data.0.path');
});

test('filters assets by filename search and type', function () {
    Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'original_filename' => 'campaign-hero.jpg',
    ]);
    Media::factory()->assets()->video()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'original_filename' => 'campaign-reel.mp4',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.assets.index', ['search' => 'hero', 'type' => 'image']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.original_filename', 'campaign-hero.jpg');
});

test('paginates assets with the application page size', function () {
    $perPage = (int) config('app.pagination.default');

    Media::factory()->assets()->count($perPage + 1)->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.assets.index'))
        ->assertOk()
        ->assertJsonCount($perPage, 'data')
        ->assertJsonPath('meta.per_page', $perPage)
        ->assertJsonPath('meta.total', $perPage + 1)
        ->assertJsonPath('meta.current_page', 1);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.assets.index', ['page' => 2]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2);
});

test('filters assets by document type', function () {
    Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);
    $document = Media::factory()->assets()->document()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'original_filename' => 'brief.pdf',
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.assets.index', ['type' => 'document']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $document->id)
        ->assertJsonPath('data.0.type', MediaType::Document->value);
});

test('rejects unknown type filters', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.assets.index', ['type' => 'audio']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);
});

test('shows an asset', function () {
    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.assets.show', $asset))
        ->assertOk()
        ->assertJsonPath('id', $asset->id)
        ->assertJsonPath('url', $asset->url)
        ->assertJsonMissingPath('path');
});

test('does not show a logo or avatar as an asset', function () {
    $logo = Media::factory()->logo()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);
    $avatar = Media::factory()->avatar()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.assets.show', $logo))
        ->assertNotFound();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.assets.show', $avatar))
        ->assertNotFound();
});

test('does not reveal another workspace asset', function () {
    $other = Workspace::factory()->create();
    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $other->id,
    ]);
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.assets.show', $asset))
        ->assertNotFound();
});

test('viewers cannot list or show assets', function () {
    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);

    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    $token = passportToken($viewer, $this->workspace);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson(route('api.assets.index'))
        ->assertForbidden();

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->getJson(route('api.assets.show', $asset))
        ->assertForbidden();
});

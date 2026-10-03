<?php

declare(strict_types=1);

use App\Jobs\Media\ImportRemoteMedia;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MediaImportStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();
    Queue::fake();

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

    config()->set([
        'services.unsplash.access_key' => 'unsplash-key',
        'services.google_media.client_id' => 'google-client-id',
        'services.google_media.client_secret' => 'media-client-secret',
        'services.google_media.redirect' => 'https://trypost.example/integrations/google/callback',
        'services.google_media.api_key' => 'google-api-key',
        'services.google_media.app_id' => 'google-app-id',
        'services.canva.client_id' => 'canva-id',
        'services.canva.client_secret' => 'canva-secret',
        'trypost.media_sources.google_drive.enabled' => true,
        'trypost.media_sources.google_photos.enabled' => true,
        'trypost.media_sources.canva.enabled' => true,
    ]);
});

test('a source that cannot be imported is rejected', function (string $source) {
    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), ['source' => $source])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');

    Queue::assertNotPushed(ImportRemoteMedia::class);
})->with(['canva', 'unsplash', 'giphy', 'ai', 'dropbox']);

test('a disabled source is rejected', function () {
    config()->set('trypost.media_sources.google_drive.enabled', false);

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), ['source' => 'google_drive'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');
});

test('a files array is rejected', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), [
            'source' => 'google_drive',
            'files' => [['id' => 'a'], ['id' => 'b']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('files');
});

test('a user outside the workspace cannot start an import', function () {
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider->fresh())
        ->postJson(route('app.media.imports.store'), ['source' => 'google_drive'])
        ->assertForbidden();
});

test('starting imports is throttled per user', function () {
    config()->set('trypost.media_sources.imports_per_user_per_minute', 2);

    foreach (range(1, 2) as $attempt) {
        $this->actingAs($this->user)
            ->postJson(route('app.media.imports.store'), ['source' => 'canva'])
            ->assertUnprocessable();
    }

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), ['source' => 'canva'])
        ->assertTooManyRequests();
});

test('the status of a pending import is returned to its owner', function () {
    $importId = (string) Str::uuid();
    MediaImportStatus::pending($importId, $this->user->id, $this->workspace->id);

    $this->actingAs($this->user)
        ->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertExactJson(['status' => 'pending', 'media' => null, 'reason' => null, 'replaces' => null]);
});

test('a finished import returns its temporary upload', function () {
    $importId = (string) Str::uuid();
    $media = Media::factory()->temporaryUpload($this->workspace)->create();
    MediaImportStatus::pending($importId, $this->user->id, $this->workspace->id);
    MediaImportStatus::complete($importId, $media);

    $this->actingAs($this->user)
        ->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('media.id', $media->id)
        ->assertJsonPath('media.upload_token', $media->upload_token)
        ->assertJsonPath('reason', null);
});

test('a failed import returns its reason', function () {
    $importId = (string) Str::uuid();
    MediaImportStatus::pending($importId, $this->user->id, $this->workspace->id);
    MediaImportStatus::fail($importId, 'host_not_allowed');

    $this->actingAs($this->user)
        ->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('media', null)
        ->assertJsonPath('reason', 'host_not_allowed');
});

test('the status is hidden from another user, another workspace and for an unknown id', function () {
    $foreignUserImport = (string) Str::uuid();
    MediaImportStatus::pending($foreignUserImport, User::factory()->create()->id, $this->workspace->id);

    $foreignWorkspaceImport = (string) Str::uuid();
    MediaImportStatus::pending($foreignWorkspaceImport, $this->user->id, Workspace::factory()->create()->id);

    foreach ([$foreignUserImport, $foreignWorkspaceImport, (string) Str::uuid()] as $importId) {
        $this->actingAs($this->user)
            ->getJson(route('app.media.imports.show', $importId))
            ->assertNotFound();
    }
});

<?php

declare(strict_types=1);

use App\Actions\Media\StartMediaImport;
use App\Dto\ImportedFile;
use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use App\Enums\Media\Type as MediaType;
use App\Events\MediaImportSettled;
use App\Jobs\Media\ImportRemoteMedia;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;
use App\Support\MediaImportStatus;
use Carbon\CarbonInterface;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

function driveFile(string $url = 'https://93.184.216.34/files/abc?alt=media'): RemoteFile
{
    return new RemoteFile(
        $url,
        'photo.png',
        ['Authorization' => 'Bearer drive-token'],
        ['93.184.216.34'],
        Source::GoogleDrive,
        ['file_id' => 'abc'],
    );
}

test('starting an import queues an encrypted job and records it as pending', function () {
    Queue::fake();
    config()->set('trypost.media_sources.import_timeout_seconds', 321);

    [$importId] = StartMediaImport::execute($this->user, $this->workspace, driveFile(), MediaType::cases());

    Queue::assertPushed(ImportRemoteMedia::class, fn (ImportRemoteMedia $job): bool => $job instanceof ShouldBeEncrypted
        && $job->importId === $importId
        && $job->workspaceId === $this->workspace->id
        && $job->userId === $this->user->id
        && $job->tries === 1
        && $job->timeout === 321);

    expect(MediaImportStatus::find($importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => 'pending', 'media_id' => null, 'reason' => null]);
});

test('a successful job ends done with one temporary upload the status endpoint returns', function () {
    Queue::fake();
    Http::fake(['https://93.184.216.34/*' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')))]);

    [$importId] = StartMediaImport::execute($this->user, $this->workspace, driveFile(), MediaType::cases());
    (new ImportRemoteMedia($importId, $this->workspace->id, $this->user->id, driveFile(), MediaType::cases()))->handle(app(RemoteMediaImporter::class));

    $media = Media::query()->sole();

    expect($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->upload_token)->not->toBeNull()
        ->and(data_get($media->meta, 'source'))->toBe('google_drive');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer drive-token'));

    $this->actingAs($this->user)
        ->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('media.id', $media->id)
        ->assertJsonPath('media.upload_token', $media->upload_token);
});

test('settling an import tells the workspace channel which import it was, and nothing else', function (int $downloadStatus) {
    Queue::fake();
    Event::fake([MediaImportSettled::class]);
    Http::fake(['https://93.184.216.34/*' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), $downloadStatus)]);

    [$importId] = StartMediaImport::execute($this->user, $this->workspace, driveFile(), MediaType::cases());

    Event::assertNotDispatched(MediaImportSettled::class);

    (new ImportRemoteMedia($importId, $this->workspace->id, $this->user->id, driveFile(), MediaType::cases()))->handle(app(RemoteMediaImporter::class));

    Event::assertDispatchedTimes(MediaImportSettled::class, 1);
    Event::assertDispatched(MediaImportSettled::class, function (MediaImportSettled $event) use ($importId): bool {
        $channels = collect($event->broadcastOn())->map(fn (PrivateChannel $channel): string => $channel->name)->all();

        return $event->importId === $importId
            && $channels === ["private-workspace.{$this->workspace->id}"]
            && $event->broadcastWith() === ['import_id' => $importId];
    });
})->with([
    'imported' => [200],
    'failed' => [404],
]);

test('a job that dies tells the workspace channel too', function () {
    Queue::fake();
    Event::fake([MediaImportSettled::class]);

    [$importId] = StartMediaImport::execute($this->user, $this->workspace, driveFile(), MediaType::cases());
    (new ImportRemoteMedia($importId, $this->workspace->id, $this->user->id, driveFile(), MediaType::cases()))->failed(new RuntimeException('timed out'));

    Event::assertDispatched(MediaImportSettled::class, fn (MediaImportSettled $event): bool => $event->importId === $importId);
});

test('a disallowed host ends failed with host_not_allowed', function () {
    Queue::fake();
    Http::fake();

    $file = driveFile('https://93.184.216.99/files/abc');
    [$importId] = StartMediaImport::execute($this->user, $this->workspace, $file, MediaType::cases());
    (new ImportRemoteMedia($importId, $this->workspace->id, $this->user->id, $file, MediaType::cases()))->handle(app(RemoteMediaImporter::class));

    expect(MediaImportStatus::find($importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => 'failed', 'reason' => 'host_not_allowed'])
        ->and(Media::query()->count())->toBe(0);

    Http::assertNothingSent();
});

test('a job that dies marks the import failed', function () {
    Queue::fake();

    [$importId] = StartMediaImport::execute($this->user, $this->workspace, driveFile(), MediaType::cases());
    (new ImportRemoteMedia($importId, $this->workspace->id, $this->user->id, driveFile(), MediaType::cases()))->failed(new RuntimeException('timed out'));

    expect(MediaImportStatus::find($importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => 'failed', 'reason' => 'unreachable']);
});

test('a deleted workspace ends failed', function () {
    Queue::fake();

    [$importId] = StartMediaImport::execute($this->user, $this->workspace, driveFile(), MediaType::cases());
    (new ImportRemoteMedia($importId, (string) Str::uuid(), $this->user->id, driveFile(), MediaType::cases()))->handle(app(RemoteMediaImporter::class));

    expect(MediaImportStatus::find($importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => 'failed']);
});

test('the job runs on the media-imports queue', function () {
    Queue::fake();

    StartMediaImport::execute($this->user, $this->workspace, driveFile(), MediaType::cases());

    Queue::assertPushedOn(ImportRemoteMedia::QUEUE, ImportRemoteMedia::class);
});

test('the transfer gets a deadline well inside the job timeout and follows redirects', function () {
    $job = new ImportRemoteMedia('import', $this->workspace->id, $this->user->id, driveFile(), MediaType::cases());

    $this->mock(RemoteMediaImporter::class)
        ->shouldReceive('import')
        ->once()
        ->withArgs(fn ($workspace, $file, $types, int $timeout, ?CarbonInterface $deadline, bool $followRedirects): bool => $timeout === $job->timeout
            && $deadline !== null
            && $deadline->lessThanOrEqualTo(now()->addSeconds(intdiv($job->timeout, 2)))
            && $followRedirects)
        ->andReturn(ImportedFile::failed(ImportedFile::UNREACHABLE));

    $job->handle(app(RemoteMediaImporter::class));
});

test('a user who lost access by the time the job runs gets nothing imported', function () {
    Queue::fake();
    Http::fake();

    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    [$importId] = StartMediaImport::execute($member, $this->workspace, driveFile(), MediaType::cases());
    $this->workspace->members()->detach($member->id);

    (new ImportRemoteMedia($importId, $this->workspace->id, $member->id, driveFile(), MediaType::cases()))->handle(app(RemoteMediaImporter::class));

    expect(MediaImportStatus::find($importId, $member->id, $this->workspace->id))
        ->toMatchArray(['status' => 'failed'])
        ->and(Media::query()->count())->toBe(0);

    Http::assertNothingSent();
});

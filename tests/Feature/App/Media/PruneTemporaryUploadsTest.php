<?php

declare(strict_types=1);

use App\Actions\Media\PruneTemporaryUploads;
use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Models\Account;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\InstagramPublisher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();

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

function pruneUploadsStoredUpload(Workspace $workspace, int $hoursOld): Media
{
    $media = Media::factory()->temporaryUpload($workspace)->create(['created_at' => now()->subHours($hoursOld)]);
    Storage::put($media->path, 'bytes');

    return $media;
}

function pruneUploadsCropFile(string $path, int $daysOld): void
{
    Storage::put($path, 'jpeg');
    touch(Storage::path($path), now()->subDays($daysOld)->getTimestamp());
}

test('an upload past the retention is deleted with its file, a younger one and an adopted one are kept', function () {
    $expired = pruneUploadsStoredUpload($this->workspace, 25);
    $fresh = pruneUploadsStoredUpload($this->workspace, 23);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $adopted = Media::factory()->ownedByPost($post)->create(['created_at' => now()->subDays(3)]);
    Storage::put($adopted->path, 'bytes');
    $asset = Media::factory()->libraryAsset($this->workspace)->create(['created_at' => now()->subDays(3)]);
    Storage::put($asset->path, 'bytes');

    expect(PruneTemporaryUploads::execute(now()))->toBe(['uploads' => 1, 'crops' => 0, 'chunks' => 0]);

    expect(Media::query()->find($expired->id))->toBeNull()
        ->and(Media::query()->whereKey([$fresh->id, $adopted->id, $asset->id])->count())->toBe(3);

    Storage::assertMissing($expired->path);
    Storage::assertExists([$fresh->path, $adopted->path, $asset->path]);
});

test('a pruned upload token is rejected by the save that follows with media_expired', function () {
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $upload = Media::factory()->temporaryUpload($this->workspace)->create(['created_at' => now()->subHours(25)]);
    $tile = [...MediaItem::fromMedia($upload)->toArray(), 'upload_token' => $upload->upload_token];

    PruneTemporaryUploads::execute(now());

    $this->actingAs($this->user)->post(route('app.posts.store'), [
        'status' => 'draft',
        'content' => 'With an expired upload',
        'media' => [$tile],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
        ]],
    ])->assertSessionHasErrors(['destinations.0.media.0.id' => __('posts.errors.media_expired')]);

    expect(Post::query()->count())->toBe(0);
});

test('crops older than seven days are deleted, younger crops and google business derivatives are kept', function () {
    $directory = InstagramPublisher::CROP_DIRECTORY;
    $old = "{$directory}/".Str::uuid().'.jpg';
    $young = "{$directory}/".Str::uuid().'.jpg';
    $derivative = 'google-business-derivatives/'.Str::uuid().'.jpg';
    pruneUploadsCropFile($old, 8);
    pruneUploadsCropFile($young, 6);
    pruneUploadsCropFile($derivative, 30);

    expect(PruneTemporaryUploads::execute(now()))->toBe(['uploads' => 0, 'crops' => 1, 'chunks' => 0]);

    Storage::assertMissing($old);
    Storage::assertExists([$young, $derivative]);
});

test('a dry run counts without deleting anything', function () {
    $expired = pruneUploadsStoredUpload($this->workspace, 25);
    $crop = InstagramPublisher::CROP_DIRECTORY.'/old.jpg';
    pruneUploadsCropFile($crop, 8);

    expect(PruneTemporaryUploads::execute(now(), dryRun: true))->toBe(['uploads' => 1, 'crops' => 1, 'chunks' => 0]);

    expect(Media::query()->find($expired->id))->not->toBeNull();
    Storage::assertExists([$expired->path, $crop]);
});

test('the command deletes through the queue after commit and prints the counts', function () {
    $expired = pruneUploadsStoredUpload($this->workspace, 30);

    $this->artisan('media:prune-uploads')->expectsOutput('1 temporary upload(s), 0 crop(s) and 0 abandoned chunk file(s) deleted.')->assertExitCode(0);

    expect(Media::query()->find($expired->id))->toBeNull();
    Storage::assertMissing($expired->path);
});

test('the command refuses a retention below one hour', function () {
    config(['trypost.media.upload_retention_hours' => 0]);
    $upload = pruneUploadsStoredUpload($this->workspace, 1);

    $this->artisan('media:prune-uploads')->assertExitCode(1);

    expect(Media::query()->find($upload->id))->not->toBeNull();
});

test('the prune is scheduled hourly without overlapping on one server', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'media:prune-uploads'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

test('a row adopted between the read and the locked delete is not deleted', function () {
    $racing = pruneUploadsStoredUpload($this->workspace, 25);
    $expired = pruneUploadsStoredUpload($this->workspace, 26);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    Media::retrieved(function (Media $media) use ($racing, $post): void {
        if ($media->id === $racing->id) {
            DB::table('medias')->where('id', $racing->id)->update(['collection' => Media::COLLECTION_MEDIA, 'post_id' => $post->id, 'upload_token' => null]);
        }
    });

    expect(PruneTemporaryUploads::execute(now())['uploads'])->toBe(1);

    expect(Media::query()->find($expired->id))->toBeNull()
        ->and(Media::query()->find($racing->id)?->post_id)->toBe($post->id);
    Storage::assertExists($racing->path);
    Storage::assertMissing($expired->path);
});

test('crop ages come from one directory listing, not a request per file', function () {
    $directory = InstagramPublisher::CROP_DIRECTORY;
    collect(range(1, 3))->each(fn (int $index) => pruneUploadsCropFile("{$directory}/old-{$index}.jpg", 8));
    $disk = Storage::disk();
    $spy = Mockery::mock($disk)->makePartial();
    $spy->shouldNotReceive('lastModified');
    Storage::set(config('filesystems.default'), $spy);

    expect(PruneTemporaryUploads::execute(now()))->toBe(['uploads' => 0, 'crops' => 3, 'chunks' => 0]);

    Storage::assertDirectoryEmpty($directory);
});

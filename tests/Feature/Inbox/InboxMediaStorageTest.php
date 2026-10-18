<?php

declare(strict_types=1);

use App\Actions\Inbox\DeleteChannelInbox;
use App\Actions\Inbox\StoreInboxMedia;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Actions\Workspace\PurgeWorkspace;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Events\InboxUpdated;
use App\Jobs\Inbox\StoreInboxMediaJob;
use App\Jobs\Media\DeleteMediaFiles;
use App\Jobs\Media\ImportRemoteMedia;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Inbox\InboxMediaSlots;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    fakePublicDns();
    Event::fake([InboxUpdated::class]);
    $this->png = file_get_contents(base_path('tests/fixtures/1x1.png'));
    $this->account = SocialAccount::factory()->instagram()->create();
    $this->photo = 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=111&signature=first';
    $this->avatar = 'https://scontent-gru1-1.cdninstagram.com/v/t51/ana_n.jpg?oh=first';
});

/**
 * @param  list<array<string, string>>  $media
 * @param  array<string, mixed>  $meta
 */
function inboxDirectWithMedia(string $id, array $media, ?string $avatar, array $meta = [], ?string $participantAvatar = null): IncomingMessage
{
    return new IncomingMessage(
        kind: ConversationKind::DirectMessage,
        subject: new IncomingSubject('igsid-1', null, [], null, null, null, [], 'Ana Fan', 'ana.fan', $participantAvatar),
        platformMessageId: $id,
        platformParentId: null,
        direction: MessageDirection::Inbound,
        authorPlatformId: 'igsid-1',
        authorName: 'Ana Fan',
        authorUsername: 'ana.fan',
        authorAvatarUrl: $avatar,
        body: 'Look',
        media: $media,
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse('2026-10-16 10:00:00'),
        meta: $meta,
    );
}

function storeInboxMediaNow(InboxMessage|InboxConversation $target, bool $lastAttempt = true): bool
{
    return StoreInboxMedia::execute($target->fresh(), $lastAttempt, ImportRemoteMedia::timeoutSeconds());
}

test('an upsert linking a network file queues its copy on the media imports queue', function () {
    Queue::fake([StoreInboxMediaJob::class]);

    UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'image', 'url' => $this->photo]], $this->avatar, participantAvatar: $this->avatar));

    Queue::assertPushedOn(ImportRemoteMedia::QUEUE, StoreInboxMediaJob::class, fn (StoreInboxMediaJob $job): bool => $job->target === StoreInboxMediaJob::MESSAGE);
    Queue::assertPushed(StoreInboxMediaJob::class, fn (StoreInboxMediaJob $job): bool => $job->target === StoreInboxMediaJob::CONVERSATION);
});

test('files already in our storage keep their path, inline data is dropped and nothing is queued', function () {
    Storage::fake(config('filesystems.default'), ['url' => 'https://cdn.trypost.test']);
    Queue::fake([StoreInboxMediaJob::class]);
    $ours = Storage::url('medias/photo.jpg');

    UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'image', 'url' => $ours], ['type' => 'image', 'url' => 'data:image/png;base64,iVBORw0KGgo=']], Storage::url('avatars/me.jpg'), participantAvatar: Storage::url('avatars/ana.jpg')));

    $message = InboxMessage::query()->sole();
    Queue::assertNotPushed(StoreInboxMediaJob::class);
    expect($message->media)->toEqual([['type' => 'image', 'path' => 'medias/photo.jpg'], ['type' => 'image']])
        ->and($message->author_avatar_path)->toBeNull()
        ->and($message->conversation->participant_avatar_path)->toBe('avatars/ana.jpg')
        ->and(data_get($message->meta, InboxMediaSlots::PENDING))->toBeNull();
});

test('the job copies the media and the shared content of a DM and stores their paths', function () {
    $thumbnail = 'https://scontent.xx.fbcdn.net/v/t39/post.jpg?oh=first';
    Http::fake(['https://lookaside.fbsbx.com/*' => Http::response($this->png), 'https://scontent-gru1-1.cdninstagram.com/*' => Http::response($this->png), 'https://scontent.xx.fbcdn.net/*' => Http::response($this->png)]);
    Queue::fake([StoreInboxMediaJob::class]);
    $message = UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'image', 'url' => $this->photo]], $this->avatar, ['content' => ['type' => 'shared_post', 'url' => 'https://www.instagram.com/p/abc', 'thumbnail_url' => $thumbnail]]));
    Event::fake([InboxUpdated::class]);

    (new StoreInboxMediaJob(StoreInboxMediaJob::MESSAGE, $message->id))->handle();

    $message->refresh();
    $rows = Media::query()->where('inbox_message_id', $message->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (Media $media): bool => $media->collection === Media::COLLECTION_MEDIA && $media->mediable_type === null && $media->upload_token === null && $media->workspace_id === $this->account->workspace_id))->toBeTrue()
        ->and($rows->pluck('path')->all())->toEqualCanonicalizing([data_get($message->media, '0.path'), data_get($message->meta, 'content.thumbnail_path')])
        ->and($message->author_avatar_path)->toBeNull()
        ->and(data_get($message->meta, InboxMediaSlots::PENDING))->toBeNull()
        ->and(data_get($message->meta, 'content.url'))->toBe('https://www.instagram.com/p/abc')
        ->and($message->body)->toBe('Look')
        ->and(InboxMediaSlots::pending($message))->toBe([]);
    $rows->each(fn (Media $media) => Storage::assertExists($media->path));
    Event::assertDispatchedTimes(InboxUpdated::class, 1);
});

test('a later read with freshly signed links keeps the copies and queues nothing', function () {
    Http::fake(['https://lookaside.fbsbx.com/*' => Http::response($this->png), 'https://scontent-gru1-1.cdninstagram.com/*' => Http::response($this->png)]);
    Queue::fake([StoreInboxMediaJob::class]);
    $message = UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'image', 'url' => $this->photo]], $this->avatar));
    storeInboxMediaNow($message);
    $stored = $message->fresh();
    Queue::fake([StoreInboxMediaJob::class]);

    UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'image', 'url' => str_replace('first', 'second', $this->photo)]], str_replace('first', 'second', $this->avatar)));
    storeInboxMediaNow($message);

    expect($message->fresh()->media)->toEqual($stored->media)
        ->and(data_get($stored->media, '0.path'))->not->toBeNull()
        ->and(Media::query()->where('inbox_message_id', $message->id)->count())->toBe(1);
    Queue::assertNotPushed(StoreInboxMediaJob::class);
});

test('a read that changes the item empties the slot until the new file is copied and queues it', function () {
    Http::fake(['https://lookaside.fbsbx.com/*' => Http::response($this->png)]);
    Queue::fake([StoreInboxMediaJob::class]);
    $message = UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'image', 'url' => $this->photo]], null));
    storeInboxMediaNow($message);
    Queue::fake([StoreInboxMediaJob::class]);

    UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'video', 'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=222']], null));

    expect($message->fresh()->media)->toEqual([['type' => 'video']])
        ->and(InboxMediaSlots::pending($message->fresh()))->toBe(['media.0.path' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=222']);
    Queue::assertPushed(StoreInboxMediaJob::class);
});

test('an expired link is retried, then left empty and never queued again', function () {
    Http::fake(['https://lookaside.fbsbx.com/*' => Http::response('', 403)]);
    Queue::fake([StoreInboxMediaJob::class]);
    $message = UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'image', 'url' => $this->photo]], null));

    expect(storeInboxMediaNow($message, lastAttempt: false))->toBeTrue()
        ->and(InboxMediaSlots::pending($message->fresh()))->not->toBe([]);

    expect(storeInboxMediaNow($message))->toBeFalse()
        ->and($message->fresh()->media)->toEqual([['type' => 'image']])
        ->and(InboxMediaSlots::pending($message->fresh()))->toBe([])
        ->and(Media::query()->count())->toBe(0);

    Queue::fake([StoreInboxMediaJob::class]);
    UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'image', 'url' => str_replace('first', 'second', $this->photo)]], null));
    Queue::assertNotPushed(StoreInboxMediaJob::class);
});

test('a missing file is given up on its last attempt', function () {
    Http::fake(['https://lookaside.fbsbx.com/*' => Http::response('', 404)]);
    Queue::fake([StoreInboxMediaJob::class]);
    $message = UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'image', 'url' => $this->photo]], null));

    storeInboxMediaNow($message);

    expect(InboxMediaSlots::markerOf(InboxMediaSlots::source($message->fresh()), 'media.0.path'))->toBe(InboxMediaSlots::UNSTORED);
});

test('a file over the size limit is given up at once', function () {
    config()->set('trypost.media.max_size_mb.image', 1);
    Http::fake(['https://lookaside.fbsbx.com/*' => Http::response($this->png.str_repeat("\0", 2 * 1024 * 1024))]);
    Queue::fake([StoreInboxMediaJob::class]);
    $message = UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'image', 'url' => $this->photo]], null));

    expect(storeInboxMediaNow($message, lastAttempt: false))->toBeFalse()
        ->and($message->fresh()->media)->toEqual([['type' => 'image']])
        ->and(InboxMediaSlots::pending($message->fresh()))->toBe([])
        ->and(Media::query()->count())->toBe(0);
});

test('running the job again stores nothing twice', function () {
    Http::fake(['https://lookaside.fbsbx.com/*' => Http::response($this->png)]);
    Queue::fake([StoreInboxMediaJob::class]);
    $message = UpsertInboxMessage::execute($this->account, inboxDirectWithMedia('m1', [['type' => 'image', 'url' => $this->photo]], null));

    storeInboxMediaNow($message);
    storeInboxMediaNow($message);

    expect(Media::query()->count())->toBe(1);
    Http::assertSentCount(1);
});

test('the conversation owns its participant avatar and skips subject media shown by its post', function () {
    Http::fake(['https://scontent-gru1-1.cdninstagram.com/*' => Http::response($this->png)]);
    $post = Post::factory()->forAccount($this->account)->create();
    $conversation = InboxConversation::factory()->create([
        'social_account_id' => $this->account->id,
        'post_id' => $post->id,
        ...InboxMediaSlots::fromNetwork([
            'participant_avatar_url' => $this->avatar,
            'subject_media' => [['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/v/post.jpg']],
            'meta' => [],
        ]),
    ]);

    storeInboxMediaNow($conversation);

    $conversation->refresh();
    expect(Media::query()->where('inbox_conversation_id', $conversation->id)->sole()->path)->toBe($conversation->participant_avatar_path)
        ->and($conversation->subject_media)->toEqual([['type' => 'image']]);
});

test('disconnecting a channel deletes its inbox copies and their files', function () {
    Bus::fake();
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id]);
    $message = InboxMessage::factory()->for($conversation, 'conversation')->create();
    Media::factory()->ownedByInboxMessage($message)->create(['path' => 'medias/message.jpg']);
    Media::factory()->ownedByInboxConversation($conversation)->create(['path' => 'medias/avatar.jpg']);

    DeleteChannelInbox::forAccount($this->account);
    $this->account->delete();

    expect(Media::query()->count())->toBe(0)
        ->and(InboxConversation::query()->count())->toBe(0);
    Bus::assertDispatched(fn (DeleteMediaFiles $job): bool => in_array('medias/message.jpg', $job->paths, true));
});

test('pruning old threads deletes their copies', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id]);
    $message = InboxMessage::factory()->for($conversation, 'conversation')->create(['platform_created_at' => now()->subYears(2)]);
    Media::factory()->ownedByInboxMessage($message)->create();
    Media::factory()->ownedByInboxConversation($conversation)->create();

    $this->artisan('inbox:prune')->assertSuccessful();

    expect(InboxMessage::query()->count())->toBe(0)
        ->and(InboxConversation::query()->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0);
});

test('purging the workspace deletes its inbox copies', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id]);
    $message = InboxMessage::factory()->for($conversation, 'conversation')->create();
    Media::factory()->ownedByInboxMessage($message)->create();

    PurgeWorkspace::execute($this->account->workspace);

    expect(Media::query()->count())->toBe(0)
        ->and(InboxMessage::query()->count())->toBe(0);
});

test('an inbox copy keeps its message from being deleted behind its back', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id]);
    $message = InboxMessage::factory()->for($conversation, 'conversation')->create();
    Media::factory()->ownedByInboxMessage($message)->create();

    expect(fn () => $conversation->delete())->toThrow(QueryException::class);
});

test('the backfill queues every row still linking a network file', function () {
    Storage::fake(config('filesystems.default'), ['url' => 'https://cdn.trypost.test']);
    Queue::fake([StoreInboxMediaJob::class]);
    $network = fn (string $url): array => InboxMediaSlots::fromNetwork(['media' => [['type' => 'image', 'url' => $url]], 'meta' => []]);
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id, ...InboxMediaSlots::fromNetwork(['participant_avatar_url' => $this->avatar, 'meta' => []])]);
    InboxMessage::factory()->for($conversation, 'conversation')->create($network($this->photo));
    InboxMessage::factory()->for($conversation, 'conversation')->create($network('https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:fan/bafy@jpeg'));
    InboxMessage::factory()->for($conversation, 'conversation')->create($network(Storage::url('medias/copy.jpg')));

    $this->artisan('inbox:store-media')->assertSuccessful();

    Queue::assertPushed(StoreInboxMediaJob::class, 3);
});

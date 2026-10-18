<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Events\InboxUpdated;
use App\Http\Resources\Api\InboxMessageResource;
use App\Jobs\Inbox\StoreInboxMediaJob;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Support\Inbox\InboxMediaSlots;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake(config('filesystems.default'), ['url' => 'https://cdn.trypost.test']);
    fakePublicDns();
    Event::fake([InboxUpdated::class]);
    $png = file_get_contents(base_path('tests/fixtures/1x1.png'));
    $mp4 = file_get_contents(base_path('tests/fixtures/sample.mp4'));
    Http::fake(fn (ClientRequest $request) => Http::response(Str::contains($request->url(), ['.mp4', 'video']) ? $mp4 : $png));
});

/**
 * @param  array{
 *     kind: ConversationKind,
 *     avatar?: ?string,
 *     media?: list<array<string, string>>,
 *     meta?: array<string, mixed>,
 *     subject_media?: list<array<string, string>>,
 *     participant_avatar?: ?string,
 * }  $shape
 */
function networkInboxMessage(array $shape, string $signature = 'first'): IncomingMessage
{
    $sign = fn (mixed $value): mixed => is_string($value) ? str_replace('{sig}', $signature, $value) : $value;
    $signAll = fn (array $value): array => json_decode($sign(json_encode($value, JSON_UNESCAPED_SLASHES)), true);

    return new IncomingMessage(
        kind: data_get($shape, 'kind'),
        subject: new IncomingSubject(
            platformId: 'subject-1',
            text: 'The post',
            media: $signAll(data_get($shape, 'subject_media', [])),
            url: null,
            publishedAt: null,
            remoteId: null,
            participantName: 'Ana Fan',
            participantUsername: 'ana.fan',
            participantAvatarUrl: $sign(data_get($shape, 'participant_avatar')),
        ),
        platformMessageId: 'message-1',
        platformParentId: null,
        direction: MessageDirection::Inbound,
        authorPlatformId: 'fan-1',
        authorName: 'Ana Fan',
        authorUsername: 'ana.fan',
        authorAvatarUrl: $sign(data_get($shape, 'avatar')),
        body: 'Look',
        media: $signAll(data_get($shape, 'media', [])),
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse('2026-10-16 10:00:00'),
        meta: $signAll(data_get($shape, 'meta', [])),
    );
}

function runQueuedInboxMediaJobs(): void
{
    Queue::pushed(StoreInboxMediaJob::class)->each(fn (StoreInboxMediaJob $job) => $job->handle());
}

/**
 * Every slot the row fills, with the path it stores.
 *
 * @return array<string, mixed>
 */
function inboxMediaSlotValues(InboxMessage|InboxConversation $row): array
{
    $source = InboxMediaSlots::source($row);
    $slots = $row instanceof InboxMessage
        ? ['author_avatar_path', 'meta.content.path', 'meta.content.thumbnail_path', ...collect($row->media ?? [])->keys()->flatMap(fn (int $index): array => ["media.{$index}.path", "media.{$index}.thumbnail_path"])->all()]
        : ['participant_avatar_path', ...collect($row->subject_media ?? [])->keys()->flatMap(fn (int $index): array => ["subject_media.{$index}.path", "subject_media.{$index}.thumbnail_path"])->all()];

    return collect($slots)
        ->mapWithKeys(fn (string $slot): array => [$slot => data_get($source, $slot)])
        ->filter(fn (mixed $path): bool => filled($path))
        ->all();
}

/**
 * Every file URL the client is sent for the message and its conversation.
 *
 * @return list<string>
 */
function inboxResourceFileUrls(InboxMessage $message): array
{
    $message = $message->fresh(['conversation.socialAccount']);
    $resource = InboxMessageResource::make($message)->toArray(Request::create('/'));
    $conversation = data_get($resource, 'conversation')->toArray(Request::create('/'));

    return collect([
        data_get($resource, 'author.avatar_url'),
        data_get($resource, 'content.url'),
        data_get($resource, 'content.thumbnail_url'),
        ...collect(data_get($resource, 'media'))->flatMap(fn (array $item): array => [data_get($item, 'url'), data_get($item, 'thumbnail_url')])->all(),
        data_get($conversation, 'participant.avatar_url'),
        ...collect(data_get($conversation, 'subject.media'))->flatMap(fn (array $item): array => [data_get($item, 'url'), data_get($item, 'thumbnail_url')])->all(),
    ])->filter()->unique()->values()->all();
}

dataset('network inbox media', [
    'Instagram DM (Instagram Login)' => [fn () => SocialAccount::factory()->instagram()->create(), [
        'kind' => ConversationKind::DirectMessage,
        'avatar' => 'https://scontent-gru1-1.cdninstagram.com/v/t51.2885-19/ana_n.jpg?oh={sig}',
        'participant_avatar' => 'https://scontent-gru1-1.cdninstagram.com/v/t51.2885-19/ana_n.jpg?oh={sig}',
        'media' => [['type' => 'image', 'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1&signature={sig}']],
        'meta' => ['content' => ['type' => 'story_mention', 'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=video-2&signature={sig}']],
    ], 3],
    'Instagram via Facebook comment mention' => [fn () => SocialAccount::factory()->instagramFacebook()->create(), [
        'kind' => ConversationKind::Mention,
        'subject_media' => [['type' => 'image', 'url' => 'https://scontent.cdninstagram.com/v/t51.29350-15/post_n.jpg?oh={sig}']],
    ], 1],
    'Facebook Messenger attachment and post picture' => [fn () => SocialAccount::factory()->facebook()->create(), [
        'kind' => ConversationKind::Mention,
        'media' => [['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/v/t39.30808-6/full_picture.jpg?oh={sig}']],
        'subject_media' => [['type' => 'image', 'url' => 'https://scontent.xx.fbcdn.net/v/t39.30808-6/full_picture.jpg?oh={sig}']],
        'meta' => ['content' => ['type' => 'attachment', 'url' => 'https://cdn.fbsbx.com/v/t59.2708-21/file.png?oh={sig}', 'title' => 'file.png']],
    ], 3],
    'Threads reply' => [fn () => SocialAccount::factory()->threads()->create(), [
        'kind' => ConversationKind::Comment,
        'avatar' => 'https://scontent-iad3-1.cdninstagram.com/v/t51.2885-19/fan.jpg?oh={sig}',
        'subject_media' => [['type' => 'image', 'url' => 'https://scontent-iad3-1.cdninstagram.com/v/t51.2885-15/thumb.jpg?oh={sig}']],
    ], 1],
    'YouTube comment' => [fn () => SocialAccount::factory()->youtube()->create(), [
        'kind' => ConversationKind::Comment,
        'avatar' => 'https://yt3.ggpht.com/ytc/fan=s48-c-k-c0x00ffffff-no-rj',
        'subject_media' => [['type' => 'image', 'url' => 'https://i.ytimg.com/vi/abc123/mqdefault.jpg']],
    ], 1],
    'Bluesky reply and DM participant' => [fn () => SocialAccount::factory()->bluesky()->create(), [
        'kind' => ConversationKind::Comment,
        'avatar' => 'https://cdn.bsky.app/img/avatar/plain/did:plc:fan/bafkreiavatar@jpeg',
        'participant_avatar' => 'https://cdn.bsky.app/img/avatar/plain/did:plc:fan/bafkreiavatar@jpeg',
        'subject_media' => [['type' => 'image', 'url' => 'https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:me/bafkreithumb@jpeg']],
    ], 2],
    'Mastodon status on any instance' => [fn () => SocialAccount::factory()->mastodon()->create(), [
        'kind' => ConversationKind::Comment,
        'avatar' => 'https://files.fosstodon.org/accounts/avatars/000/000/001/original/fan.png',
        'participant_avatar' => 'https://files.fosstodon.org/accounts/avatars/000/000/001/original/fan.png',
        'media' => [['type' => 'video', 'url' => 'https://files.fosstodon.org/media_attachments/files/000/original/clip.mp4', 'thumbnail_url' => 'https://files.fosstodon.org/media_attachments/files/000/small/clip.png']],
        'subject_media' => [['type' => 'image', 'url' => 'https://files.fosstodon.org/media_attachments/files/001/original/photo.png', 'thumbnail_url' => 'https://files.fosstodon.org/media_attachments/files/001/small/photo.png']],
    ], 4],
    'Google Business review' => [fn () => SocialAccount::factory()->googleBusiness()->create(), [
        'kind' => ConversationKind::Review,
        'avatar' => 'https://lh3.googleusercontent.com/a-/reviewer=s120-c-rp-mo-br100',
        'participant_avatar' => 'https://lh3.googleusercontent.com/a-/reviewer=s120-c-rp-mo-br100',
        'subject_media' => [
            ['type' => 'image', 'url' => 'https://lh3.googleusercontent.com/geougc-cs/photo=w400'],
            ['type' => 'video', 'url' => 'https://lh3.googleusercontent.com/geougc-cs/video=m18', 'thumbnail_url' => 'https://lh3.googleusercontent.com/geougc-cs/thumb=w400'],
        ],
    ], 5],
]);

test('every network file an inbox row links is stored on our disk, the row keeps its path and the client gets our URL', function (Closure $account, array $shape, int $files) {
    Queue::fake([StoreInboxMediaJob::class]);
    $account = $account();

    $message = UpsertInboxMessage::execute($account, networkInboxMessage($shape));
    $conversation = $message->conversation;

    expect(InboxMediaSlots::pending($message) + InboxMediaSlots::pending($conversation))->toHaveCount($files);

    runQueuedInboxMediaJobs();

    $message->refresh();
    $conversation->refresh();
    $rows = Media::query()->where('workspace_id', $account->workspace_id)->get();
    $paths = [...array_values(inboxMediaSlotValues($message)), ...array_values(inboxMediaSlotValues($conversation))];
    $urls = inboxResourceFileUrls($message);

    expect($rows)->toHaveCount($files)
        ->and($paths)->toHaveCount($files)
        ->and($rows->pluck('path')->all())->toEqualCanonicalizing($paths)
        ->and($urls)->toEqualCanonicalizing(array_map(fn (string $path): string => "https://cdn.trypost.test/{$path}", $paths))
        ->and(data_get($message->meta, InboxMediaSlots::PENDING))->toBeNull()
        ->and(data_get($conversation->meta, InboxMediaSlots::PENDING))->toBeNull()
        ->and(InboxMediaSlots::pending($message))->toBe([])
        ->and(InboxMediaSlots::pending($conversation))->toBe([]);
    $rows->each(fn (Media $media) => Storage::assertExists($media->path));
})->with('network inbox media');

test('a re-read with freshly signed links keeps every copy and downloads nothing again', function (Closure $account, array $shape, int $files) {
    Queue::fake([StoreInboxMediaJob::class]);
    $account = $account();
    $message = UpsertInboxMessage::execute($account, networkInboxMessage($shape));
    runQueuedInboxMediaJobs();
    $stored = [...inboxMediaSlotValues($message->fresh()), ...inboxMediaSlotValues($message->conversation->fresh())];
    Queue::fake([StoreInboxMediaJob::class]);
    $requests = Http::recorded()->count();

    UpsertInboxMessage::execute($account, networkInboxMessage($shape, signature: 'second'));
    (new StoreInboxMediaJob(StoreInboxMediaJob::MESSAGE, $message->id))->handle();
    (new StoreInboxMediaJob(StoreInboxMediaJob::CONVERSATION, $message->inbox_conversation_id))->handle();

    expect([...inboxMediaSlotValues($message->fresh()), ...inboxMediaSlotValues($message->conversation->fresh())])->toBe($stored)
        ->and(Media::query()->count())->toBe($files);
    Queue::assertNotPushed(StoreInboxMediaJob::class);
    expect(Http::recorded())->toHaveCount($requests);
})->with('network inbox media');

test('a shared post keeps its link to the post and stores only its preview', function () {
    Queue::fake([StoreInboxMediaJob::class]);
    $account = SocialAccount::factory()->instagram()->create();

    $message = UpsertInboxMessage::execute($account, networkInboxMessage([
        'kind' => ConversationKind::DirectMessage,
        'meta' => ['content' => ['type' => 'shared_post', 'url' => 'https://www.instagram.com/reel/DdbS2uhx6h1/', 'thumbnail_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=3&signature={sig}']],
    ]));
    runQueuedInboxMediaJobs();

    $content = data_get(InboxMessageResource::make($message->fresh())->toArray(Request::create('/')), 'content');

    expect(data_get($content, 'url'))->toBe('https://www.instagram.com/reel/DdbS2uhx6h1/')
        ->and(data_get($content, 'thumbnail_url'))->toBe('https://cdn.trypost.test/'.data_get($message->fresh()->meta, 'content.thumbnail_path'))
        ->and(Media::query()->count())->toBe(1);
    Http::assertNotSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'instagram.com/reel'));
});

test('a file not stored yet is never sent to the client as a network link', function () {
    Queue::fake([StoreInboxMediaJob::class]);
    $account = SocialAccount::factory()->bluesky()->create();

    $message = UpsertInboxMessage::execute($account, networkInboxMessage([
        'kind' => ConversationKind::Comment,
        'avatar' => 'https://cdn.bsky.app/img/avatar/plain/did:plc:fan/bafkreiavatar@jpeg',
        'media' => [['type' => 'image', 'url' => 'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:fan/bafkreiphoto@jpeg']],
    ]));

    expect(inboxResourceFileUrls($message))->toBe([])
        ->and($message->fresh()->author_avatar_path)->toBeNull();
});

test('a message whose media is still being copied says so, and stops saying it once stored', function () {
    Queue::fake([StoreInboxMediaJob::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    $resource = fn (InboxMessage $message): array => InboxMessageResource::make($message->fresh())->toArray(Request::create('/'));

    $withMedia = UpsertInboxMessage::execute($account, networkInboxMessage([
        'kind' => ConversationKind::Comment,
        'media' => [['type' => 'image', 'url' => 'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:fan/bafkreiphoto@jpeg']],
    ]));
    $avatarOnly = UpsertInboxMessage::execute(SocialAccount::factory()->bluesky()->create(), networkInboxMessage([
        'kind' => ConversationKind::Comment,
        'avatar' => 'https://cdn.bsky.app/img/avatar/plain/did:plc:fan/bafkreiavatar@jpeg',
    ]));

    expect(data_get($resource($withMedia), 'media_pending'))->toBeTrue()
        ->and(data_get($resource($avatarOnly), 'media_pending'))->toBeFalse();

    (new StoreInboxMediaJob(StoreInboxMediaJob::MESSAGE, $withMedia->id))->handle();

    expect(data_get($resource($withMedia), 'media_pending'))->toBeFalse();
});

test('a message the channel wrote shows the channel\'s current picture and stores none', function () {
    Queue::fake([StoreInboxMediaJob::class]);
    $account = SocialAccount::factory()->instagram()->create(['avatar_url' => 'avatars/channel.jpg']);
    $shape = ['kind' => ConversationKind::DirectMessage, 'avatar' => 'https://scontent.cdninstagram.com/v/me.jpg?oh={sig}'];

    $message = UpsertInboxMessage::execute($account, new IncomingMessage(...[...get_object_vars(networkInboxMessage($shape)), 'direction' => MessageDirection::Outbound]));

    expect($message->fresh()->author_avatar_path)->toBeNull()
        ->and(InboxMediaSlots::pending($message->fresh()))->toBe([])
        ->and(inboxResourceFileUrls($message)[0])->toBe('https://cdn.trypost.test/avatars/channel.jpg');
    Queue::assertNotPushed(StoreInboxMediaJob::class, fn (StoreInboxMediaJob $job): bool => $job->target === StoreInboxMediaJob::MESSAGE);
});

<?php

declare(strict_types=1);

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Actions\Inbox\ImportInboxConversationPost;
use App\Actions\Inbox\RemoveInboxMessages;
use App\Actions\Inbox\UpsertInboxMessages;
use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Post\Origin;
use App\Exceptions\Inbox\InboxPostUnavailableException;
use App\Jobs\Inbox\HandleMetaWebhook;
use App\Jobs\Inbox\ImportInboxPost;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Jobs\Post\ImportExternalPostMedia;
use App\Models\AnalyticsPublication;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\InboxPendingMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class, ImportExternalPostMedia::class]);
    $this->ig = config('trypost.platforms.instagram.graph_api');
    $this->account = SocialAccount::factory()->instagram()->withInboxScopes()->create([
        'platform_user_id' => 'app-scoped-1',
        'username' => 'brand',
        'token_expires_at' => now()->addDays(30),
        'meta' => ['ig_user_id' => '17841400000000001'],
    ]);
});

function postFirstWebhook(array $value = [], int $time = 1760600000): array
{
    return [
        'object' => 'instagram',
        'entry' => [[
            'id' => '17841400000000001',
            'time' => $time,
            'changes' => [['field' => 'comments', 'value' => [
                'from' => ['id' => '17841499999999999', 'username' => 'fan.account'],
                'media' => ['id' => '18000000000000001', 'media_product_type' => 'FEED'],
                'id' => '17900000000000001',
                'text' => 'Love this!',
                ...$value,
            ]]],
        ]],
    ];
}

function fakeInstagramMediaRead(string $ig): void
{
    Http::fake(["{$ig}/18000000000000001*" => Http::response([
        'id' => '18000000000000001',
        'owner' => ['id' => '17841400000000001'],
        'caption' => 'Launch day',
        'media_type' => 'VIDEO',
        'media_product_type' => 'REELS',
        'permalink' => 'https://www.instagram.com/reel/abc/',
        'timestamp' => '2026-10-14T10:00:00+0000',
    ])]);
}

function postFirstComment(string $id, string $postId = '18000000000000001', ?DiscoveredPublication $publication = null, ?CarbonImmutable $observedAt = null, string $body = 'Nice', bool $removed = false): IncomingMessage
{
    return new IncomingMessage(
        kind: ConversationKind::Comment,
        subject: new IncomingSubject($postId, null, [], null, null, $postId, publication: $publication),
        platformMessageId: $id,
        platformParentId: null,
        direction: MessageDirection::Inbound,
        authorPlatformId: '999',
        authorName: 'fan',
        authorUsername: 'fan',
        authorAvatarUrl: null,
        body: $body,
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse('2026-10-15 10:00:00'),
        removed: $removed,
        observedAt: $observedAt,
    );
}

test('a polled comment on a post TryPost does not have imports the post it was listed with, then stores the comment under it', function () {
    Http::fake([
        "{$this->ig}/app-scoped-1/media*" => Http::response(['data' => [[
            'id' => '18000000000000001', 'caption' => 'Launch day', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/1.jpg',
            'permalink' => 'https://www.instagram.com/p/abc/', 'timestamp' => now()->subDays(2)->toIso8601String(),
        ]]]),
        "{$this->ig}/app-scoped-1/stories*" => Http::response(['data' => []]),
        "{$this->ig}/18000000000000001/comments*" => Http::response(['data' => [[
            'id' => 'c1', 'text' => 'Nice', 'timestamp' => now()->subDay()->toIso8601String(), 'from' => ['id' => '999', 'username' => 'fan'],
        ]]]),
    ]);

    (new SyncInboxAccount($this->account->id, ConversationKind::Comment, true))->handle();

    $post = Post::query()->sole();
    $message = InboxMessage::query()->sole();

    expect($post->origin)->toBe(Origin::Network)
        ->and($post->platform_post_id)->toBe('18000000000000001')
        ->and($post->content)->toBe('Launch day')
        ->and($message->conversation->post_id)->toBe($post->id)
        ->and($message->conversation->subject_text)->toBeNull()
        ->and(AnalyticsPublication::query()->sole()->post_id)->toBe($post->id)
        ->and(InboxPendingMessage::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), "{$this->ig}/18000000000000001?"));
    Queue::assertPushed(ImportExternalPostMedia::class, fn (ImportExternalPostMedia $job): bool => $job->postId === $post->id);
});

test('a webhook comment on a post TryPost does not have reads that post once, imports it and stores the comment', function () {
    fakeInstagramMediaRead($this->ig);

    HandleMetaWebhook::dispatchSync(postFirstWebhook());

    $post = Post::query()->sole();

    expect(InboxMessage::query()->sole()->conversation->post_id)->toBe($post->id)
        ->and($post->content_type->value)->toBe('instagram_reel')
        ->and(InboxPendingMessage::query()->count())->toBe(0);
});

test('a comment waits instead of being stored while its post cannot be imported, and is tried again with backoff', function () {
    Http::fake(["{$this->ig}/18000000000000001*" => Http::sequence()
        ->push(['id' => '18000000000000001', 'owner' => ['id' => '17841400000000001']])
        ->push(['error' => ['code' => 100, 'error_subcode' => 33, 'message' => 'Unsupported get request.']], 400)]);
    Queue::fake([ImportInboxPost::class, ImportExternalPostMedia::class]);

    HandleMetaWebhook::dispatchSync(postFirstWebhook());

    expect(InboxMessage::query()->count())->toBe(0)
        ->and(InboxConversation::query()->count())->toBe(0)
        ->and(InboxPendingMessage::query()->sole()->platform_post_id)->toBe('18000000000000001');
    Queue::assertPushed(ImportInboxPost::class, fn (ImportInboxPost $job): bool => $job->platformPostId === '18000000000000001');

    $job = (new ImportInboxPost($this->account->id, '18000000000000001'))->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(ImportInboxPost::BACKOFF[0]);
    expect(InboxPendingMessage::query()->count())->toBe(1)
        ->and(Post::query()->count())->toBe(0);
});

test('after its last attempt a post that never came drops its waiting comments and is reported once', function () {
    Exceptions::fake();
    Http::fake(["{$this->ig}/18000000000000001*" => Http::response(['error' => ['code' => 10, 'message' => 'no permission']], 403)]);
    InboxPendingMessage::factory()->create(['social_account_id' => $this->account->id, 'platform_post_id' => '18000000000000001', 'platform_post_hash' => InboxConversation::hashOf('18000000000000001')]);

    $job = (new ImportInboxPost($this->account->id, '18000000000000001'))->withFakeQueueInteractions();
    $job->job->attempts = ImportInboxPost::MAX_ATTEMPTS;
    $job->handle();

    $job->assertNotReleased();
    expect(InboxPendingMessage::query()->count())->toBe(0)
        ->and(InboxMessage::query()->count())->toBe(0);
    Exceptions::assertReported(fn (InboxPostUnavailableException $exception): bool => str_contains($exception->getMessage(), 'Dropped 1 inbox comments'));
});

test('a post the workspace removed from TryPost is not imported again and its comments stop waiting at once', function () {
    Exceptions::fake();
    Http::preventStrayRequests();
    AnalyticsPublication::factory()->create([
        'workspace_id' => $this->account->workspace_id,
        'social_account_id' => $this->account->id,
        'social_account_key' => app(ResolveAnalyticsAccountKey::class)->for($this->account),
        'remote_id' => '18000000000000001',
        'post_dismissed_at' => now(),
    ]);
    InboxPendingMessage::factory()->create(['social_account_id' => $this->account->id, 'platform_post_id' => '18000000000000001', 'platform_post_hash' => InboxConversation::hashOf('18000000000000001')]);

    $job = (new ImportInboxPost($this->account->id, '18000000000000001'))->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();
    expect(InboxPendingMessage::query()->count())->toBe(0)
        ->and(Post::query()->count())->toBe(0);
    Exceptions::assertNothingReported();
});

test('importing the same post twice, or after the analytics sync imported it, keeps one post', function () {
    $publication = new DiscoveredPublication('18000000000000001', CarbonImmutable::parse('2026-10-14 10:00:00'), PublicationContentType::Image, excerpt: 'Launch day');
    $subject = new IncomingSubject('18000000000000001', null, [], null, null, '18000000000000001', publication: $publication);

    $first = ImportInboxConversationPost::execute($this->account, $subject);
    $second = ImportInboxConversationPost::execute($this->account, $subject);

    expect($second)->toBe($first)
        ->and(Post::query()->count())->toBe(1)
        ->and(AnalyticsPublication::query()->count())->toBe(1);
});

test('a comment on a post TryPost published is linked to it, never imported as a second post', function () {
    $published = Post::factory()->forAccount($this->account)->published()->create(['platform_post_id' => '18000000000000001']);
    Http::preventStrayRequests();

    UpsertInboxMessages::execute($this->account, [postFirstComment('c1')]);

    expect(Post::query()->count())->toBe(1)
        ->and(InboxMessage::query()->sole()->conversation->post_id)->toBe($published->id);
});

test('a webhook and then a poll of the same comment keep one message, and the reverse too', function (bool $webhookFirst) {
    Post::factory()->forAccount($this->account)->published()->create(['platform_post_id' => '18000000000000001']);
    $webhook = postFirstComment('c1', observedAt: CarbonImmutable::parse('2026-10-15 10:00:05'));
    $poll = postFirstComment('c1');

    UpsertInboxMessages::execute($this->account, [$webhookFirst ? $webhook : $poll]);
    UpsertInboxMessages::execute($this->account, [$webhookFirst ? $poll : $webhook]);

    expect(InboxMessage::query()->count())->toBe(1)
        ->and(InboxConversation::query()->count())->toBe(1);
})->with(['webhook first' => [true], 'poll first' => [false]]);

test('a delivery Meta retries keeps one message, also while it waits for its post', function () {
    Queue::fake([ImportInboxPost::class, ImportExternalPostMedia::class]);
    fakeInstagramMediaRead($this->ig);

    HandleMetaWebhook::dispatchSync(postFirstWebhook());
    HandleMetaWebhook::dispatchSync(postFirstWebhook());

    expect(InboxPendingMessage::query()->count())->toBe(1);

    (new ImportInboxPost($this->account->id, '18000000000000001'))->handle();
    HandleMetaWebhook::dispatchSync(postFirstWebhook());

    expect(InboxMessage::query()->count())->toBe(1)
        ->and(InboxPendingMessage::query()->count())->toBe(0);
});

test('an older delivery arriving after a newer one never overwrites it', function () {
    Post::factory()->forAccount($this->account)->published()->create(['platform_post_id' => '18000000000000001']);

    UpsertInboxMessages::execute($this->account, [postFirstComment('c1', observedAt: CarbonImmutable::parse('2026-10-15 12:00:00'), body: 'Edited twice')]);
    UpsertInboxMessages::execute($this->account, [postFirstComment('c1', observedAt: CarbonImmutable::parse('2026-10-15 11:00:00'), body: 'Edited once')]);

    expect(InboxMessage::query()->sole()->body)->toBe('Edited twice');

    UpsertInboxMessages::execute($this->account, [postFirstComment('c1', observedAt: CarbonImmutable::parse('2026-10-15 13:00:00'), body: 'Edited three times')]);

    expect(InboxMessage::query()->sole()->body)->toBe('Edited three times');
});

test('a removal that arrives before its comment leaves the comment removed when it lands', function () {
    Post::factory()->forAccount($this->account)->published()->create(['platform_post_id' => '18000000000000001']);

    RemoveInboxMessages::execute($this->account, ['c1']);
    UpsertInboxMessages::execute($this->account, [postFirstComment('c1')]);

    expect(InboxMessage::query()->sole()->removed_at)->not->toBeNull()
        ->and(InboxConversation::query()->sole()->open_count)->toBe(0);
});

test('a removal of a comment still waiting for its post drops it', function () {
    Queue::fake([ImportInboxPost::class, ImportExternalPostMedia::class]);

    UpsertInboxMessages::execute($this->account, [postFirstComment('c1')]);
    RemoveInboxMessages::execute($this->account, ['c1']);

    expect(InboxPendingMessage::query()->count())->toBe(0);
});

test('waiting comments are stored in time order once their post lands', function () {
    Queue::fake([ImportInboxPost::class, ImportExternalPostMedia::class]);
    $publication = new DiscoveredPublication('18000000000000001', CarbonImmutable::parse('2026-10-14 10:00:00'), PublicationContentType::Image, excerpt: 'Launch day');
    $reply = new IncomingMessage(...[...get_object_vars(postFirstComment('c2', publication: $publication)), 'platformParentId' => 'c1', 'platformCreatedAt' => CarbonImmutable::parse('2026-10-15 11:00:00')]);

    UpsertInboxMessages::execute($this->account, [$reply, postFirstComment('c1', publication: $publication)]);
    (new ImportInboxPost($this->account->id, '18000000000000001'))->handle();

    $root = InboxMessage::query()->where('platform_message_id', 'c1')->sole();

    expect(InboxMessage::query()->where('platform_message_id', 'c2')->sole()->parent_id)->toBe($root->id)
        ->and(InboxConversation::query()->sole()->post_id)->toBe(Post::query()->sole()->id);
});

test('a mention only a webhook delivers is stored at once, with no post', function () {
    Http::preventStrayRequests();
    Http::fake(["{$this->ig}/18000000000000001*" => Http::response(['id' => '18000000000000001', 'owner' => ['id' => '17899999999999999']])]);

    HandleMetaWebhook::dispatchSync(postFirstWebhook(['text' => '@brand look']));

    $message = InboxMessage::query()->sole();

    expect($message->conversation->kind)->toBe(ConversationKind::Mention)
        ->and($message->conversation->post_id)->toBeNull()
        ->and(Post::query()->count())->toBe(0)
        ->and(InboxPendingMessage::query()->count())->toBe(0);
});

test('the dispatcher queues the import again for comments still waiting', function () {
    Queue::fake([ImportInboxPost::class, SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    InboxPendingMessage::factory()->count(2)->create(['social_account_id' => $this->account->id, 'platform_post_id' => 'p1', 'platform_post_hash' => InboxConversation::hashOf('p1')]);
    InboxPendingMessage::factory()->create(['social_account_id' => $this->account->id, 'platform_post_id' => 'old', 'platform_post_hash' => InboxConversation::hashOf('old'), 'created_at' => now()->subDays(InboxPendingMessage::KEEP_DAYS + 1)]);

    $this->artisan('inbox:dispatch-sync')->assertSuccessful();

    Queue::assertPushed(ImportInboxPost::class, 1);
    Queue::assertPushed(ImportInboxPost::class, fn (ImportInboxPost $job): bool => $job->platformPostId === 'p1');
});

test('pruning drops comments that waited too long for their post', function () {
    InboxPendingMessage::factory()->create(['social_account_id' => $this->account->id, 'created_at' => now()->subDays(InboxPendingMessage::KEEP_DAYS + 1)]);
    $fresh = InboxPendingMessage::factory()->create(['social_account_id' => $this->account->id]);

    $this->artisan('inbox:prune')->assertSuccessful();

    expect(InboxPendingMessage::query()->pluck('id')->all())->toBe([$fresh->id]);
});

<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Actions\Inbox\UpsertInboxMessages;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\FacebookPublishException;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\FacebookInboxDriver;
use App\Services\Inbox\InboxDrivers;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    $this->graph = config('trypost.platforms.facebook.graph_api');
    $this->account = SocialAccount::factory()->facebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    $this->driver = app(FacebookInboxDriver::class);
    $this->post = Post::factory()->forAccount($this->account)->published()->create(['platform_post_id' => 'page-1_post-1']);
});

/** @return array<string, mixed> */
function facebookFeedValue(array $overrides = []): array
{
    $value = data_get(json_decode((string) file_get_contents(base_path('tests/fixtures/inbox/facebook-feed-comment.json')), true), 'entry.0.changes.0.value');

    return array_replace_recursive($value, $overrides);
}

function facebookComment(string $id, ?string $parent = null): IncomingMessage
{
    return new IncomingMessage(
        kind: ConversationKind::Comment,
        subject: new IncomingSubject('page-1_post-1', 'Our news', [], 'https://www.facebook.com/page-1/posts/1', null, 'page-1_post-1'),
        platformMessageId: $id,
        platformParentId: $parent,
        direction: MessageDirection::Inbound,
        authorPlatformId: 'user-9',
        authorName: 'Ana Fan',
        authorUsername: null,
        authorAvatarUrl: null,
        body: 'Great news',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse($parent ? '2026-10-15 11:00:00' : '2026-10-15 10:00:00'),
    );
}

test('facebook pages use the facebook driver', function () {
    expect(app(InboxDrivers::class)->for(Platform::Facebook))->toBeInstanceOf(FacebookInboxDriver::class);
});

test('poll reads every level of comments on recent posts', function () {
    Http::fake([
        "{$this->graph}/page-1/published_posts*" => Http::response(['data' => [[
            'id' => 'page-1_post-1',
            'message' => 'Our news',
            'created_time' => now()->subDays(2)->toIso8601String(),
            'permalink_url' => 'https://www.facebook.com/page-1/posts/1',
        ]]]),
        "{$this->graph}/page-1_post-1/comments*" => Http::response(['data' => [
            ['id' => 'c1', 'message' => 'Hello', 'from' => ['id' => 'user-9', 'name' => 'Ana Fan', 'picture' => ['data' => ['is_silhouette' => false, 'url' => 'https://lookaside.example/ana.jpg']]], 'created_time' => now()->subDay()->toIso8601String(), 'permalink_url' => 'https://www.facebook.com/c1'],
            ['id' => 'c2', 'message' => 'Thanks', 'from' => ['id' => 'page-1', 'name' => 'Acme', 'picture' => ['data' => ['is_silhouette' => true, 'url' => 'https://lookaside.example/default.jpg']]], 'created_time' => now()->subHours(20)->toIso8601String(), 'parent' => ['id' => 'c1']],
            ['id' => 'c3', 'message' => 'Spam', 'from' => ['id' => 'user-8', 'name' => 'Bot'], 'created_time' => now()->subHours(19)->toIso8601String(), 'is_hidden' => true],
        ]]),
    ]);

    $messages = collect($this->driver->poll($this->account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable())->messages);

    expect($messages->pluck('platformMessageId')->all())->toBe(['c1', 'c2', 'c3'])
        ->and($messages->firstWhere('platformMessageId', 'c1')->platformParentId)->toBeNull()
        ->and($messages->firstWhere('platformMessageId', 'c1')->authorName)->toBe('Ana Fan')
        ->and($messages->firstWhere('platformMessageId', 'c1')->platformUrl)->toBe('https://www.facebook.com/c1')
        ->and($messages->firstWhere('platformMessageId', 'c1')->authorAvatarUrl)->toBe('https://lookaside.example/ana.jpg')
        ->and($messages->firstWhere('platformMessageId', 'c2')->authorAvatarUrl)->toBeNull()
        ->and($messages->firstWhere('platformMessageId', 'c2')->platformParentId)->toBe('c1')
        ->and($messages->firstWhere('platformMessageId', 'c2')->direction)->toBe(MessageDirection::Outbound)
        ->and($messages->firstWhere('platformMessageId', 'c3')->removed)->toBeFalse()
        ->and($messages->firstWhere('platformMessageId', 'c3')->hidden)->toBeTrue()
        ->and($messages->firstWhere('platformMessageId', 'c1')->hidden)->toBeNull()
        ->and($messages->first()->subject->text)->toBe('Our news');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), "{$this->graph}/page-1_post-1/comments")
        && data_get($request->data(), 'filter') === 'stream'
        && (int) data_get($request->data(), 'limit') === 50);
});

test('a busy post pages its comments newest first and stops at the page cap', function () {
    $page = 0;
    Http::fake([
        "{$this->graph}/page-1/published_posts*" => Http::response(['data' => [[
            'id' => 'page-1_post-1', 'message' => 'Our news', 'created_time' => now()->subDays(2)->toIso8601String(),
        ]]]),
        "{$this->graph}/page-1_post-1/comments*" => function () use (&$page) {
            $page++;

            return Http::response([
                'data' => [['id' => "c{$page}", 'message' => 'Hi', 'from' => ['id' => 'user-9', 'name' => 'Ana Fan'], 'created_time' => now()->subMinutes($page)->toIso8601String()]],
                'paging' => ['cursors' => ['after' => "after-{$page}"], 'next' => "https://graph.example/next-{$page}"],
            ]);
        },
    ]);

    $messages = $this->driver->poll($this->account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable())->messages;

    expect($messages)->toHaveCount(10)
        ->and($messages[0]->platformMessageId)->toBe('c1');
    Http::assertSentCount(11);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), "{$this->graph}/page-1_post-1/comments")
        && data_get($request->data(), 'order') === 'reverse_chronological'
        && data_get($request->data(), 'after') === 'after-9');
});

test('a top-level webhook comment has no parent and only names its post', function () {
    Http::fake();

    $events = $this->driver->webhookEvents($this->account, facebookFeedValue());

    expect($events->messages)->toHaveCount(1)
        ->and($events->messages[0]->platformMessageId)->toBe('post-1_c1')
        ->and($events->messages[0]->platformParentId)->toBeNull()
        ->and($events->messages[0]->direction)->toBe(MessageDirection::Inbound)
        ->and($events->messages[0]->authorName)->toBe('Ana Fan')
        ->and($events->messages[0]->body)->toBe('Great news')
        ->and($events->messages[0]->platformCreatedAt->getTimestamp())->toBe(1760600000)
        ->and($events->messages[0]->subject->platformId)->toBe('page-1_post-1')
        ->and($events->messages[0]->subject->remoteId)->toBe('page-1_post-1')
        ->and($events->messages[0]->subject->text)->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'page-1_post-1'));
});

test('a webhook reply keeps its parent', function () {
    Http::fake();

    $events = $this->driver->webhookEvents($this->account, facebookFeedValue(['comment_id' => 'post-1_c2', 'parent_id' => 'post-1_c1']));

    expect($events->messages[0]->platformParentId)->toBe('post-1_c1');
});

test('remove and delete are removals', function (string $verb) {
    Http::preventStrayRequests();

    $events = $this->driver->webhookEvents($this->account, facebookFeedValue(['verb' => $verb]));

    expect($events->removedIds)->toBe(['post-1_c1'])
        ->and($events->messages)->toBe([]);
})->with(['remove', 'delete']);

test('hide and unhide only move the hidden state, never the message', function (string $verb, array $hidden, array $shown) {
    Http::preventStrayRequests();

    $events = $this->driver->webhookEvents($this->account, facebookFeedValue(['verb' => $verb, 'from' => ['id' => 'page-1', 'name' => 'Acme']]));

    expect($events->hiddenIds)->toBe($hidden)
        ->and($events->shownIds)->toBe($shown)
        ->and($events->messages)->toBe([])
        ->and($events->removedIds)->toBe([]);
})->with([
    'hide' => ['hide', ['post-1_c1'], []],
    'unhide' => ['unhide', [], ['post-1_c1']],
]);

test('likes are ignored', function () {
    Http::preventStrayRequests();

    $events = $this->driver->webhookEvents($this->account, facebookFeedValue(['item' => 'like']));

    expect($events->messages)->toBe([])
        ->and($events->removedIds)->toBe([])
        ->and($events->hiddenIds)->toBe([]);
});

test('comments by the page are outbound', function () {
    Http::fake();

    $events = $this->driver->webhookEvents($this->account, facebookFeedValue(['from' => ['id' => 'page-1', 'name' => 'Acme']]));

    expect($events->messages[0]->direction)->toBe(MessageDirection::Outbound);
});

test('a webhook comment reads only its author picture, once per author', function () {
    UpsertInboxMessage::execute($this->account, facebookComment('post-1_c0'));
    Http::preventStrayRequests();
    Http::fake(["{$this->graph}/post-1_c1*" => Http::response(['from' => ['id' => 'user-9', 'picture' => ['data' => ['is_silhouette' => false, 'url' => 'https://lookaside.example/ana.jpg']]], 'id' => 'post-1_c1'])]);

    $first = $this->driver->webhookEvents($this->account, facebookFeedValue())->messages;
    $second = $this->driver->webhookEvents($this->account, facebookFeedValue(['comment_id' => 'post-1_c1', 'verb' => 'edited']))->messages;

    expect($first[0]->authorAvatarUrl)->toBe('https://lookaside.example/ana.jpg')
        ->and($second[0]->authorAvatarUrl)->toBe('https://lookaside.example/ana.jpg');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'from{picture.type(large)}'));
});

test('a webhook comment whose author picture cannot be read still lands', function () {
    Http::fake(["{$this->graph}/*" => Http::response(['error' => ['code' => 100, 'error_subcode' => 33, 'message' => 'Unsupported get request']], 400)]);

    $messages = $this->driver->webhookEvents($this->account, facebookFeedValue())->messages;

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->authorName)->toBe('Ana Fan')
        ->and($messages[0]->authorAvatarUrl)->toBeNull();
});

test('a reply goes to the top-level comment', function () {
    UpsertInboxMessage::execute($this->account, facebookComment('post-1_c1'));
    $child = UpsertInboxMessage::execute($this->account, facebookComment('post-1_c2', 'post-1_c1'));
    Http::fake(["{$this->graph}/post-1_c1/comments" => Http::response(['id' => 'post-1_c3'])]);

    $sent = $this->driver->reply($this->account, $child, 'thanks');

    Http::assertSent(fn (Request $request): bool => $request->url() === "{$this->graph}/post-1_c1/comments" && $request['message'] === 'thanks');
    expect($sent->platformMessageId)->toBe('post-1_c3');
});

test('subscribing installs the app on the page for feed and mentions', function () {
    Http::fake(["{$this->graph}/page-1/subscribed_apps*" => Http::response(['success' => true])]);

    expect($this->driver->subscribeWebhooks($this->account))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === "{$this->graph}/page-1/subscribed_apps"
        && $request['subscribed_fields'] === 'feed,mention'
        && $request['access_token'] === $this->account->access_token);
});

test('a refused page subscription throws', function () {
    Http::fake(["{$this->graph}/page-1/subscribed_apps*" => Http::response(['success' => false])]);

    $this->driver->subscribeWebhooks($this->account);
})->throws(FacebookPublishException::class);

test('edited updates the body', function () {
    Http::fake();
    UpsertInboxMessages::execute($this->account, $this->driver->webhookEvents($this->account, facebookFeedValue())->messages);

    UpsertInboxMessages::execute($this->account, $this->driver->webhookEvents($this->account, facebookFeedValue(['verb' => 'edited', 'message' => 'Great news, edited']))->messages);

    expect(InboxMessage::query()->count())->toBe(1)
        ->and(InboxMessage::query()->value('body'))->toBe('Great news, edited');
});

test('mentions are not prefilled on facebook', function () {
    expect($this->driver->prefillsMention())->toBeFalse()
        ->and($this->driver->replyLength($this->account))->toBe($this->account->maxContentLength());
});

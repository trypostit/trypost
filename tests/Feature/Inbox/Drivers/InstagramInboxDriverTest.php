<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\InstagramPublishException;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\InstagramInboxDriver;
use App\Services\Inbox\InboxDrivers;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    $this->ig = config('trypost.platforms.instagram.graph_api');
    $this->fb = config('trypost.platforms.instagram-facebook.graph_api');
    $this->account = SocialAccount::factory()->instagram()->withInboxScopes()->create([
        'platform_user_id' => 'app-scoped-1',
        'username' => 'brand',
        'token_expires_at' => now()->addDays(30),
        'meta' => ['ig_user_id' => '17841400000000001'],
    ]);
    $this->driver = app(InstagramInboxDriver::class);
});

function instagramMediaPage(): array
{
    return ['data' => [[
        'id' => '18000000000000001',
        'caption' => 'Launch day',
        'media_type' => 'IMAGE',
        'media_url' => 'https://cdn.example/1.jpg',
        'permalink' => 'https://www.instagram.com/p/abc/',
        'timestamp' => now()->subDays(2)->toIso8601String(),
    ]]];
}

/** @return array<string, mixed> */
function instagramWebhookValue(array $overrides = []): array
{
    $value = data_get(json_decode((string) file_get_contents(base_path('tests/fixtures/inbox/instagram-comment.json')), true), 'entry.0.changes.0.value');

    return array_replace_recursive($value, $overrides);
}

function instagramKnownSubject(SocialAccount $account): void
{
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => '18000000000000001']);
    UpsertInboxMessage::execute($account, new IncomingMessage(
        kind: ConversationKind::Comment,
        subject: new IncomingSubject('18000000000000001', 'Launch day', [], 'https://www.instagram.com/p/abc/', null, '18000000000000001'),
        platformMessageId: 'earlier',
        platformParentId: null,
        direction: MessageDirection::Inbound,
        authorPlatformId: '1',
        authorName: 'someone',
        authorUsername: 'someone',
        authorAvatarUrl: null,
        body: 'First',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse('2026-10-15 10:00:00'),
    ));
}

function instagramComment(string $id, ?string $parent = null): IncomingMessage
{
    return new IncomingMessage(
        kind: ConversationKind::Comment,
        subject: new IncomingSubject('18000000000000001', 'Launch day', [], 'https://www.instagram.com/p/abc/', null, '18000000000000001'),
        platformMessageId: $id,
        platformParentId: $parent,
        direction: MessageDirection::Inbound,
        authorPlatformId: '999',
        authorName: 'fan',
        authorUsername: 'fan',
        authorAvatarUrl: null,
        body: 'Nice',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse("2026-10-15 10:0{$id[1]}:00"),
    );
}

test('both instagram connection methods use the instagram driver', function () {
    expect(app(InboxDrivers::class)->for(Platform::Instagram))->toBeInstanceOf(InstagramInboxDriver::class)
        ->and(app(InboxDrivers::class)->for(Platform::InstagramFacebook))->toBeInstanceOf(InstagramInboxDriver::class);
});

test('poll reads every recent post with its replies under the top-level comment', function () {
    Http::fake([
        "{$this->ig}/app-scoped-1/media*" => Http::response(instagramMediaPage()),
        "{$this->ig}/app-scoped-1/stories*" => Http::response(['data' => []]),
        "{$this->ig}/18000000000000001/comments*" => Http::response(['data' => [[
            'id' => 'c1', 'text' => 'Nice', 'timestamp' => now()->subDay()->toIso8601String(),
            'from' => ['id' => '999', 'username' => 'fan'], 'username' => 'fan',
            'replies' => ['data' => [[
                'id' => 'c2', 'text' => '@fan thanks', 'timestamp' => now()->subHours(20)->toIso8601String(),
                'from' => ['id' => '17841400000000001', 'username' => 'brand'], 'username' => 'brand', 'parent_id' => 'c1',
            ]]],
        ]]]),
    ]);

    $messages = collect($this->driver->poll($this->account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable())->messages);

    expect($messages->pluck('platformMessageId')->all())->toBe(['c1', 'c2'])
        ->and($messages->firstWhere('platformMessageId', 'c1')->direction)->toBe(MessageDirection::Inbound)
        ->and($messages->firstWhere('platformMessageId', 'c1')->authorUsername)->toBe('fan')
        ->and($messages->firstWhere('platformMessageId', 'c2')->platformParentId)->toBe('c1')
        ->and($messages->firstWhere('platformMessageId', 'c2')->direction)->toBe(MessageDirection::Outbound)
        ->and($messages->first()->subject->text)->toBe('Launch day')
        ->and($messages->first()->subject->url)->toBe('https://www.instagram.com/p/abc/');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), "{$this->ig}/18000000000000001/comments")
        && (int) data_get($request->data(), 'limit') === 50
        && str_contains((string) data_get($request->data(), 'fields'), 'replies.limit(50){id,text'));
});

test('a reply listed both at the top and under its comment is one outbound reply', function () {
    Http::fake([
        "{$this->ig}/app-scoped-1/media*" => Http::response(instagramMediaPage()),
        "{$this->ig}/app-scoped-1/stories*" => Http::response(['data' => []]),
        "{$this->ig}/18000000000000001/comments*" => Http::response(['data' => [
            [
                'id' => 'c1', 'text' => 'Nice', 'timestamp' => now()->subDay()->toIso8601String(),
                'from' => ['id' => '999', 'username' => 'fan'], 'username' => 'fan',
                'replies' => ['data' => [[
                    'id' => 'c2', 'text' => '@fan thanks', 'timestamp' => now()->subHours(20)->toIso8601String(),
                ]]],
            ],
            [
                'id' => 'c2', 'text' => '@fan thanks', 'timestamp' => now()->subHours(20)->toIso8601String(),
                'from' => ['id' => '17841400000000001', 'username' => 'brand'], 'username' => 'brand',
            ],
        ]]),
    ]);

    $messages = collect($this->driver->poll($this->account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable())->messages);
    $reply = $messages->firstWhere('platformMessageId', 'c2');

    expect($messages->where('platformMessageId', 'c2'))->toHaveCount(1)
        ->and($reply->platformParentId)->toBe('c1')
        ->and($reply->direction)->toBe(MessageDirection::Outbound)
        ->and($reply->authorUsername)->toBe('brand');
});

test('poll follows the next comments page', function () {
    Http::fake([
        "{$this->ig}/app-scoped-1/media*" => Http::response(instagramMediaPage()),
        "{$this->ig}/app-scoped-1/stories*" => Http::response(['data' => []]),
        "{$this->ig}/18000000000000001/comments*" => Http::sequence()
            ->push(['data' => [['id' => 'c1', 'text' => 'One', 'timestamp' => now()->subDay()->toIso8601String(), 'from' => ['id' => '9', 'username' => 'fan']]], 'paging' => ['cursors' => ['after' => 'next-1'], 'next' => 'https://graph/next']])
            ->push(['data' => [['id' => 'c3', 'text' => 'Two', 'timestamp' => now()->subDay()->toIso8601String(), 'from' => ['id' => '9', 'username' => 'fan']]]]),
    ]);

    $messages = $this->driver->poll($this->account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable())->messages;

    expect(collect($messages)->pluck('platformMessageId')->all())->toBe(['c1', 'c3']);
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'after') === 'next-1');
});

test('poll pages the replies of a comment with more than one page of them', function () {
    Http::fake([
        "{$this->ig}/app-scoped-1/media*" => Http::response(instagramMediaPage()),
        "{$this->ig}/app-scoped-1/stories*" => Http::response(['data' => []]),
        "{$this->ig}/18000000000000001/comments*" => Http::response(['data' => [[
            'id' => 'c1', 'text' => 'Nice', 'timestamp' => now()->subDay()->toIso8601String(), 'from' => ['id' => '9', 'username' => 'fan'],
            'replies' => [
                'data' => [['id' => 'r1', 'text' => 'One', 'timestamp' => now()->subHours(20)->toIso8601String(), 'from' => ['id' => '8', 'username' => 'other']]],
                'paging' => ['cursors' => ['after' => 'replies-1'], 'next' => 'https://graph/replies-next'],
            ],
        ]]]),
        "{$this->ig}/c1/replies*" => Http::response(['data' => [
            ['id' => 'r51', 'text' => 'Fifty-one', 'timestamp' => now()->subHours(19)->toIso8601String(), 'from' => ['id' => '7', 'username' => 'late']],
        ]]),
    ]);

    $messages = collect($this->driver->poll($this->account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable())->messages);

    expect($messages->pluck('platformMessageId')->all())->toBe(['c1', 'r1', 'r51'])
        ->and($messages->firstWhere('platformMessageId', 'r51')->platformParentId)->toBe('c1');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), "{$this->ig}/c1/replies")
        && data_get($request->data(), 'after') === 'replies-1');
});

test('a hidden comment is not a removed one', function () {
    Http::fake([
        "{$this->ig}/app-scoped-1/media*" => Http::response(instagramMediaPage()),
        "{$this->ig}/app-scoped-1/stories*" => Http::response(['data' => []]),
        "{$this->ig}/18000000000000001/comments*" => Http::response(['data' => [[
            'id' => 'c1', 'text' => 'Spam', 'timestamp' => now()->subDay()->toIso8601String(),
            'from' => ['id' => '999', 'username' => 'fan'], 'hidden' => true,
        ]]]),
    ]);

    $messages = $this->driver->poll($this->account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable())->messages;

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->removed)->toBeFalse()
        ->and($messages[0]->hidden)->toBeTrue();
});

test('a webhook comment on an unknown post checks its owner and leaves the post to its import', function () {
    Http::fake(["{$this->ig}/18000000000000001*" => Http::response(['id' => '18000000000000001', 'owner' => ['id' => '17841400000000001'], 'caption' => 'Launch day', 'permalink' => 'https://www.instagram.com/p/abc/', 'timestamp' => '2026-10-14T10:00:00+0000'])]);

    $events = $this->driver->webhookEvents($this->account, instagramWebhookValue(), 1760600000);

    expect($events->messages)->toHaveCount(1)
        ->and($events->removedIds)->toBe([])
        ->and($events->messages[0]->platformMessageId)->toBe('17900000000000001')
        ->and($events->messages[0]->platformParentId)->toBeNull()
        ->and($events->messages[0]->direction)->toBe(MessageDirection::Inbound)
        ->and($events->messages[0]->authorUsername)->toBe('fan.account')
        ->and($events->messages[0]->body)->toBe('Love this!')
        ->and($events->messages[0]->platformCreatedAt->getTimestamp())->toBe(1760600000)
        ->and($events->messages[0]->subject->platformId)->toBe('18000000000000001')
        ->and($events->messages[0]->subject->remoteId)->toBe('18000000000000001')
        ->and($events->messages[0]->subject->text)->toBeNull();
    Http::assertSentCount(1);
});

test('a webhook comment never reads its post, even when the post read would fail', function () {
    Http::fake(["{$this->ig}/18000000000000001*" => Http::sequence()
        ->push(['id' => '18000000000000001', 'owner' => ['id' => '17841400000000001']])
        ->push(['error' => ['code' => 100]], 400)]);

    $events = $this->driver->webhookEvents($this->account, instagramWebhookValue(), 1760600000);

    expect($events->messages)->toHaveCount(1)
        ->and($events->messages[0]->subject->platformId)->toBe('18000000000000001')
        ->and($events->messages[0]->subject->text)->toBeNull();
    Http::assertSentCount(1);
});

test('a webhook comment on a known post makes no request', function () {
    instagramKnownSubject($this->account);
    Http::preventStrayRequests();
    Http::fake();

    $events = $this->driver->webhookEvents($this->account, instagramWebhookValue(), 1760600000);

    expect($events->messages)->toHaveCount(1);
    Http::assertNothingSent();
});

test('a webhook reply keeps its top-level parent', function () {
    instagramKnownSubject($this->account);

    $events = $this->driver->webhookEvents($this->account, instagramWebhookValue(['parent_id' => 'earlier']), 1760600000);

    expect($events->messages[0]->platformParentId)->toBe('earlier');
});

test('own comments from the webhook are outbound', function () {
    instagramKnownSubject($this->account);

    $events = $this->driver->webhookEvents($this->account, instagramWebhookValue(['from' => ['id' => '17841400000000001', 'username' => 'brand']]), 1760600000);

    expect($events->messages[0]->direction)->toBe(MessageDirection::Outbound);
});

test('without a stored professional account id the username decides', function () {
    $this->account->forceFill(['meta' => []])->save();
    instagramKnownSubject($this->account);

    $events = $this->driver->webhookEvents($this->account, instagramWebhookValue(['from' => ['id' => 'x', 'username' => 'brand']]), 1760600000);

    expect($events->messages[0]->direction)->toBe(MessageDirection::Outbound);
});

test('a webhook without a media id is ignored', function () {
    Http::preventStrayRequests();

    expect($this->driver->webhookEvents($this->account, ['id' => 'c1', 'text' => 'x'], 1760600000)->messages)->toBe([]);
});

test('a reply to a nested reply goes to the top-level comment', function () {
    instagramKnownSubject($this->account);
    $root = UpsertInboxMessage::execute($this->account, instagramComment('c1'));
    $child = UpsertInboxMessage::execute($this->account, instagramComment('c2', 'c1'));
    Http::fake(["{$this->ig}/c1/replies" => Http::response(['id' => 'c3'])]);

    $sent = $this->driver->reply($this->account, $child, 'thanks');

    Http::assertSent(fn (Request $request): bool => $request->url() === "{$this->ig}/c1/replies" && $request['message'] === 'thanks');
    expect($sent->platformMessageId)->toBe('c3')
        ->and($root->id)->toBe($child->root_id);
});

test('facebook login reads and replies on graph.facebook.com and subscribes through the page', function () {
    $account = SocialAccount::factory()->instagramFacebook()->withInboxScopes()->create([
        'platform_user_id' => '17841400000000002',
        'token_expires_at' => null,
        'meta' => ['page_id' => 'page-1'],
    ]);
    $root = UpsertInboxMessage::execute($account, instagramComment('c1'));
    Http::fake([
        config('trypost.platforms.facebook.graph_api').'/page-1/subscribed_apps*' => Http::response(['success' => true]),
        "{$this->fb}/c1/replies" => Http::response(['id' => 'c9']),
    ]);

    expect($this->driver->subscribeWebhooks($account))->toBeTrue()
        ->and($this->driver->reply($account, $root, 'hi')->platformMessageId)->toBe('c9');

    Http::assertSent(fn (Request $request): bool => $request->url() === config('trypost.platforms.facebook.graph_api').'/page-1/subscribed_apps' && $request['subscribed_fields'] === 'feed');
    Http::assertSent(fn (Request $request): bool => $request->url() === "{$this->fb}/c1/replies");
});

test('instagram login subscribes comments on me', function () {
    Http::fake(["{$this->ig}/me/subscribed_apps" => Http::response(['success' => true])]);

    expect($this->driver->subscribeWebhooks($this->account))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === "{$this->ig}/me/subscribed_apps"
        && $request['subscribed_fields'] === 'comments,live_comments'
        && $request['access_token'] === $this->account->access_token);
});

test('a refused subscription throws', function (array $body, int $status) {
    Http::fake(["{$this->ig}/me/subscribed_apps" => Http::response($body, $status)]);

    $this->driver->subscribeWebhooks($this->account);
})->with([
    'success false' => [['success' => false], 200],
    'rejected' => [['error' => ['code' => 100, 'message' => 'bad']], 400],
])->throws(InstagramPublishException::class);

test('the reply limit is the account limit and mentions are prefilled', function () {
    expect($this->driver->replyLength($this->account))->toBe($this->account->maxContentLength())
        ->and($this->driver->prefillsMention())->toBeTrue()
        ->and($this->driver->kinds())->toBe([ConversationKind::Comment, ConversationKind::Mention, ConversationKind::DirectMessage]);
});

test('a channel connected through facebook shows a professional commenter picture, asked once per person', function () {
    $account = SocialAccount::factory()->instagramFacebook()->withInboxScopes()->create(['platform_user_id' => '17841400000000002', 'username' => 'brand', 'token_expires_at' => null, 'meta' => ['page_id' => 'page-1']]);
    instagramKnownSubject($account);
    Http::preventStrayRequests();
    Http::fake(["{$this->fb}/17841400000000002*" => Http::sequence()
        ->push(['business_discovery' => ['profile_picture_url' => 'https://cdn.example/fan.jpg', 'id' => '1']])
        ->push(['error' => ['code' => 110, 'error_subcode' => 2207013, 'message' => 'Invalid user id']], 400)]);

    $fan = $this->driver->webhookEvents($account, instagramWebhookValue(), 1760600000)->messages[0];
    $again = $this->driver->webhookEvents($account, instagramWebhookValue(['id' => '17900000000000002']), 1760600000)->messages[0];
    $personal = $this->driver->webhookEvents($account, instagramWebhookValue(['id' => '17900000000000003', 'from' => ['id' => '17841400000000077', 'username' => 'personal.one']]), 1760600000)->messages[0];
    $this->driver->webhookEvents($account, instagramWebhookValue(['id' => '17900000000000004', 'from' => ['id' => '17841400000000077', 'username' => 'personal.one']]), 1760600000);

    expect($fan->authorAvatarUrl)->toBe('https://cdn.example/fan.jpg')
        ->and($again->authorAvatarUrl)->toBe('https://cdn.example/fan.jpg')
        ->and($personal->authorAvatarUrl)->toBeNull()
        ->and($personal->authorName)->toBe('personal.one');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'business_discovery.username(fan.account){profile_picture_url}'));
});

test('instagram login never asks for a commenter picture', function () {
    instagramKnownSubject($this->account);
    Http::preventStrayRequests();
    Http::fake();

    $message = $this->driver->webhookEvents($this->account, instagramWebhookValue(), 1760600000)->messages[0];

    expect($message->authorAvatarUrl)->toBeNull();
    Http::assertNothingSent();
});

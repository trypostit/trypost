<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Jobs\Inbox\HandleMetaWebhook;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inbox\Drivers\ThreadsInboxDriver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    Sleep::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00'));
    $this->account = SocialAccount::factory()->threads()->create([
        'platform_user_id' => '78901',
        'username' => 'me',
        'token_expires_at' => now()->addDays(30),
        'scopes' => ['threads_basic', 'threads_read_replies', 'threads_manage_replies', 'threads_manage_mentions'],
    ]);
    $this->graph = config('trypost.platforms.threads.graph_api');
});

/** @return array<string, mixed> */
function threadsMention(string $id, bool $isReply = false, ?string $root = null, ?string $parent = null, ?string $rootOwner = null): array
{
    return array_filter([
        'id' => $id,
        'text' => 'Look @me',
        'username' => 'test_username',
        'permalink' => "https://www.threads.net/@test_username/post/{$id}",
        'timestamp' => '2026-10-16T10:33:16+0000',
        'media_type' => 'TEXT_POST',
        'media_url' => null,
        'is_reply' => $isReply,
        'root_post' => $root ? array_filter(['id' => $root, 'owner_id' => $rootOwner]) : null,
        'replied_to' => $parent ? ['id' => $parent] : null,
    ], fn (mixed $value): bool => $value !== null);
}

/** @return array<string, mixed> */
function threadsWebhook(string $field, array $value, string $targetId = '78901'): array
{
    return [
        'app_id' => '123456', 'topic' => $field === 'mentions' ? 'interaction' : 'moderate', 'target_id' => $targetId, 'time' => 1760600000,
        'subscription_id' => '234567', 'has_uid_field' => false,
        'values' => ['field' => $field, 'value' => $value],
    ];
}

function storedThreadsMention(SocialAccount $account, string $id): void
{
    UpsertInboxMessage::execute($account, new IncomingMessage(
        kind: ConversationKind::Mention,
        subject: new IncomingSubject($id, 'Look @me', [], "https://www.threads.net/@test_username/post/{$id}", CarbonImmutable::parse('2026-10-16'), null, [], 'test_username', 'test_username'),
        platformMessageId: $id,
        platformParentId: null,
        direction: MessageDirection::Inbound,
        authorPlatformId: null,
        authorName: 'test_username',
        authorUsername: 'test_username',
        authorAvatarUrl: null,
        body: 'Look @me',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse('2026-10-16 10:00:00'),
    ));
}

test('the mentions edge is polled from the watermark', function () {
    Http::fake(["{$this->graph}/78901/mentions*" => Http::response(['data' => [threadsMention('8901234')], 'paging' => ['cursors' => ['after' => 'a1']]])]);

    $result = app(ThreadsInboxDriver::class)->poll($this->account, ConversationKind::Mention, '1760000000', CarbonImmutable::parse('2026-09-16'));
    $message = $result->messages[0];

    expect($result->messages)->toHaveCount(1)
        ->and($message->kind)->toBe(ConversationKind::Mention)
        ->and($message->subject->platformId)->toBe('8901234')
        ->and($message->subject->text)->toBe('Look @me')
        ->and($message->subject->participantUsername)->toBe('test_username')
        ->and($message->platformParentId)->toBeNull()
        ->and($message->platformUrl)->toBe('https://www.threads.net/@test_username/post/8901234')
        ->and($result->cursor)->toBe((string) CarbonImmutable::parse('2026-10-16T10:33:16+0000')->getTimestamp());
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/78901/mentions')
        && (int) $request['since'] === 1760000000
        && (int) $request['until'] === now()->getTimestamp()
        && str_contains((string) $request['fields'], 'permalink'));
});

test('a backfill starts at the since date and never before the edge minimum', function () {
    Http::fake(["{$this->graph}/78901/mentions*" => Http::response(['data' => []])]);

    $result = app(ThreadsInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, CarbonImmutable::parse('2020-01-01'));

    Http::assertSent(fn (Request $request): bool => (int) $request['since'] === 1688540400);
    expect($result->cursor)->toBeNull();
});

test('a polled mention in a reply under the channel own post is left to comments', function () {
    InboxConversation::factory()->create([
        'social_account_id' => $this->account->id,
        'platform_conversation_id' => 'own-root', 'platform_conversation_hash' => InboxConversation::hashOf('own-root'),
    ]);
    Http::fake(["{$this->graph}/78901/mentions*" => Http::response(['data' => [threadsMention('m-own', true, 'own-root', 'own-root')]])]);

    expect(app(ThreadsInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, CarbonImmutable::parse('2026-09-16'))->messages)->toBe([]);
});

test('the mentions webhook field becomes a mention of the mentioned channel', function () {
    Http::preventStrayRequests();

    HandleMetaWebhook::dispatchSync(threadsWebhook('mentions', threadsMention('8901234')));

    $conversation = InboxConversation::query()->sole();

    expect($conversation->kind)->toBe(ConversationKind::Mention)
        ->and($conversation->social_account_id)->toBe($this->account->id)
        ->and($conversation->participant_username)->toBe('test_username')
        ->and(InboxMessage::query()->sole()->body)->toBe('Look @me')
        ->and(HandleMetaWebhook::isRelevant(threadsWebhook('mentions', threadsMention('1'))))->toBeTrue();
});

test('a mentions webhook is dropped while the inbox is off or the scope is missing', function () {
    config()->set('trypost.inbox.enabled', false);
    HandleMetaWebhook::dispatchSync(threadsWebhook('mentions', threadsMention('8901234')));

    config()->set('trypost.inbox.enabled', true);
    $this->account->update(['scopes' => ['threads_basic', 'threads_read_replies', 'threads_manage_replies']]);
    HandleMetaWebhook::dispatchSync(threadsWebhook('mentions', threadsMention('8901235')));

    expect(InboxMessage::query()->count())->toBe(0);
});

test('a mention webhook on the channel own post is left to the replies field', function () {
    HandleMetaWebhook::dispatchSync(threadsWebhook('mentions', threadsMention('m-own', true, 'own-root', 'own-root', '78901')));

    expect(InboxMessage::query()->count())->toBe(0);
});

test('a reply under the channel answer on a foreign post joins the mention', function () {
    storedThreadsMention($this->account, '8901234');
    UpsertInboxMessage::execute($this->account, new IncomingMessage(
        kind: ConversationKind::Mention,
        subject: new IncomingSubject('8901234', null, [], null, null, null),
        platformMessageId: '555',
        platformParentId: '8901234',
        direction: MessageDirection::Outbound,
        authorPlatformId: null,
        authorName: 'me',
        authorUsername: 'me',
        authorAvatarUrl: null,
        body: 'Thanks',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse('2026-10-16 11:00:00'),
    ));
    Http::preventStrayRequests();

    HandleMetaWebhook::dispatchSync(threadsWebhook('replies', [
        ...threadsMention('999', true, 'foreign-root', '555', 'someone-else'),
        'username' => 'fan',
    ], '999'));

    $late = InboxMessage::query()->where('platform_message_id', '999')->sole();

    expect($late->parent_id)->toBe(InboxMessage::query()->where('platform_message_id', '555')->value('id'))
        ->and($late->conversation->kind)->toBe(ConversationKind::Mention)
        ->and(InboxConversation::query()->count())->toBe(1);
});

test('a reply on a foreign post under nothing stored is ignored', function () {
    HandleMetaWebhook::dispatchSync(threadsWebhook('replies', threadsMention('999', true, 'foreign-root', '555', 'someone-else'), '999'));

    expect(InboxMessage::query()->count())->toBe(0);
});

test('replying to a mention uses reply_to_id', function () {
    storedThreadsMention($this->account, '8901234');
    Http::fake([
        "{$this->graph}/78901/threads_publishing_limit*" => Http::response(['data' => [['reply_quota_usage' => 1, 'reply_config' => ['quota_total' => 1000, 'quota_duration' => 86400]]]]),
        "{$this->graph}/78901/threads" => Http::response(['id' => 'container-1']),
        "{$this->graph}/container-1*" => Http::response(['status' => 'FINISHED']),
        "{$this->graph}/78901/threads_publish" => Http::response(['id' => 'r1']),
        "{$this->graph}/r1*" => Http::response(['permalink' => 'https://www.threads.net/@me/post/r1']),
    ]);

    $sent = app(ThreadsInboxDriver::class)->reply($this->account, InboxMessage::query()->sole(), 'Thanks!');

    expect($sent->platformMessageId)->toBe('r1');
    Http::assertSent(fn (Request $request): bool => str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/78901/threads')
        && $request['reply_to_id'] === '8901234'
        && $request['media_type'] === 'TEXT');
});

test('the connect flow asks for threads_manage_mentions while the inbox is on', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    config()->set('trypost.inbox.enabled', false);

    $without = urldecode((string) $this->actingAs($user)->get(route('app.social.threads.connect'))->headers->get('Location'));

    config()->set('trypost.inbox.enabled', true);

    $with = urldecode((string) $this->actingAs($user)->get(route('app.social.threads.connect'))->headers->get('Location'));

    expect($without)->not->toContain('threads_read_replies')->not->toContain('threads_manage_mentions')
        ->and($with)->toContain('threads_read_replies')->toContain('threads_manage_mentions');
});

test('a mention answering a stored message keeps it as parent on the webhook and the poll', function () {
    storedThreadsMention($this->account, '8901234');
    $value = threadsMention('m-reply', true, 'foreign-root', '8901234', 'someone-else');
    Http::fake(["{$this->graph}/78901/mentions*" => Http::response(['data' => [$value]])]);

    $polled = app(ThreadsInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, CarbonImmutable::parse('2026-09-16'))->messages[0];
    $pushed = app(ThreadsInboxDriver::class)->mentionWebhookEvents($this->account, $value)->messages[0];

    expect($polled->platformParentId)->toBe('8901234')
        ->and($pushed->platformParentId)->toBe('8901234');

    HandleMetaWebhook::dispatchSync(threadsWebhook('mentions', $value));

    expect(InboxMessage::query()->where('platform_message_id', 'm-reply')->sole()->parent_id)
        ->toBe(InboxMessage::query()->where('platform_message_id', '8901234')->value('id'))
        ->and(InboxConversation::query()->count())->toBe(1);
});

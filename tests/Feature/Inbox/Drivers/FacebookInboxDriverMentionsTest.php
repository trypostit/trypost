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
use App\Services\Inbox\Drivers\FacebookInboxDriver;
use App\Services\Inbox\PollBudget;
use App\Support\Inbox\InboxMediaSlots;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    $this->graph = config('trypost.platforms.facebook.graph_api');
    $this->account = SocialAccount::factory()->facebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    $this->driver = app(FacebookInboxDriver::class);
});

/** @param array<string, mixed> $value */
function facebookMentionWebhook(array $value): void
{
    HandleMetaWebhook::dispatchSync(['object' => 'page', 'entry' => [['id' => 'page-1', 'time' => 1760600000, 'changes' => [['field' => 'mention', 'value' => [
        'post_id' => 'user-9_post-7',
        'item' => 'post',
        'verb' => 'add',
        'created_time' => 1760600000,
        ...$value,
    ]]]]]]);
}

/** @return array<string, mixed> */
function facebookMentioningPost(array $overrides = []): array
{
    return [
        'id' => 'user-9_post-7',
        'message' => 'Loving @Acme today',
        'from' => ['id' => 'user-9', 'name' => 'Ana Fan', 'picture' => ['data' => ['is_silhouette' => false, 'url' => 'https://lookaside.example/ana.jpg']]],
        'created_time' => '2026-10-16T10:00:00+0000',
        'permalink_url' => 'https://www.facebook.com/user-9/posts/7',
        'full_picture' => 'https://cdn.example/p.jpg',
        ...$overrides,
    ];
}

test('a post mention fetches the mentioning post', function () {
    Http::fake(["{$this->graph}/user-9_post-7*" => Http::response(facebookMentioningPost())]);

    facebookMentionWebhook([]);

    $conversation = InboxConversation::query()->sole();
    $message = InboxMessage::query()->sole();

    expect($conversation->kind)->toBe(ConversationKind::Mention)
        ->and($conversation->platform_conversation_id)->toBe('user-9_post-7')
        ->and($conversation->participant_name)->toBe('Ana Fan')
        ->and($conversation->subject_text)->toBe('Loving @Acme today')
        ->and($conversation->subject_url)->toBe('https://www.facebook.com/user-9/posts/7')
        ->and($message->platform_message_id)->toBe('user-9_post-7')
        ->and($message->body)->toBe('Loving @Acme today')
        ->and($message->author_name)->toBe('Ana Fan')
        ->and($message->author_platform_id)->toBe('user-9')
        ->and(collect(data_get($message->meta, InboxMediaSlots::PENDING))->values()->all())->toContain('https://lookaside.example/ana.jpg')
        ->and($message->direction)->toBe(MessageDirection::Inbound)
        ->and($message->platform_url)->toBe('https://www.facebook.com/user-9/posts/7')
        ->and(collect($message->meta)->except(InboxMediaSlots::PENDING)->all())->toEqual(['post_id' => 'user-9_post-7']);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), "{$this->graph}/user-9_post-7")
        && str_contains(urldecode($request->url()), 'permalink_url'));
});

test('a comment mention lands under the mentioning post', function () {
    Http::fake([
        "{$this->graph}/user-9_post-7*" => Http::response(facebookMentioningPost(['message' => 'Weekend plans'])),
        "{$this->graph}/post-7_c1*" => Http::response([
            'id' => 'post-7_c1', 'message' => 'Ask @Acme', 'from' => ['id' => 'user-5', 'name' => 'Bia'],
            'created_time' => '2026-10-16T11:00:00+0000', 'permalink_url' => 'https://www.facebook.com/c1', 'parent' => ['id' => 'user-9_post-7'],
        ]),
    ]);

    facebookMentionWebhook(['item' => 'comment', 'comment_id' => 'post-7_c1']);

    $conversation = InboxConversation::query()->sole();
    $message = InboxMessage::query()->sole();

    expect($conversation->kind)->toBe(ConversationKind::Mention)
        ->and($conversation->platform_conversation_id)->toBe('user-9_post-7')
        ->and($conversation->subject_text)->toBe('Weekend plans')
        ->and($message->platform_message_id)->toBe('post-7_c1')
        ->and($message->platform_parent_id)->toBeNull()
        ->and($message->body)->toBe('Ask @Acme')
        ->and($message->author_name)->toBe('Bia')
        ->and($message->platform_url)->toBe('https://www.facebook.com/c1')
        ->and($message->meta)->toEqual(['post_id' => 'user-9_post-7', 'comment_id' => 'post-7_c1']);
});

test('a comment mention in a reply keeps its top-level comment', function () {
    Http::fake([
        "{$this->graph}/user-9_post-7*" => Http::response(facebookMentioningPost()),
        "{$this->graph}/post-7_c2*" => Http::response([
            'id' => 'post-7_c2', 'message' => '@Acme?', 'from' => ['id' => 'user-5', 'name' => 'Bia'],
            'created_time' => '2026-10-16T11:00:00+0000', 'parent' => ['id' => 'post-7_c1'],
        ]),
    ]);

    facebookMentionWebhook(['item' => 'comment', 'comment_id' => 'post-7_c2']);

    expect(InboxMessage::query()->sole()->meta)->toEqual(['post_id' => 'user-9_post-7', 'comment_id' => 'post-7_c2', 'top_level_comment_id' => 'post-7_c1']);
});

test('removing, deleting or hiding a mention removes it', function (string $verb) {
    Http::fake(["{$this->graph}/user-9_post-7*" => Http::response(facebookMentioningPost())]);
    facebookMentionWebhook([]);
    Http::preventStrayRequests();

    facebookMentionWebhook(['verb' => $verb]);

    expect(InboxMessage::query()->sole()->removed_at)->not->toBeNull();
})->with(['remove', 'delete', 'hide']);

test('an edited mention updates its body', function () {
    Http::fakeSequence("{$this->graph}/user-9_post-7*")
        ->push(facebookMentioningPost())
        ->push(facebookMentioningPost(['message' => 'Still loving @Acme']));

    facebookMentionWebhook([]);
    facebookMentionWebhook(['verb' => 'edited']);

    expect(InboxMessage::query()->sole()->body)->toBe('Still loving @Acme');
});

test('a mention that cannot be read is dropped', function () {
    Http::fake(["{$this->graph}/*" => Http::response(['error' => ['code' => 10, 'message' => 'no permission']], 400)]);

    facebookMentionWebhook([]);

    expect(InboxMessage::query()->exists())->toBeFalse();
});

test('a post mention on a profile is found on the tagged posts and stored under their id', function () {
    Http::fake([
        "{$this->graph}/page-1/tagged*" => Http::response(['data' => [
            facebookMentioningPost(['id' => 'user-9-real_post-8', 'message' => 'Another post']),
            facebookMentioningPost(['id' => 'user-9-real_post-7', 'tagged_time' => '2026-10-16T10:00:01+0000']),
        ]]),
        "{$this->graph}/*" => Http::response(['error' => ['code' => 10, 'message' => 'requires pages_read_engagement or Page Public Content Access']], 400),
    ]);

    facebookMentionWebhook(['post_id' => 'app-scoped-9_post-7']);

    $message = InboxMessage::query()->sole();

    expect($message->platform_message_id)->toBe('user-9-real_post-7')
        ->and($message->author_name)->toBe('Ana Fan')
        ->and($message->body)->toBe('Loving @Acme today')
        ->and($message->conversation->platform_conversation_id)->toBe('user-9-real_post-7');
});

test('the webhook and the tagged poll store the same mention once', function () {
    Http::fake([
        "{$this->graph}/page-1/tagged*" => Http::response(['data' => [facebookMentioningPost(['id' => 'user-9-real_post-7', 'tagged_time' => now()->subMinute()->toIso8601String()])]]),
        "{$this->graph}/*" => Http::response(['error' => ['code' => 10, 'message' => 'no permission']], 400),
    ]);

    facebookMentionWebhook(['post_id' => 'app-scoped-9_post-7']);
    $polled = $this->driver->poll($this->account, ConversationKind::Mention, null, now()->subDays(30)->toImmutable())->messages;
    collect($polled)->each(fn (IncomingMessage $message) => UpsertInboxMessage::execute($this->account, $message));

    expect(InboxMessage::query()->count())->toBe(1);
});

test('a removal under the app-scoped id removes the mention stored under the tagged id', function () {
    $conversation = InboxConversation::factory()->for($this->account, 'socialAccount')->create(['kind' => ConversationKind::Mention]);
    $mention = InboxMessage::factory()->for($conversation, 'conversation')->create(['platform_message_id' => 'user-9-real_post-7', 'platform_message_hash' => InboxConversation::hashOf('user-9-real_post-7')]);
    $other = InboxMessage::factory()->for($conversation, 'conversation')->create(['platform_message_id' => 'user-9-real_post-77', 'platform_message_hash' => InboxConversation::hashOf('user-9-real_post-77')]);
    Http::preventStrayRequests();

    facebookMentionWebhook(['post_id' => 'app-scoped-9_post-7', 'verb' => 'remove']);

    expect($mention->fresh()->removed_at)->not->toBeNull()
        ->and($other->fresh()->removed_at)->toBeNull();
});

test('a comment mention whose post cannot be read still lands, named by its post', function () {
    Http::fake([
        "{$this->graph}/post-7_c1*" => Http::response([
            'id' => 'post-7_c1', 'message' => 'Ask @Acme', 'from' => ['id' => 'user-5', 'name' => 'Bia'],
            'created_time' => '2026-10-16T11:00:00+0000', 'permalink_url' => 'https://www.facebook.com/c1', 'parent' => ['id' => 'user-9-real_post-7'],
        ]),
        "{$this->graph}/page-1/tagged*" => Http::response(['data' => []]),
        "{$this->graph}/*" => Http::response(['error' => ['code' => 10, 'message' => 'no permission']], 400),
    ]);

    facebookMentionWebhook(['post_id' => 'app-scoped-9_post-7', 'item' => 'comment', 'comment_id' => 'post-7_c1']);

    $message = InboxMessage::query()->sole();

    expect($message->body)->toBe('Ask @Acme')
        ->and($message->author_name)->toBe('Bia')
        ->and($message->platform_parent_id)->toBeNull()
        ->and($message->conversation->platform_conversation_id)->toBe('app-scoped-9_post-7')
        ->and($message->conversation->subject_url)->toBe('https://www.facebook.com/c1');
});

test('a mention on the page own post is left to the feed comments', function () {
    Http::fake([
        "{$this->graph}/page-1_post-1*" => Http::response(facebookMentioningPost(['id' => 'page-1_post-1'])),
        "{$this->graph}/post-1_c1*" => Http::response(['id' => 'post-1_c1', 'message' => '@Acme', 'created_time' => '2026-10-16T11:00:00+0000']),
    ]);

    facebookMentionWebhook(['post_id' => 'page-1_post-1', 'item' => 'comment', 'comment_id' => 'post-1_c1']);

    expect(InboxMessage::query()->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('a mention webhook makes no request while the inbox is off', function () {
    config()->set('trypost.inbox.enabled', false);
    Http::preventStrayRequests();

    facebookMentionWebhook([]);

    expect(InboxMessage::query()->exists())->toBeFalse();
});

test('the mention field is relevant', function () {
    expect(HandleMetaWebhook::isRelevant(['object' => 'page', 'entry' => [['id' => 'page-1', 'changes' => [['field' => 'mention', 'value' => ['post_id' => 'x']]]]]]))->toBeTrue();
});

test('the poll reads tagged posts inside the window', function () {
    $this->freezeTime();
    Http::fake(["{$this->graph}/page-1/tagged*" => Http::response(['data' => [
        facebookMentioningPost(['tagged_time' => now()->subDay()->toIso8601String()]),
        facebookMentioningPost(['id' => 'user-3_post-2', 'tagged_time' => now()->subDays(40)->toIso8601String()]),
    ], 'paging' => ['next' => 'https://next', 'cursors' => ['after' => 'p2']]])]);

    $result = $this->driver->poll($this->account, ConversationKind::Mention, null, now()->subDays(30)->toImmutable());

    expect(collect($result->messages)->pluck('platformMessageId')->all())->toBe(['user-9_post-7'])
        ->and($result->messages[0]->kind)->toBe(ConversationKind::Mention)
        ->and($result->messages[0]->subject->text)->toBe('Loving @Acme today')
        ->and($result->messages[0]->platformCreatedAt->toIso8601String())->toBe(now()->subDay()->toIso8601String())
        ->and($result->partial)->toBeFalse()
        ->and($result->cursor)->toBe(now()->subDay()->toImmutable()->toIso8601String());
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), "{$this->graph}/page-1/tagged")
        && str_contains((string) data_get($request->data(), 'fields'), 'tagged_time'));
});

test('the poll follows pages until the window ends and skips the page own posts', function () {
    $this->freezeTime();
    Http::fakeSequence("{$this->graph}/page-1/tagged*")
        ->push(['data' => [
            facebookMentioningPost(['tagged_time' => now()->subHour()->toIso8601String()]),
            facebookMentioningPost(['id' => 'page-1_post-9', 'tagged_time' => now()->subHours(2)->toIso8601String()]),
        ], 'paging' => ['next' => 'https://next', 'cursors' => ['after' => 'p2']]])
        ->push(['data' => [facebookMentioningPost(['id' => 'user-3_post-2', 'tagged_time' => now()->subHours(3)->toIso8601String()])]]);

    $result = $this->driver->poll($this->account, ConversationKind::Mention, null, now()->subDays(30)->toImmutable());

    expect(collect($result->messages)->pluck('platformMessageId')->all())->toBe(['user-9_post-7', 'user-3_post-2']);
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'after') === 'p2');
});

test('the poll reads at most ten pages', function () {
    $this->freezeTime();
    Http::fake(["{$this->graph}/page-1/tagged*" => Http::response(['data' => [
        facebookMentioningPost(['tagged_time' => now()->subHour()->toIso8601String()]),
    ], 'paging' => ['next' => 'https://next', 'cursors' => ['after' => 'more']]])]);

    $result = $this->driver->poll($this->account, ConversationKind::Mention, null, now()->subDays(30)->toImmutable());

    Http::assertSentCount(10);
    expect($result->partial)->toBeTrue();
});

test('a spent poll budget resumes the tagged pages', function () {
    $this->freezeTime();
    app()->instance(PollBudget::class, new PollBudget(CarbonImmutable::now()->subSecond()));
    Http::fake(["{$this->graph}/page-1/tagged*" => Http::response(['data' => [
        facebookMentioningPost(['tagged_time' => now()->subHour()->toIso8601String()]),
    ], 'paging' => ['next' => 'https://next', 'cursors' => ['after' => 'p2']]])]);

    $result = $this->driver->poll($this->account, ConversationKind::Mention, null, now()->subDays(30)->toImmutable());

    expect($result->partial)->toBeTrue()
        ->and(json_decode((string) $result->cursor, true))->toMatchArray(['after' => 'p2']);
    Http::assertSentCount(1);

    $this->driver->poll($this->account, ConversationKind::Mention, $result->cursor, now()->subDays(30)->toImmutable());

    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'after') === 'p2');
});

test('replying to a post mention comments on the post', function () {
    Http::fake([
        "{$this->graph}/user-9_post-7/comments" => Http::response(['id' => 'post-7_c9']),
        "{$this->graph}/user-9_post-7*" => Http::response(facebookMentioningPost()),
    ]);
    facebookMentionWebhook([]);

    $sent = $this->driver->reply($this->account, InboxMessage::query()->sole(), 'Thanks!');

    expect($sent->platformMessageId)->toBe('post-7_c9');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === "{$this->graph}/user-9_post-7/comments"
        && $request['message'] === 'Thanks!');
});

test('replying to a comment mention answers its top-level comment', function (array $parent, string $target) {
    Http::fake([
        "{$this->graph}/{$target}/comments" => Http::response(['id' => 'reply-1']),
        "{$this->graph}/user-9_post-7*" => Http::response(facebookMentioningPost()),
        "{$this->graph}/post-7_c2*" => Http::response(['id' => 'post-7_c2', 'message' => '@Acme?', 'created_time' => '2026-10-16T11:00:00+0000', 'parent' => $parent]),
    ]);
    facebookMentionWebhook(['item' => 'comment', 'comment_id' => 'post-7_c2']);

    expect($this->driver->reply($this->account, InboxMessage::query()->sole(), 'Hi!')->platformMessageId)->toBe('reply-1');
    Http::assertSent(fn (Request $request): bool => $request->url() === "{$this->graph}/{$target}/comments" && $request['message'] === 'Hi!');
})->with([
    'top-level comment' => [['id' => 'user-9_post-7'], 'post-7_c2'],
    'reply' => [['id' => 'post-7_c1'], 'post-7_c1'],
]);

test('a reply under a stored mention answers the mention', function () {
    Http::fake([
        "{$this->graph}/user-9_post-7/comments" => Http::response(['id' => 'reply-2']),
        "{$this->graph}/user-9_post-7*" => Http::response(facebookMentioningPost()),
    ]);
    facebookMentionWebhook([]);
    $mention = InboxMessage::query()->sole();
    $child = UpsertInboxMessage::execute($this->account, new IncomingMessage(
        kind: ConversationKind::Comment,
        subject: new IncomingSubject('user-9_post-7', null, [], null, null, null),
        platformMessageId: 'post-7_c5',
        platformParentId: 'user-9_post-7',
        direction: MessageDirection::Inbound,
        authorPlatformId: 'user-9',
        authorName: 'Ana Fan',
        authorUsername: null,
        authorAvatarUrl: null,
        body: 'Reply',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::now(),
    ));
    $this->driver->reply($this->account, $child, 'Sure');

    expect($child->inbox_conversation_id)->toBe($mention->inbox_conversation_id);
    Http::assertSent(fn (Request $request): bool => $request->url() === "{$this->graph}/user-9_post-7/comments");
});

test('subscribing adds the mention field', function () {
    Http::fake(["{$this->graph}/page-1/subscribed_apps*" => Http::response(['success' => true])]);

    $this->driver->subscribeWebhooks($this->account);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['subscribed_fields'] === 'feed,mention');
});

test('facebook is a mention network', function () {
    expect($this->driver->kinds())->toContain(ConversationKind::Mention)
        ->and($this->account->platform->inboxKindEnabled(ConversationKind::Mention))->toBeTrue();
});

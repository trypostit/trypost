<?php

declare(strict_types=1);

use App\Actions\Inbox\MarkInboxTreeAnswered;
use App\Actions\Inbox\RecountInboxConversation;
use App\Actions\Inbox\ReplyToInboxMessage;
use App\Actions\Inbox\ResolveInboxMessages;
use App\Actions\Inbox\RetryInboxReply;
use App\Actions\Inbox\UnresolveInboxMessage;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Actions\Inbox\UpsertInboxMessages;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ResolveScope;
use App\Events\InboxUpdated;
use App\Http\Resources\Api\InboxMessageResource;
use App\Jobs\Inbox\SendInboxReply;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\BlueskyInboxDriver;
use App\Services\Inbox\Drivers\FacebookInboxDriver;
use App\Services\Inbox\Drivers\InstagramInboxDriver;
use App\Services\Inbox\Drivers\ThreadsInboxDriver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->conversation = InboxConversation::factory()->create();
    $this->comment = InboxMessage::factory()->for($this->conversation, 'conversation')->create();
    $this->user = $this->conversation->workspace->owner;
});

test('replying stores a sending message and queues it', function () {
    Queue::fake([SendInboxReply::class]);

    $reply = ReplyToInboxMessage::execute($this->comment, $this->user, '  thanks!  ');

    expect($reply->direction)->toBe(MessageDirection::Outbound)
        ->and($reply->body)->toBe('thanks!')
        ->and($reply->delivery_status)->toBe(DeliveryStatus::Sending)
        ->and($reply->parent_id)->toBe($this->comment->id)
        ->and($reply->sent_by_user_id)->toBe($this->user->id);
    Queue::assertPushed(SendInboxReply::class);
});

test('a reply stores no picture and shows the channel\'s current one', function () {
    Queue::fake([SendInboxReply::class]);
    $account = $this->comment->socialAccount;
    $account->forceFill(['avatar_url' => 'social-accounts/me.jpg'])->save();

    $reply = ReplyToInboxMessage::execute($this->comment->fresh(), $this->user, 'thanks!');
    $avatar = fn (): ?string => data_get(InboxMessageResource::make($reply->fresh())->toArray(Request::create('/')), 'author.avatar_url');

    expect($reply->fresh()->author_avatar_path)->toBeNull()
        ->and($avatar())->toBe(Storage::url('social-accounts/me.jpg'));

    $account->forceFill(['avatar_url' => 'social-accounts/new.jpg'])->save();

    expect($avatar())->toBe(Storage::url('social-accounts/new.jpg'));
});

test('a reply the channel wrote on the network stores no picture either', function () {
    UpsertInboxMessage::execute($this->comment->socialAccount, new IncomingMessage(
        kind: ConversationKind::Comment,
        subject: new IncomingSubject($this->conversation->platform_conversation_id, null, [], null, null, null),
        platformMessageId: 'own-reply',
        platformParentId: $this->comment->platform_message_id,
        direction: MessageDirection::Outbound,
        authorPlatformId: 'me',
        authorName: 'Me',
        authorUsername: 'me',
        authorAvatarUrl: 'https://cdn.bsky.app/img/avatar/plain/did:plc:me/bafkreime@jpeg',
        body: 'Thanks',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse('2026-10-16 11:00:00'),
    ));

    $reply = InboxMessage::query()->where('platform_message_id', 'own-reply')->sole();

    expect($reply->author_avatar_path)->toBeNull()
        ->and(data_get($reply->meta, 'pending_media'))->toBeNull()
        ->and(data_get(InboxMessageResource::make($reply)->toArray(request()), 'author.avatar_url'))->toBe($this->comment->socialAccount->avatar_url);
});

test('an empty or too long reply is refused', function (string $text) {
    ReplyToInboxMessage::execute($this->comment, $this->user, $text);
})->with([' ', str_repeat('a', 301)])->throws(ValidationException::class);

test('a sent reply gets its id and answers the comment', function () {
    Queue::fake([SendInboxReply::class]);
    $reply = ReplyToInboxMessage::execute($this->comment, $this->user, 'thanks!');
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('forSending')->andReturnSelf()->getMock()->shouldReceive('reply')->once()
        ->andReturn(new SentReply('at://me/r1', 'https://bsky.app/r1', CarbonImmutable::now(), ['cid' => 'c']));

    (new SendInboxReply($reply->id))->handle();

    expect($reply->fresh()->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($reply->fresh()->platform_message_id)->toBe('at://me/r1')
        ->and($this->comment->fresh()->answered_at)->not->toBeNull()
        ->and($this->conversation->fresh()->open_count)->toBe(0);
});

test('an echo that lands first is merged into one message', function () {
    Queue::fake([SendInboxReply::class]);
    $reply = ReplyToInboxMessage::execute($this->comment, $this->user, 'thanks!');
    $echo = InboxMessage::factory()->for($this->conversation, 'conversation')->outbound()->create([
        'platform_message_id' => 'at://me/r1',
        'platform_message_hash' => InboxConversation::hashOf('at://me/r1'),
    ]);
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('forSending')->andReturnSelf()->getMock()->shouldReceive('reply')->andReturn(new SentReply('at://me/r1', null, CarbonImmutable::now()));

    (new SendInboxReply($reply->id))->handle();

    expect(InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->count())->toBe(1)
        ->and($echo->fresh()->sent_by_user_id)->toBe($this->user->id);
});

test('an echo inserted while the reply is being saved is merged', function () {
    Queue::fake([SendInboxReply::class]);
    $reply = ReplyToInboxMessage::execute($this->comment, $this->user, 'thanks!');
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('forSending')->andReturnSelf()->getMock()->shouldReceive('reply')->andReturn(new SentReply('at://me/r2', null, CarbonImmutable::now()));
    $conversation = $this->conversation;
    $inserted = false;
    DB::listen(function (QueryExecuted $query) use ($conversation, &$inserted): void {
        if ($inserted || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'platform_message_hash')) {
            return;
        }

        $inserted = true;
        InboxMessage::factory()->for($conversation, 'conversation')->outbound()->create([
            'platform_message_id' => 'at://me/r2',
            'platform_message_hash' => InboxConversation::hashOf('at://me/r2'),
            'parent_id' => $this->comment->id,
            'root_id' => $this->comment->id,
            'platform_created_at' => now(),
        ]);
    });

    (new SendInboxReply($reply->id))->handle();

    $outbound = InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->get();

    expect($inserted)->toBeTrue()
        ->and($outbound)->toHaveCount(1)
        ->and($outbound->first()->sent_by_user_id)->toBe($this->user->id)
        ->and($this->comment->fresh()->answered_at)->not->toBeNull()
        ->and($this->conversation->fresh()->open_count)->toBe(0);
});

test('a failed reply is marked failed with a readable error', function () {
    Queue::fake([SendInboxReply::class]);
    $reply = ReplyToInboxMessage::execute($this->comment, $this->user, 'thanks!');
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('forSending')->andReturnSelf()->getMock()->shouldReceive('reply')->andThrow(new RuntimeException('boom'));

    (new SendInboxReply($reply->id))->handle();

    expect($reply->fresh()->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($reply->fresh()->delivery_error)->toBe('reply_failed')
        ->and($this->comment->fresh()->answered_at)->toBeNull();
});

test('resolving the conversation resolves its open messages and undo reopens one', function () {
    InboxMessage::factory()->for($this->conversation, 'conversation')->create();

    $count = ResolveInboxMessages::execute($this->conversation->workspace, $this->user, ResolveScope::Conversation, conversation: $this->conversation);

    expect($count)->toBe(2)->and($this->conversation->fresh()->open_count)->toBe(0);

    UnresolveInboxMessage::execute($this->comment->fresh());

    expect($this->conversation->fresh()->open_count)->toBe(1);
});

test('resolving older messages leaves recent ones open', function () {
    $this->comment->update(['platform_created_at' => now()->subMonths(2)]);
    $recent = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['platform_created_at' => now()->subDay()]);

    ResolveInboxMessages::execute($this->conversation->workspace, $this->user, ResolveScope::Older);

    expect($this->comment->fresh()->resolved_at)->not->toBeNull()
        ->and($recent->fresh()->resolved_at)->toBeNull();
});

test('a member who needs approval cannot reply or resolve but can view', function () {
    $member = workspaceMember($this->conversation->workspace, 'approval');

    expect($member->can('view', $this->conversation))->toBeTrue()
        ->and($member->can('reply', $this->comment))->toBeFalse()
        ->and($member->can('resolve', $this->comment))->toBeFalse();
});

test('an outsider cannot view, reply or resolve', function () {
    $outsider = workspaceOutsider($this->conversation->workspace);

    expect($outsider->can('view', $this->conversation))->toBeFalse()
        ->and($outsider->can('reply', $this->comment))->toBeFalse();
});

test('a member who publishes directly can reply and resolve', function () {
    $member = workspaceMember($this->conversation->workspace);

    expect($member->can('reply', $this->comment))->toBeTrue()
        ->and($member->can('resolve', $this->comment))->toBeTrue();
});

test('retrying a failed reply sends the same row again', function () {
    Queue::fake([SendInboxReply::class]);
    $failed = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create([
        'parent_id' => $this->comment->id,
        'root_id' => $this->comment->id,
        'delivery_status' => DeliveryStatus::Failed,
        'delivery_error' => 'Network said no.',
    ]);

    $retried = RetryInboxReply::execute($failed);

    expect($retried->id)->toBe($failed->id)
        ->and($failed->fresh()->delivery_status)->toBe(DeliveryStatus::Sending)
        ->and($failed->fresh()->delivery_error)->toBeNull()
        ->and(InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->count())->toBe(1);
    Queue::assertPushed(SendInboxReply::class, fn (SendInboxReply $job) => $job->messageId === $failed->id);
});

test('only a failed reply can be retried', function (string $state) {
    Queue::fake([SendInboxReply::class]);
    $message = match ($state) {
        'inbound' => $this->comment,
        'sending' => InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id, 'delivery_status' => DeliveryStatus::Sending]),
        'sent' => InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id]),
    };

    expect(fn () => RetryInboxReply::execute($message))->toThrow(ValidationException::class);
    Queue::assertNothingPushed();
})->with(['inbound', 'sending', 'sent']);

test('a reply job that dies while sending marks the reply failed', function () {
    Event::fake([InboxUpdated::class]);
    $reply = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create([
        'parent_id' => $this->comment->id,
        'root_id' => $this->comment->id,
        'delivery_status' => DeliveryStatus::Sending,
    ]);

    (new SendInboxReply($reply->id))->failed(new RuntimeException('timed out'));

    expect($reply->fresh()->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($reply->fresh()->delivery_error)->toBe(InboxMessage::REPLY_FAILED);
    Event::assertDispatched(InboxUpdated::class);
});

test('a reply job that fails after sending leaves the sent reply alone', function () {
    $reply = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id]);

    (new SendInboxReply($reply->id))->failed(new RuntimeException('late'));

    expect($reply->fresh()->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($reply->fresh()->delivery_error)->toBeNull();
});

test('the reply job outlives the network budget', function () {
    expect((new SendInboxReply('id'))->timeout)->toBeGreaterThanOrEqual(150)
        ->and(config('horizon.defaults.inbox.timeout'))->toBeGreaterThan((new SendInboxReply('id'))->timeout);
});

test('a failed reply error is shown in the viewer locale', function () {
    $failed = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create([
        'parent_id' => $this->comment->id,
        'delivery_status' => DeliveryStatus::Failed,
        'delivery_error' => InboxMessage::REPLY_FAILED,
    ]);

    app()->setLocale('pt-BR');
    $payload = InboxMessageResource::make($failed->fresh())->resolve();

    expect($payload['delivery_error'])->toBe(__('inbox.errors.reply_failed', ['network' => 'Bluesky'], 'pt-BR'))
        ->and($payload['delivery_error'])->not->toBe(__('inbox.errors.reply_failed', ['network' => 'Bluesky'], 'en'));
});

test('resolving a thread resolves the comment and its open replies', function () {
    $ours = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id, 'root_id' => $this->comment->id]);
    $nested = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['parent_id' => $ours->id, 'root_id' => $this->comment->id]);
    $other = InboxMessage::factory()->for($this->conversation, 'conversation')->create();

    $count = ResolveInboxMessages::execute($this->conversation->workspace, $this->user, ResolveScope::Thread, message: $this->comment);

    expect($count)->toBe(2)
        ->and($this->comment->fresh()->resolved_at)->not->toBeNull()
        ->and($nested->fresh()->resolved_at)->not->toBeNull()
        ->and($other->fresh()->resolved_at)->toBeNull()
        ->and($this->conversation->fresh()->open_count)->toBe(1);

    UnresolveInboxMessage::execute($this->comment->fresh());

    expect($nested->fresh()->resolved_at)->toBeNull()
        ->and($this->comment->fresh()->resolved_at)->toBeNull()
        ->and($this->conversation->fresh()->open_count)->toBe(3);
});

test('resolving a thread from one of its replies resolves the whole tree', function () {
    $nested = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id, 'root_id' => $this->comment->id]);

    ResolveInboxMessages::execute($this->conversation->workspace, $this->user, ResolveScope::Thread, message: $nested);

    expect($this->comment->fresh()->resolved_at)->not->toBeNull()
        ->and($nested->fresh()->resolved_at)->not->toBeNull();
});

test('resolving older messages respects the channel', function () {
    $this->comment->update(['platform_created_at' => now()->subMonths(2)]);
    $elsewhere = InboxMessage::factory()->create(['platform_created_at' => now()->subMonths(2)]);
    $elsewhere->conversation->update(['workspace_id' => $this->conversation->workspace_id]);
    $elsewhere->socialAccount->update(['workspace_id' => $this->conversation->workspace_id]);

    ResolveInboxMessages::execute($this->conversation->workspace, $this->user, ResolveScope::Older, channelIds: [$this->conversation->social_account_id]);

    expect($this->comment->fresh()->resolved_at)->not->toBeNull()
        ->and($elsewhere->fresh()->resolved_at)->toBeNull();
});

test('a bulk resolve broadcasts once for the workspace', function () {
    Event::fake([InboxUpdated::class]);
    $second = InboxConversation::factory()->create(['workspace_id' => $this->conversation->workspace_id, 'social_account_id' => $this->conversation->social_account_id]);
    InboxMessage::factory()->for($second, 'conversation')->create();

    ResolveInboxMessages::execute($this->conversation->workspace, $this->user, ResolveScope::All);

    Event::assertDispatchedTimes(InboxUpdated::class, 1);
    Event::assertDispatched(InboxUpdated::class, fn (InboxUpdated $event): bool => $event->conversationId === null);
});

test('resolving recounts under a lock inside a transaction', function () {
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = [DB::transactionLevel(), $query->sql];
    });

    ResolveInboxMessages::execute($this->conversation->workspace, $this->user, ResolveScope::Conversation, conversation: $this->conversation);

    $locked = collect($statements)->first(fn (array $statement): bool => str_contains(strtolower($statement[1]), 'for update') || str_contains(strtolower($statement[1]), 'for share'));
    $updates = collect($statements)->filter(fn (array $statement): bool => str_starts_with(strtolower($statement[1]), 'update'));

    expect($locked)->not->toBeNull()
        ->and($updates->every(fn (array $statement): bool => $statement[0] > 0))->toBeTrue();
});

test('a reply is refused for a parent that cannot be answered', function (string $state) {
    Queue::fake([SendInboxReply::class]);
    $parent = match ($state) {
        'outbound' => InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id]),
        'removed' => InboxMessage::factory()->for($this->conversation, 'conversation')->create(['removed_at' => now()]),
        'unsent' => InboxMessage::factory()->for($this->conversation, 'conversation')->create(['platform_message_id' => null, 'platform_message_hash' => null]),
    };

    expect(fn () => ReplyToInboxMessage::execute($parent, $this->user, 'hi'))->toThrow(ValidationException::class);
    Queue::assertNothingPushed();
})->with(['outbound', 'removed', 'unsent']);

test('a reply from the inbox is sent through the network driver', function (string $state, array $attributes, string $subjectId, string $commentId, Closure $fakeReply, string $sentId, Closure $echo) {
    Sleep::fake();
    Queue::fake([SendInboxReply::class, SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    $account = SocialAccount::factory()->{$state}()->withInboxScopes()->create(['workspace_id' => $this->conversation->workspace_id, ...$attributes]);
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => $subjectId]);
    $comment = UpsertInboxMessage::execute($account, new IncomingMessage(
        kind: ConversationKind::Comment,
        subject: new IncomingSubject($subjectId, 'Our post', [], 'https://example.com/post', null, $subjectId),
        platformMessageId: $commentId,
        platformParentId: null,
        direction: MessageDirection::Inbound,
        authorPlatformId: 'fan-1',
        authorName: 'Fan',
        authorUsername: 'fan',
        authorAvatarUrl: null,
        body: 'Nice post',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::now()->subHour(),
        meta: ['object' => $subjectId],
    ));
    $fakeReply();

    $reply = ReplyToInboxMessage::execute($comment, $this->user, 'Thanks!');
    (new SendInboxReply($reply->id))->handle();

    $reply->refresh();

    expect($reply->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($reply->platform_message_id)->toBe($sentId)
        ->and($comment->fresh()->answered_at)->not->toBeNull()
        ->and($comment->conversation->fresh()->open_count)->toBe(0);

    UpsertInboxMessages::execute($account, $echo($account)->messages);

    $outbound = InboxMessage::query()->where('social_account_id', $account->id)->where('direction', MessageDirection::Outbound)->sole();

    expect($outbound->id)->toBe($reply->id)
        ->and($outbound->sent_by_user_id)->toBe($this->user->id);
})->with([
    'instagram' => [
        'instagram',
        ['platform_user_id' => 'app-scoped-1', 'username' => 'brand', 'token_expires_at' => now()->addDays(30), 'meta' => ['ig_user_id' => '1784']],
        'media-1',
        'c1',
        fn () => Http::fake([config('trypost.platforms.instagram.graph_api').'/c1/replies' => Http::response(['id' => 'c9'])]),
        'c9',
        fn (SocialAccount $account) => app(InstagramInboxDriver::class)->webhookEvents($account, ['id' => 'c9', 'parent_id' => 'c1', 'text' => 'Thanks!', 'from' => ['id' => '1784', 'username' => 'brand'], 'media' => ['id' => 'media-1']], now()->getTimestamp()),
    ],
    'instagram via facebook' => [
        'instagramFacebook',
        ['platform_user_id' => '1784', 'token_expires_at' => null],
        'media-1',
        'c1',
        fn () => Http::fake([config('trypost.platforms.instagram-facebook.graph_api').'/c1/replies' => Http::response(['id' => 'c9'])]),
        'c9',
        fn (SocialAccount $account) => app(InstagramInboxDriver::class)->webhookEvents($account, ['id' => 'c9', 'parent_id' => 'c1', 'text' => 'Thanks!', 'from' => ['id' => '1784', 'username' => 'brand'], 'media' => ['id' => 'media-1']], now()->getTimestamp()),
    ],
    'facebook' => [
        'facebook',
        ['platform_user_id' => 'page-1', 'token_expires_at' => null],
        'page-1_post-1',
        'post-1_c1',
        fn () => Http::fake([config('trypost.platforms.facebook.graph_api').'/post-1_c1/comments' => Http::response(['id' => 'post-1_c9'])]),
        'post-1_c9',
        fn (SocialAccount $account) => app(FacebookInboxDriver::class)->webhookEvents($account, ['item' => 'comment', 'verb' => 'add', 'comment_id' => 'post-1_c9', 'parent_id' => 'post-1_c1', 'post_id' => 'page-1_post-1', 'from' => ['id' => 'page-1', 'name' => 'Acme'], 'message' => 'Thanks!', 'created_time' => now()->getTimestamp()]),
    ],
    'threads' => [
        'threads',
        ['platform_user_id' => 'threads-user-1', 'username' => 'brand', 'token_expires_at' => now()->addDays(30)],
        'root-1',
        'reply-1',
        function () {
            $graph = config('trypost.platforms.threads.graph_api');
            Http::fake([
                "{$graph}/threads-user-1/threads_publishing_limit*" => Http::response(['data' => [['reply_quota_usage' => 1, 'reply_config' => ['quota_total' => 1000]]]]),
                "{$graph}/threads-user-1/threads" => Http::response(['id' => 'container-1']),
                "{$graph}/container-1*" => Http::response(['status' => 'FINISHED']),
                "{$graph}/threads-user-1/threads_publish" => Http::response(['id' => 'reply-9']),
                "{$graph}/reply-9*" => Http::response(['permalink' => 'https://www.threads.net/@brand/post/x']),
            ]);
        },
        'reply-9',
        fn (SocialAccount $account) => app(ThreadsInboxDriver::class)->webhookEvents($account, ['id' => 'reply-9', 'username' => 'brand', 'text' => 'Thanks!', 'replied_to' => ['id' => 'reply-1'], 'root_post' => ['id' => 'root-1', 'owner_id' => 'threads-user-1'], 'timestamp' => now()->toIso8601String()]),
    ],
]);

test('replies and direct messages go out on their own queue, read before the polls', function () {
    expect((new SendInboxReply('message-id'))->queue)->toBe('inbox-replies')
        ->and(config('horizon.defaults.inbox.queue'))->toBe(['inbox-replies', 'inbox']);
});

test('a failed reply is reported with its access token redacted', function () {
    Queue::fake([SendInboxReply::class]);
    Exceptions::fake();
    $reply = ReplyToInboxMessage::execute($this->comment, $this->user, 'thanks!');
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('forSending')->andReturnSelf()->getMock()->shouldReceive('reply')->andThrow(new RuntimeException('GET https://x.test/?access_token=secret-token failed'));

    (new SendInboxReply($reply->id))->handle();

    Exceptions::assertReported(fn (RuntimeException $exception): bool => ! str_contains($exception->getMessage(), 'secret-token')
        && str_contains($exception->getMessage(), '[REDACTED]'));
    Exceptions::assertNotReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'secret-token'));
});

test('resolving all or older messages never touches another workspace', function (ResolveScope $scope) {
    $this->comment->update(['platform_created_at' => now()->subMonths(2)]);
    $foreign = InboxMessage::factory()->create(['platform_created_at' => now()->subMonths(2)]);
    RecountInboxConversation::execute($foreign->conversation);

    ResolveInboxMessages::execute($this->conversation->workspace, $this->user, $scope);

    expect($this->comment->fresh()->resolved_at)->not->toBeNull()
        ->and($foreign->fresh()->resolved_at)->toBeNull()
        ->and($foreign->conversation->fresh()->open_count)->toBe(1);
})->with([ResolveScope::All, ResolveScope::Older]);

test('a message arriving while a bulk resolve runs stays open and counted', function () {
    $late = null;
    DB::listen(function (QueryExecuted $query) use (&$late): void {
        if ($late === null && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'inbox_messages')) {
            $late = false;
            $conversation = InboxConversation::factory()->create(['workspace_id' => $this->conversation->workspace_id, 'social_account_id' => $this->conversation->social_account_id]);
            $late = InboxMessage::factory()->for($conversation, 'conversation')->create();
            RecountInboxConversation::execute($conversation);
        }
    });

    ResolveInboxMessages::execute($this->conversation->workspace, $this->user, ResolveScope::All);

    expect($this->comment->fresh()->resolved_at)->not->toBeNull()
        ->and($late->fresh()->resolved_at)->toBeNull()
        ->and($late->conversation->fresh()->open_count)->toBe(1);
});

test('a reply removed on the network answers nothing', function () {
    $removed = InboxMessage::factory()->for($this->conversation, 'conversation')->outbound()->create([
        'parent_id' => $this->comment->id,
        'root_id' => $this->comment->id,
        'platform_created_at' => now(),
        'removed_at' => now(),
    ]);

    MarkInboxTreeAnswered::execute($removed);

    expect($this->comment->fresh()->answered_at)->toBeNull();
});

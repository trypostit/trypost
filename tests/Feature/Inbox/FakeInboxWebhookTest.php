<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Jobs\Post\ImportExternalPostMedia;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    config()->set('services.facebook.client_secret', 'fb-secret');
    config()->set('services.instagram.client_secret', 'ig-secret');
    config()->set('services.threads.client_secret', 'th-secret');
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class, ImportExternalPostMedia::class]);
    $this->deliveries = [];
});

/**
 * Hands every delivery the command sends to this app, as Meta would.
 *
 * @param  array<string, mixed>  $graph
 */
function forwardFakeWebhooks(mixed $test, array $graph = []): void
{
    Http::fake([
        route('webhooks.meta') => function (Request $request) use ($test) {
            $test->deliveries[] = $request;
            $response = $test->call('POST', route('webhooks.meta'), [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $request->header('X-Hub-Signature-256')[0] ?? '',
            ], $request->body());

            return Http::response($response->getContent(), $response->status());
        },
        ...$graph,
    ]);
}

test('the command never runs in production', function () {
    $this->app['env'] = 'production';
    $page = SocialAccount::factory()->facebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    Post::factory()->forAccount($page)->published()->create(['platform_post_id' => 'page-1_post-1']);
    Http::fake();

    $this->artisan('inbox:fake-webhook', ['network' => 'facebook', 'event' => 'comment'])->assertFailed();

    Http::assertNothingSent();
});

test('an unknown network or event is refused', function (string $network, string $event) {
    $this->artisan('inbox:fake-webhook', ['network' => $network, 'event' => $event])->assertFailed();
})->with([['tiktok', 'comment'], ['instagram', 'echo'], ['threads', 'message']]);

test('the delivery is signed with the app secret Meta signs the channel with', function (string $network, Closure $channel, string $secret) {
    $channel();
    forwardFakeWebhooks($this);

    $this->artisan('inbox:fake-webhook', ['network' => $network, 'event' => 'message'])->assertSuccessful();

    $delivery = $this->deliveries[0];

    expect($delivery->header('X-Hub-Signature-256')[0])->toBe('sha256='.hash_hmac('sha256', $delivery->body(), $secret));
})->with([
    'facebook page' => ['facebook', fn () => SocialAccount::factory()->facebook()->withDirectMessageScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]), 'fb-secret'],
    'instagram login' => ['instagram', fn () => SocialAccount::factory()->instagram()->withDirectMessageScopes()->create(['token_expires_at' => now()->addDays(30), 'meta' => ['ig_user_id' => '1784']]), 'ig-secret'],
]);

test('every facebook event lands in the page inbox', function () {
    $page = SocialAccount::factory()->facebook()->withDirectMessageScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    Post::factory()->forAccount($page)->published()->create(['platform_post_id' => 'page-1_post-1']);
    forwardFakeWebhooks($this);

    $this->artisan('inbox:fake-webhook', ['network' => 'facebook', 'event' => 'comment', '--text' => 'First'])->assertSuccessful();
    $comment = InboxMessage::query()->sole();

    $this->artisan('inbox:fake-webhook', ['network' => 'facebook', 'event' => 'reply', '--text' => 'Second'])->assertSuccessful();
    $reply = InboxMessage::query()->where('body', 'Second')->sole();

    $this->artisan('inbox:fake-webhook', ['network' => 'facebook', 'event' => 'edit', '--target' => $comment->platform_message_id, '--text' => 'First, edited'])->assertSuccessful();
    $this->artisan('inbox:fake-webhook', ['network' => 'facebook', 'event' => 'delete', '--target' => $reply->platform_message_id])->assertSuccessful();
    $this->artisan('inbox:fake-webhook', ['network' => 'facebook', 'event' => 'message', '--text' => 'Hello'])->assertSuccessful();
    $this->artisan('inbox:fake-webhook', ['network' => 'facebook', 'event' => 'echo', '--text' => 'Hi back'])->assertSuccessful();

    expect($comment->fresh()->body)->toBe('First, edited')
        ->and($comment->conversation->post->platform_post_id)->toBe('page-1_post-1')
        ->and($reply->parent_id)->toBe($comment->id)
        ->and($reply->fresh()->removed_at)->not->toBeNull()
        ->and(InboxMessage::query()->where('body', 'Hello')->sole()->conversation->kind)->toBe(ConversationKind::DirectMessage)
        ->and(InboxMessage::query()->where('body', 'Hi back')->sole()->direction)->toBe(MessageDirection::Outbound);
});

test('a facebook mention lands once the network returns the mentioning post', function () {
    SocialAccount::factory()->facebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    $graph = config('trypost.platforms.facebook.graph_api');
    forwardFakeWebhooks($this, ["{$graph}/*" => Http::response(['id' => 'x', 'message' => 'Shout out', 'from' => ['id' => '7', 'name' => 'Friend'], 'created_time' => now()->toIso8601String()])]);

    $this->artisan('inbox:fake-webhook', ['network' => 'facebook', 'event' => 'mention'])->assertSuccessful();

    expect(InboxConversation::query()->sole()->kind)->toBe(ConversationKind::Mention);
});

test('every instagram event lands in the inbox of an instagram login channel', function () {
    $channel = SocialAccount::factory()->instagram()->withDirectMessageScopes()->create(['username' => 'brand', 'token_expires_at' => now()->addDays(30), 'meta' => ['ig_user_id' => '1784']]);
    Post::factory()->forAccount($channel)->published()->create(['platform_post_id' => '18000000000000001']);
    $ig = config('trypost.platforms.instagram.graph_api');
    forwardFakeWebhooks($this, ["{$ig}/*" => Http::response(['id' => 'm', 'owner' => ['id' => '5555']])]);

    $this->artisan('inbox:fake-webhook', ['network' => 'instagram', 'event' => 'comment', '--text' => 'Nice'])->assertSuccessful();
    $comment = InboxMessage::query()->sole();
    $this->artisan('inbox:fake-webhook', ['network' => 'instagram', 'event' => 'reply', '--text' => 'Thanks'])->assertSuccessful();
    $this->artisan('inbox:fake-webhook', ['network' => 'instagram', 'event' => 'live_comment', '--text' => 'Live!'])->assertSuccessful();
    $this->artisan('inbox:fake-webhook', ['network' => 'instagram', 'event' => 'message', '--text' => 'DM'])->assertSuccessful();
    $this->artisan('inbox:fake-webhook', ['network' => 'instagram', 'event' => 'mention', '--text' => 'look'])->assertSuccessful();

    expect($comment->conversation->kind)->toBe(ConversationKind::Comment)
        ->and(InboxMessage::query()->where('body', 'Thanks')->sole()->parent_id)->toBe($comment->id)
        ->and(InboxMessage::query()->where('body', 'Live!')->sole()->inbox_conversation_id)->toBe($comment->inbox_conversation_id)
        ->and(InboxMessage::query()->where('body', 'DM')->sole()->conversation->kind)->toBe(ConversationKind::DirectMessage)
        ->and(InboxMessage::query()->where('body', '@brand look')->sole()->conversation->kind)->toBe(ConversationKind::Mention);
});

test('an instagram mention reaches a channel connected through facebook', function () {
    SocialAccount::factory()->instagramFacebook()->withInboxScopes()->create(['platform_user_id' => '1784', 'token_expires_at' => null]);
    $graph = config('trypost.platforms.instagram-facebook.graph_api');
    forwardFakeWebhooks($this, ["{$graph}/1784*" => Http::response(['mentioned_comment' => ['id' => 'c', 'text' => 'Hey @brand', 'timestamp' => now()->toIso8601String(), 'media' => ['id' => 'm', 'caption' => 'Their post']]])]);

    $this->artisan('inbox:fake-webhook', ['network' => 'instagram', 'event' => 'mention'])->assertSuccessful();

    expect(InboxMessage::query()->sole()->body)->toBe('Hey @brand')
        ->and(InboxConversation::query()->sole()->kind)->toBe(ConversationKind::Mention);
});

test('every threads event lands in the inbox', function () {
    $channel = SocialAccount::factory()->threads()->withInboxScopes()->create(['platform_user_id' => 'threads-user-1', 'username' => 'brand', 'token_expires_at' => now()->addDays(30), 'scopes' => ['threads_basic', 'threads_read_replies', 'threads_manage_replies', 'threads_manage_mentions']]);
    Post::factory()->forAccount($channel)->published()->create(['platform_post_id' => 'root-1']);
    forwardFakeWebhooks($this);

    $this->artisan('inbox:fake-webhook', ['network' => 'threads', 'event' => 'comment', '--text' => 'Reply'])->assertSuccessful();
    $comment = InboxMessage::query()->sole();
    $this->artisan('inbox:fake-webhook', ['network' => 'threads', 'event' => 'reply', '--text' => 'Nested'])->assertSuccessful();
    $this->artisan('inbox:fake-webhook', ['network' => 'threads', 'event' => 'mention', '--text' => 'hey'])->assertSuccessful();
    $nested = InboxMessage::query()->where('body', 'Nested')->sole();
    $this->artisan('inbox:fake-webhook', ['network' => 'threads', 'event' => 'delete', '--target' => $nested->platform_message_id])->assertSuccessful();

    expect($comment->conversation->post->platform_post_id)->toBe('root-1')
        ->and($nested->parent_id)->toBe($comment->id)
        ->and($nested->fresh()->removed_at)->not->toBeNull()
        ->and(InboxMessage::query()->where('body', '@brand hey')->sole()->conversation->kind)->toBe(ConversationKind::Mention);
});

test('a comment event needs a post', function () {
    SocialAccount::factory()->facebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    Http::preventStrayRequests();

    $this->artisan('inbox:fake-webhook', ['network' => 'facebook', 'event' => 'comment'])->assertFailed();

    Http::assertNothingSent();
});

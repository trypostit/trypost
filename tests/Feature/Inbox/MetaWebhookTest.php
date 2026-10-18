<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Status;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\Inbox\HandleMetaWebhook;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Jobs\Post\ImportExternalPostMedia;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\FacebookInboxDriver;
use App\Services\Inbox\Drivers\InstagramInboxDriver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

beforeEach(function () {
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    config()->set('trypost.inbox.enabled', true);
    config()->set('services.facebook.client_secret', 'fb-secret');
    config()->set('services.instagram.client_secret', 'ig-secret');
    config()->set('services.threads.client_secret', 'th-secret');
    config()->set('trypost.inbox.webhooks.meta_verify_token', 'verify-me');
    $this->graph = config('trypost.platforms.facebook.graph_api');
});

function postMetaWebhook(TestCase $test, string $body, ?string $secret): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];

    if ($secret !== null) {
        $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return $test->call('POST', route('webhooks.meta'), [], [], [], $server, $body);
}

function metaFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/fixtures/inbox/{$name}.json"));
}

/** @return array<string, mixed> */
function metaPayload(string $name, array $overrides = []): array
{
    return array_replace_recursive(json_decode(metaFixture($name), true), $overrides);
}

function facebookInboxPage(array $attributes = [], bool $withPost = true): SocialAccount
{
    $account = SocialAccount::factory()->facebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null, ...$attributes]);

    if ($withPost) {
        Post::factory()->forAccount($account)->published()->create(['platform_post_id' => 'page-1_post-1', 'content' => 'Our news']);
    }

    return $account;
}

function fakeFacebookPost(string $graph): void
{
    Http::fake(["{$graph}/page-1_post-1*" => Http::response(['id' => 'page-1_post-1', 'message' => 'Our news', 'permalink_url' => 'https://www.facebook.com/page-1/posts/1'])]);
}

test('the verification echoes the challenge', function () {
    $this->get(route('webhooks.meta.verify').'?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=1158201444')
        ->assertOk()
        ->assertContent('1158201444');
});

test('a wrong verify token is refused', function () {
    $this->get(route('webhooks.meta.verify').'?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=1')->assertForbidden();
});

test('an unset verify token refuses everything', function () {
    config()->set('trypost.inbox.webhooks.meta_verify_token', null);

    $this->get(route('webhooks.meta.verify').'?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertForbidden();
});

test('a valid signature queues the job', function () {
    Queue::fake();

    postMetaWebhook($this, metaFixture('facebook-feed-comment'), 'fb-secret')->assertOk();

    Queue::assertPushedOn('inbox', HandleMetaWebhook::class);
});

test('deliveries signed with any configured meta app secret are accepted', function (string $fixture, string $secret) {
    Queue::fake();

    postMetaWebhook($this, metaFixture($fixture), $secret)->assertOk();

    Queue::assertPushed(HandleMetaWebhook::class);
})->with([
    'instagram login' => ['instagram-comment', 'ig-secret'],
    'threads' => ['threads-reply', 'th-secret'],
]);

test('a bad signature is 401 and queues nothing', function () {
    Queue::fake();

    postMetaWebhook($this, metaFixture('facebook-feed-comment'), 'wrong')->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('a missing signature is 401', function () {
    Queue::fake();

    postMetaWebhook($this, metaFixture('facebook-feed-comment'), null)->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('the signature covers the raw body', function () {
    Queue::fake();
    $body = str_replace('Great news', 'Café news', metaFixture('facebook-feed-comment'));

    postMetaWebhook($this, $body, 'fb-secret')->assertOk();

    Queue::assertPushed(HandleMetaWebhook::class);
});

test('irrelevant deliveries are acknowledged without a job', function () {
    Queue::fake();
    $body = (string) json_encode(metaPayload('facebook-feed-comment', ['entry' => [['changes' => [['value' => ['item' => 'like']]]]]]));

    postMetaWebhook($this, $body, 'fb-secret')->assertOk();

    Queue::assertNothingPushed();
});

test('the module off answers 404', function () {
    config()->set('trypost.inbox.enabled', false);
    Queue::fake();

    $this->get(route('webhooks.meta.verify').'?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=1')->assertNotFound();
    postMetaWebhook($this, metaFixture('facebook-feed-comment'), 'fb-secret')->assertNotFound();
});

test('a facebook comment lands in the page inbox', function () {
    $account = facebookInboxPage();
    fakeFacebookPost($this->graph);

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));

    $message = InboxMessage::query()->sole();

    expect($message->social_account_id)->toBe($account->id)
        ->and($message->platform_message_id)->toBe('post-1_c1')
        ->and($message->conversation->open_count)->toBe(1)
        ->and($message->conversation->post_id)->not->toBeNull()
        ->and($message->conversation->subject()['text'])->toBe('Our news');
});

test('a delivery fans out to every workspace that connected the page', function () {
    $first = facebookInboxPage();
    $second = facebookInboxPage();
    fakeFacebookPost($this->graph);

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));

    expect(InboxMessage::query()->pluck('social_account_id')->sort()->values()->all())->toBe(collect([$first->id, $second->id])->sort()->values()->all())
        ->and(InboxConversation::query()->distinct()->pluck('workspace_id')->all())->toHaveCount(2);
});

test('an instagram login channel is matched by its professional account id', function () {
    $account = SocialAccount::factory()->instagram()->withInboxScopes()->create([
        'platform_user_id' => 'app-scoped-1',
        'token_expires_at' => now()->addDays(30),
        'meta' => ['ig_user_id' => '17841400000000001'],
    ]);
    Http::fake();

    HandleMetaWebhook::dispatchSync(metaPayload('instagram-comment'));

    expect(InboxMessage::query()->sole()->social_account_id)->toBe($account->id);
});

test('an instagram channel connected through facebook is matched by its platform id', function () {
    $account = SocialAccount::factory()->instagramFacebook()->withInboxScopes()->create([
        'platform_user_id' => '17841400000000001',
        'token_expires_at' => null,
    ]);
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => '18000000000000001']);
    Http::fake();

    HandleMetaWebhook::dispatchSync(metaPayload('instagram-comment'));

    expect(InboxMessage::query()->sole()->social_account_id)->toBe($account->id);
});

test('a page feed comment never reaches an instagram channel of the same page', function () {
    SocialAccount::factory()->instagramFacebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    Http::fake();

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));

    expect(InboxMessage::query()->count())->toBe(0);
});

test('a threads reply lands in the post owner inbox', function () {
    $account = SocialAccount::factory()->threads()->withInboxScopes()->create(['platform_user_id' => 'threads-user-1', 'username' => 'brand', 'token_expires_at' => now()->addDays(30)]);
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => 'root-1']);
    Http::fake();

    HandleMetaWebhook::dispatchSync(metaPayload('threads-reply'));

    expect(InboxMessage::query()->sole()->social_account_id)->toBe($account->id);
});

test('a threads delete removes the reply from every channel that keeps a copy', function () {
    $author = SocialAccount::factory()->threads()->withInboxScopes()->create(['platform_user_id' => 'threads-user-2', 'token_expires_at' => now()->addDays(30)]);
    $answered = SocialAccount::factory()->threads()->withInboxScopes()->create(['platform_user_id' => 'threads-user-1', 'token_expires_at' => now()->addDays(30)]);
    $bystander = SocialAccount::factory()->threads()->withInboxScopes()->create(['platform_user_id' => 'threads-user-3', 'token_expires_at' => now()->addDays(30)]);
    $copy = ['platform_message_id' => 'reply-1', 'platform_message_hash' => InboxConversation::hashOf('reply-1')];
    $sent = InboxMessage::factory()->sentFromTryPost()->for(InboxConversation::factory()->for($author, 'socialAccount'), 'conversation')->create($copy);
    $received = InboxMessage::factory()->for(InboxConversation::factory()->for($answered, 'socialAccount'), 'conversation')->create($copy);
    $other = InboxMessage::factory()->for(InboxConversation::factory()->for($bystander, 'socialAccount'), 'conversation')->create();
    Http::fake();

    postMetaWebhook($this, metaFixture('threads-delete'), 'th-secret')->assertOk();

    expect($sent->fresh()->removed_at)->not->toBeNull()
        ->and($received->fresh()->removed_at)->not->toBeNull()
        ->and($received->conversation->fresh()->open_count)->toBe(0)
        ->and($other->fresh()->removed_at)->toBeNull();
    Http::assertNothingSent();
});

test('a channel that must not receive it gets nothing', function (Closure $setup) {
    $account = facebookInboxPage();
    $setup($account);
    fakeFacebookPost($this->graph);

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));

    expect(InboxMessage::query()->count())->toBe(0);
})->with([
    'without inbox access' => [fn (SocialAccount $account) => $account->forceFill(['scopes' => ['pages_manage_posts']])->save()],
    'with the inbox off' => [fn () => config()->set('trypost.inbox.enabled', false)],
    'disconnected' => [fn (SocialAccount $account) => $account->forceFill(['status' => Status::Disconnected])->save()],
    'without app access' => [fn () => config()->set('trypost.self_hosted', false)],
]);

test('a facebook remove deletes the stored comment from the open count', function () {
    facebookInboxPage();
    fakeFacebookPost($this->graph);
    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment', ['entry' => [['changes' => [['value' => ['verb' => 'remove']]]]]]));

    $message = InboxMessage::query()->sole();

    expect($message->removed_at)->not->toBeNull()
        ->and($message->conversation->fresh()->open_count)->toBe(0);
});

test('a page hiding a comment marks it hidden and showing it again clears it, keeping its author and its place in the open count', function () {
    facebookInboxPage();
    fakeFacebookPost($this->graph);
    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));
    $byPage = ['entry' => [['changes' => [['value' => ['from' => ['id' => 'page-1', 'name' => 'Acme']]]]]]];

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment', array_replace_recursive($byPage, ['entry' => [['changes' => [['value' => ['verb' => 'hide']]]]]])));
    $message = InboxMessage::query()->sole();

    expect($message->hidden_at)->not->toBeNull()
        ->and($message->author_name)->not->toBe('Acme')
        ->and($message->conversation->fresh()->open_count)->toBe(1);

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment', array_replace_recursive($byPage, ['entry' => [['changes' => [['value' => ['verb' => 'unhide']]]]]])));

    expect($message->fresh()->hidden_at)->toBeNull();
});

test('a later read that does not say whether a comment is hidden keeps it hidden', function () {
    facebookInboxPage();
    fakeFacebookPost($this->graph);
    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));
    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment', ['entry' => [['changes' => [['value' => ['verb' => 'hide', 'from' => ['id' => 'page-1', 'name' => 'Acme']]]]]]]));

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment', ['entry' => [['changes' => [['value' => ['message' => 'Edited', 'verb' => 'edited']]]]]]));

    $message = InboxMessage::query()->sole();

    expect($message->body)->toBe('Edited')
        ->and($message->hidden_at)->not->toBeNull();
});

test('a repeated delivery stores one message', function () {
    facebookInboxPage();
    fakeFacebookPost($this->graph);

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));
    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));

    expect(InboxMessage::query()->count())->toBe(1);
    Http::assertNothingSent();
});

test('one failing channel does not stop the others', function () {
    $attributes = ['token_expires_at' => now()->addDays(30), 'meta' => ['ig_user_id' => '17841400000000001']];
    $failing = SocialAccount::factory()->instagram()->withInboxScopes()->create($attributes);
    $working = SocialAccount::factory()->instagram()->withInboxScopes()->create($attributes);
    Http::fake();
    $real = new InstagramInboxDriver;

    $this->partialMock(InstagramInboxDriver::class, function ($mock) use ($failing, $real) {
        $mock->shouldReceive('webhookEvents')->andReturnUsing(function (SocialAccount $account, array $value, int $time) use ($failing, $real) {
            if ($account->is($failing)) {
                throw new TokenExpiredException('expired');
            }

            return $real->webhookEvents($account, $value, $time);
        });
    });

    HandleMetaWebhook::dispatchSync(metaPayload('instagram-comment'));

    expect(InboxMessage::query()->sole()->social_account_id)->toBe($working->id)
        ->and($failing->fresh()->status)->toBe(Status::TokenExpired);
});

test('a comment on a post not imported yet reads that post once with the channel token', function () {
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class, ImportExternalPostMedia::class]);
    $account = facebookInboxPage(withPost: false);
    Http::fake(["{$this->graph}/page-1_post-1*" => Http::response(['id' => 'page-1_post-1', 'message' => 'Our news', 'created_time' => '2026-10-15T10:00:00+0000', 'permalink_url' => 'https://www.facebook.com/page-1/posts/1'])]);

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));

    expect(InboxMessage::query()->sole()->conversation->post->platform_post_id)->toBe('page-1_post-1');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', "Bearer {$account->access_token}"));
});

test('an outage on every channel is left to the job retries', function () {
    facebookInboxPage();
    $this->mock(FacebookInboxDriver::class)->shouldReceive('webhookEvents')->andThrow(new PlatformUnavailableException('down'));

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));
})->throws(PlatformUnavailableException::class);

test('an outage on one channel does not retry the delivery the others stored', function () {
    $failing = facebookInboxPage();
    facebookInboxPage();
    fakeFacebookPost($this->graph);
    $real = new FacebookInboxDriver;

    $this->partialMock(FacebookInboxDriver::class, function ($mock) use ($failing, $real) {
        $mock->shouldReceive('webhookEvents')->andReturnUsing(function (SocialAccount $account, array $value) use ($failing, $real) {
            if ($account->is($failing)) {
                throw new PlatformUnavailableException('down');
            }

            return $real->webhookEvents($account, $value);
        });
    });

    HandleMetaWebhook::dispatchSync(metaPayload('facebook-feed-comment'));

    expect(InboxMessage::query()->count())->toBe(1);
});

test('rejected signatures are logged once an hour as a warning, then at debug level', function () {
    Log::spy();

    postMetaWebhook($this, metaFixture('facebook-feed-comment'), 'wrong')->assertUnauthorized();
    postMetaWebhook($this, metaFixture('facebook-feed-comment'), 'wrong')->assertUnauthorized();

    Log::shouldHaveReceived('warning')->with('Meta webhook signature rejected', Mockery::on(fn (array $context): bool => array_is_list($context['headers'])))->once();
    Log::shouldHaveReceived('debug')->with('Meta webhook signature rejected', Mockery::any())->once();
});

test('webhook deliveries start no session and set no cookie', function () {
    $body = metaFixture('facebook-feed-comment');

    $delivery = postMetaWebhook($this, $body, 'fb-secret')->assertOk();
    $check = $this->get(route('webhooks.meta.verify', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-me', 'hub.challenge' => 'abc']))->assertOk();

    expect($delivery->headers->getCookies())->toBe([])
        ->and($check->headers->getCookies())->toBe([]);
});

test('a verification with array query values is refused, not a server error', function (array $query) {
    $this->get(route('webhooks.meta.verify', $query))->assertForbidden();
})->with([
    'token' => [['hub.mode' => 'subscribe', 'hub.verify_token' => ['verify-me'], 'hub.challenge' => 'abc']],
    'challenge' => [['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-me', 'hub.challenge' => ['abc']]],
]);

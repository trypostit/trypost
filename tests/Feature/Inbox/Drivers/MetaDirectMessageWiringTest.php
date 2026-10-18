<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Inbox\HandleMetaWebhook;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inbox\Contracts\DirectMessageDriver;
use App\Services\Inbox\InboxDrivers;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    config()->set('trypost.inbox.enabled', true);
    Http::fake(['*' => Http::response([])]);
    $this->instagram = SocialAccount::factory()->instagram()->create([
        'meta' => ['ig_user_id' => 'ig-me'],
        'token_expires_at' => null,
        'scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights', 'instagram_business_manage_comments', 'instagram_business_manage_messages'],
    ]);
    $this->messaging = ['object' => 'instagram', 'entry' => [[
        'id' => 'ig-me',
        'time' => 1760608800000,
        'messaging' => [
            ['sender' => ['id' => 'igsid-9'], 'recipient' => ['id' => 'ig-me'], 'timestamp' => 1760608800000, 'message' => ['mid' => 'mid-1', 'text' => 'Hi']],
            ['sender' => ['id' => 'ig-me'], 'recipient' => ['id' => 'igsid-9'], 'timestamp' => 1760608860000, 'message' => ['mid' => 'mid-2', 'text' => 'Hello!', 'is_echo' => true]],
        ],
    ]]];
});

test('every Meta driver sends DMs', function (Platform $platform) {
    $driver = app(InboxDrivers::class)->for($platform);

    expect($driver)->toBeInstanceOf(DirectMessageDriver::class)
        ->and($driver->kinds())->toContain(ConversationKind::DirectMessage);
})->with([Platform::Instagram, Platform::InstagramFacebook, Platform::Facebook]);

test('a messaging webhook event lands as a DM and its echo as outbound', function () {
    expect(HandleMetaWebhook::isRelevant($this->messaging))->toBeTrue();

    HandleMetaWebhook::dispatchSync($this->messaging);

    $conversation = InboxConversation::query()->sole();

    expect($conversation->kind)->toBe(ConversationKind::DirectMessage)
        ->and($conversation->platform_conversation_id)->toBe('igsid-9')
        ->and(InboxMessage::query()->where('platform_message_id', 'mid-2')->value('direction'))->toBe(MessageDirection::Outbound)
        ->and($conversation->open_count)->toBe(0);
});

test('a webhook share, story reply and unsupported message are stored with what they carry', function () {
    $event = fn (string $mid, array $message): array => ['sender' => ['id' => 'igsid-9'], 'recipient' => ['id' => 'ig-me'], 'timestamp' => 1760608800000, 'message' => ['mid' => $mid, ...$message]];

    HandleMetaWebhook::dispatchSync(['object' => 'instagram', 'entry' => [['id' => 'ig-me', 'time' => 1760608800000, 'messaging' => [
        $event('mid-share', ['attachments' => [['type' => 'ig_post', 'payload' => ['url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1', 'title' => 'Autumn drop', 'ig_post_media_id' => '1']]]]),
        $event('mid-story', ['text' => 'Love it', 'reply_to' => ['story' => ['url' => 'https://cdn.example/story', 'id' => 's-1']]]),
        $event('mid-unsupported', ['is_unsupported' => true]),
    ]]]]);

    $content = InboxMessage::query()->pluck('meta', 'platform_message_id')->map(fn (?array $meta): ?string => data_get($meta, 'content.type'));

    expect($content->all())->toEqual(['mid-share' => 'shared_post', 'mid-story' => 'story_reply', 'mid-unsupported' => 'unsupported'])
        ->and(InboxMessage::query()->where('platform_message_id', 'mid-story')->value('body'))->toBe('Love it');
});

test('a reaction webhook lands on the message it is about and an unreact removes it', function () {
    HandleMetaWebhook::dispatchSync($this->messaging);
    $reaction = fn (string $action): array => ['object' => 'instagram', 'entry' => [['id' => 'ig-me', 'time' => 1760608800000, 'messaging' => [
        ['sender' => ['id' => 'igsid-9'], 'recipient' => ['id' => 'ig-me'], 'timestamp' => 1760608900000, 'reaction' => ['mid' => 'mid-2', 'action' => $action, 'reaction' => 'love', 'emoji' => '❤️']],
        ['sender' => ['id' => 'igsid-9'], 'recipient' => ['id' => 'ig-me'], 'timestamp' => 1760608900000, 'reaction' => ['mid' => 'mid-unknown', 'action' => $action, 'reaction' => 'love', 'emoji' => '❤️']],
    ]]]];

    HandleMetaWebhook::dispatchSync($reaction('react'));

    expect(InboxMessage::query()->where('platform_message_id', 'mid-2')->sole()->meta)->toEqual(['reactions' => [['emoji' => '❤️', 'user_id' => 'igsid-9']]])
        ->and(InboxMessage::query()->count())->toBe(2);

    HandleMetaWebhook::dispatchSync($reaction('unreact'));

    expect(InboxMessage::query()->where('platform_message_id', 'mid-2')->sole()->meta)->toBe([]);
});

test('a Messenger event reaches the Page', function () {
    $page = SocialAccount::factory()->facebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    $page->update(['scopes' => [...$page->scopes, 'pages_messaging']]);

    HandleMetaWebhook::dispatchSync(['object' => 'page', 'entry' => [['id' => 'page-1', 'time' => 1760608800000, 'messaging' => [
        ['sender' => ['id' => 'psid-1'], 'recipient' => ['id' => 'page-1'], 'timestamp' => 1760608800000, 'message' => ['mid' => 'm_1', 'text' => 'Hello']],
    ]]]]);

    expect(InboxConversation::query()->sole())
        ->social_account_id->toBe($page->id)
        ->platform_conversation_id->toBe('psid-1');
});

test('messaging events are ignored while the inbox is off or the scope is missing', function () {
    config()->set('trypost.inbox.enabled', false);
    Http::fake();

    HandleMetaWebhook::dispatchSync($this->messaging);
    expect(InboxConversation::query()->exists())->toBeFalse();
    Http::assertNothingSent();

    config()->set('trypost.inbox.enabled', true);
    $this->instagram->update(['scopes' => ['instagram_business_basic', 'instagram_business_manage_comments']]);

    HandleMetaWebhook::dispatchSync($this->messaging);
    expect(InboxConversation::query()->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('the connect flow asks for DM scopes only while the inbox is on', function (string $route, string $network, string $scope) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $location = fn (): string => urldecode((string) $this->actingAs($user)->get(route($route))->headers->get('Location'));

    config()->set('trypost.inbox.enabled', false);
    expect($location())->not->toContain($scope);

    config()->set('trypost.inbox.enabled', true);
    expect($location())->toContain($scope);
})->with([
    'instagram' => ['app.social.instagram.connect', 'instagram', 'instagram_business_manage_messages'],
    'instagram via facebook' => ['app.social.instagram-facebook.connect', 'instagram', 'instagram_manage_messages'],
    'facebook' => ['app.social.facebook.connect', 'facebook', 'pages_messaging'],
]);

test('the webhook subscription adds the message fields once DMs are granted', function () {
    $graph = config('trypost.platforms.facebook.graph_api');
    $page = SocialAccount::factory()->facebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    Http::fake([
        "{$graph}/page-1/subscribed_apps" => Http::response(['success' => true]),
        config('trypost.platforms.instagram.graph_api').'/me/subscribed_apps' => Http::response(['success' => true]),
    ]);

    (new SubscribeInboxWebhooks($page->id))->handle();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'page-1/subscribed_apps') && $request['subscribed_fields'] === 'feed,mention');

    $page->update(['scopes' => [...$page->scopes, 'pages_messaging']]);
    (new SubscribeInboxWebhooks($page->id))->handle();
    (new SubscribeInboxWebhooks($this->instagram->id))->handle();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), 'page-1/subscribed_apps') && $request['subscribed_fields'] === 'feed,mention,messages,message_echoes');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/me/subscribed_apps') && $request['subscribed_fields'] === 'comments,live_comments,messages');
});

test('a Page subscription keeps the fields the app already has there', function (Platform $platform, array $existing, string $expected) {
    config()->set('services.facebook.client_id', 'our-app');
    $graph = config('trypost.platforms.facebook.graph_api');
    $account = $platform === Platform::Facebook
        ? SocialAccount::factory()->facebook()->withDirectMessageScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null])
        : SocialAccount::factory()->instagramFacebook()->withInboxScopes()->create(['meta' => ['page_id' => 'page-1'], 'token_expires_at' => null]);
    Http::swap(new HttpFactory);
    Http::fake(["{$graph}/page-1/subscribed_apps*" => Http::sequence()
        ->push(['data' => [
            ['id' => 'other-app', 'subscribed_fields' => ['leadgen']],
            ['id' => 'our-app', 'subscribed_fields' => $existing],
        ]])
        ->push(['success' => true])]);

    (new SubscribeInboxWebhooks($account->id))->handle();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['subscribed_fields'] === $expected);
})->with([
    'messenger keeps feed set by the Instagram page' => [Platform::Facebook, ['feed'], 'feed,mention,messages,message_echoes'],
    'instagram via facebook keeps the Page messages' => [Platform::InstagramFacebook, ['messages', 'message_echoes'], 'messages,message_echoes,feed'],
]);

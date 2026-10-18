<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Exceptions\PlatformUnavailableException;
use App\Jobs\Inbox\HandleMetaWebhook;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Jobs\Post\ImportExternalPostMedia;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\InstagramInboxDriver;
use App\Support\Inbox\InboxMediaSlots;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    $this->facebookGraph = config('trypost.platforms.instagram-facebook.graph_api');
    $this->instagramGraph = config('trypost.platforms.instagram.graph_api');
});

function instagramFacebookChannel(): SocialAccount
{
    return SocialAccount::factory()->instagramFacebook()->withInboxScopes()->create([
        'platform_user_id' => '17841405726653026', 'username' => 'me', 'token_expires_at' => null,
    ]);
}

function instagramLoginChannel(): SocialAccount
{
    return SocialAccount::factory()->instagram()->withInboxScopes()->create([
        'platform_user_id' => 'app-scoped-1', 'username' => 'me', 'token_expires_at' => now()->addDays(30),
        'meta' => ['ig_user_id' => '17841400000000000'],
    ]);
}

/** @param array<string, mixed> $value */
function instagramWebhook(string $entryId, string $field, array $value): void
{
    HandleMetaWebhook::dispatchSync(['object' => 'instagram', 'entry' => [['id' => $entryId, 'time' => 1760608800, 'changes' => [['field' => $field, 'value' => $value]]]]]);
}

test('a caption mention fetches the mentioned media', function () {
    instagramFacebookChannel();
    Http::fake(["{$this->facebookGraph}/17841405726653026*" => Http::response(['mentioned_media' => [
        'id' => '17918195224117851', 'caption' => 'Shoutout @me', 'media_type' => 'IMAGE',
        'media_url' => 'https://cdn/p.jpg', 'timestamp' => '2026-10-16T10:00:00+0000', 'username' => 'fan',
    ], 'id' => '17841405726653026'])]);

    instagramWebhook('17841405726653026', 'mentions', ['media_id' => '17918195224117851']);

    $conversation = InboxConversation::query()->sole();
    $message = InboxMessage::query()->sole();

    expect($conversation->kind)->toBe(ConversationKind::Mention)
        ->and($conversation->platform_conversation_id)->toBe('17918195224117851')
        ->and($conversation->participant_username)->toBe('fan')
        ->and($conversation->subject_text)->toBe('Shoutout @me')
        ->and($message->platform_message_id)->toBe('17918195224117851')
        ->and($message->body)->toBe('Shoutout @me')
        ->and($message->author_username)->toBe('fan')
        ->and(collect($message->meta)->except(InboxMediaSlots::PENDING)->all())->toEqual(['media_id' => '17918195224117851']);
    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'mentioned_media.media_id(17918195224117851)'));
});

test('a comment mention fetches the mentioned comment', function () {
    instagramFacebookChannel();
    Http::fake(["{$this->facebookGraph}/17841405726653026*" => Http::response(['mentioned_comment' => [
        'id' => '17894227972186120', 'text' => '@me what do you think?', 'timestamp' => '2026-10-16T10:05:00+0000',
        'from' => ['id' => '17841400000000099', 'username' => 'commenter'],
        'media' => ['id' => '17918195224117851', 'caption' => 'A post', 'media_url' => 'https://cdn/p.jpg', 'permalink' => 'https://www.instagram.com/p/x/', 'username' => 'fan'],
    ], 'id' => '17841405726653026'])]);

    instagramWebhook('17841405726653026', 'mentions', ['comment_id' => '17894227972186120', 'media_id' => '17918195224117851']);

    $message = InboxMessage::query()->sole();

    expect($message->platform_message_id)->toBe('17894227972186120')
        ->and($message->body)->toBe('@me what do you think?')
        ->and($message->author_username)->toBe('commenter')
        ->and($message->author_platform_id)->toBe('17841400000000099')
        ->and($message->conversation->platform_conversation_id)->toBe('17918195224117851')
        ->and($message->conversation->subject_text)->toBe('A post')
        ->and($message->conversation->subject_url)->toBe('https://www.instagram.com/p/x/')
        ->and($message->meta)->toEqual(['media_id' => '17918195224117851', 'comment_id' => '17894227972186120']);
    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'mentioned_comment.comment_id(17894227972186120)')
        && str_contains(urldecode($request->url()), 'from{id,username}'));
});

test('a mention that cannot be read is dropped', function () {
    instagramFacebookChannel();
    Http::fake(["{$this->facebookGraph}/*" => Http::response(['error' => ['code' => 100, 'message' => 'comments disabled']], 400)]);

    instagramWebhook('17841405726653026', 'mentions', ['comment_id' => '1', 'media_id' => '2']);

    expect(InboxMessage::query()->count())->toBe(0);
});

test('a mention webhook event is dropped while the inbox is off', function () {
    config()->set('trypost.inbox.enabled', false);
    instagramFacebookChannel();
    Http::fake(['*' => Http::response(['mentioned_media' => ['id' => '17918195224117851', 'caption' => 'Hi @me', 'username' => 'fan', 'timestamp' => '2026-10-16T10:00:00+0000']])]);

    instagramWebhook('17841405726653026', 'mentions', ['media_id' => '17918195224117851']);

    expect(InboxConversation::query()->exists())->toBeFalse();
});

test('an Instagram Login comment on someone else media is a mention', function () {
    instagramLoginChannel();
    Http::fake(["{$this->instagramGraph}/999*" => Http::response(['error' => ['code' => 10, 'message' => 'no permission']], 400)]);

    instagramWebhook('17841400000000000', 'comments', [
        'id' => '555', 'from' => ['id' => '42', 'username' => 'fan'], 'text' => '@me hi', 'media' => ['id' => '999', 'media_product_type' => 'FEED'],
    ]);

    $message = InboxMessage::query()->sole();

    expect($message->conversation->kind)->toBe(ConversationKind::Mention)
        ->and($message->conversation->platform_conversation_id)->toBe('999')
        ->and($message->conversation->participant_username)->toBe('fan')
        ->and($message->author_username)->toBe('fan')
        ->and($message->meta)->toEqual(['media_id' => '999', 'comment_id' => '555']);
});

test('an Instagram Login comment on media owned by someone else by username is a mention', function () {
    instagramLoginChannel();
    Http::fake(["{$this->instagramGraph}/999*" => Http::response(['id' => '999', 'username' => 'stranger'])]);

    instagramWebhook('17841400000000000', 'comments', [
        'id' => '557', 'from' => ['id' => '42', 'username' => 'fan'], 'text' => '@me hi', 'media' => ['id' => '999', 'media_product_type' => 'FEED'],
    ]);

    expect(InboxConversation::query()->sole()->kind)->toBe(ConversationKind::Mention);
});

test('an Instagram Login comment on the channel media stays a comment', function () {
    $account = instagramLoginChannel();
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => '999']);
    Http::fake();

    instagramWebhook('17841400000000000', 'comments', [
        'id' => '556', 'from' => ['id' => '42', 'username' => 'fan'], 'text' => 'nice', 'media' => ['id' => '999', 'media_product_type' => 'FEED'],
    ]);

    expect(InboxMessage::query()->sole()->conversation->kind)->toBe(ConversationKind::Comment);
    Http::assertNothingSent();
});

test('replying to a comment mention posts to the mentions edge', function () {
    $account = instagramFacebookChannel();
    $conversation = InboxConversation::factory()->create([
        'social_account_id' => $account->id, 'kind' => ConversationKind::Mention,
        'platform_conversation_id' => '17918195224117851', 'platform_conversation_hash' => InboxConversation::hashOf('17918195224117851'),
    ]);
    $parent = InboxMessage::factory()->for($conversation, 'conversation')->create([
        'platform_message_id' => '17894227972186120',
        'platform_message_hash' => InboxConversation::hashOf('17894227972186120'),
        'meta' => ['media_id' => '17918195224117851', 'comment_id' => '17894227972186120'],
    ]);
    Http::fake(["{$this->facebookGraph}/17841405726653026/mentions" => Http::response(['id' => '17846319838228163'])]);

    $sent = app(InstagramInboxDriver::class)->reply($account, $parent, 'Thanks!');

    expect($sent->platformMessageId)->toBe('17846319838228163');
    Http::assertSent(fn (Request $request): bool => $request['media_id'] === '17918195224117851'
        && $request['comment_id'] === '17894227972186120'
        && $request['message'] === 'Thanks!');
});

test('replying to a caption mention sends no comment id', function () {
    $account = instagramLoginChannel();
    $conversation = InboxConversation::factory()->create([
        'social_account_id' => $account->id, 'kind' => ConversationKind::Mention,
        'platform_conversation_id' => '777', 'platform_conversation_hash' => InboxConversation::hashOf('777'),
    ]);
    $parent = InboxMessage::factory()->for($conversation, 'conversation')->create([
        'platform_message_id' => '777', 'platform_message_hash' => InboxConversation::hashOf('777'), 'meta' => ['media_id' => '777'],
    ]);
    Http::fake(["{$this->instagramGraph}/17841400000000000/mentions" => Http::response(['id' => 'c-1'])]);

    app(InstagramInboxDriver::class)->reply($account, $parent, 'Thanks!');

    Http::assertSent(fn (Request $request): bool => $request['media_id'] === '777' && blank($request['comment_id'] ?? null));
});

test('instagram mentions are never backfilled', function () {
    $account = instagramLoginChannel();
    Http::fake();

    expect(app(InstagramInboxDriver::class)->kinds())->toBe([ConversationKind::Comment, ConversationKind::Mention, ConversationKind::DirectMessage])
        ->and(app(InstagramInboxDriver::class)->poll($account, ConversationKind::Mention, 'c', CarbonImmutable::parse('2026-09-16'))->messages)->toBe([]);
    Http::assertNothingSent();
});

test('an Instagram Login comment on the channel media stays a comment after the account is renamed', function () {
    $account = instagramLoginChannel();
    $account->update(['username' => 'old-name']);
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class, ImportExternalPostMedia::class]);
    Http::fake(["{$this->instagramGraph}/999*" => Http::response(['id' => '999', 'owner' => ['id' => '17841400000000000'], 'media_type' => 'IMAGE', 'caption' => 'Ours', 'timestamp' => '2026-10-14T10:00:00+0000'])]);

    instagramWebhook('17841400000000000', 'comments', [
        'id' => '558', 'from' => ['id' => '42', 'username' => 'fan'], 'text' => 'nice', 'media' => ['id' => '999', 'media_product_type' => 'FEED'],
    ]);

    expect(InboxMessage::query()->sole()->conversation->kind)->toBe(ConversationKind::Comment);
    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'fields=id,owner{id}'));
});

test('an outage while checking who owns the media retries the delivery instead of filing a mention', function () {
    instagramLoginChannel();
    Http::fake(["{$this->instagramGraph}/999*" => Http::response(['error' => ['code' => 2, 'message' => 'temporary']], 503)]);

    expect(fn () => instagramWebhook('17841400000000000', 'comments', [
        'id' => '559', 'from' => ['id' => '42', 'username' => 'fan'], 'text' => 'nice', 'media' => ['id' => '999', 'media_product_type' => 'FEED'],
    ]))->toThrow(PlatformUnavailableException::class)
        ->and(InboxMessage::query()->count())->toBe(0);
});

test('media that does not exist for the channel is someone else media, so the comment is a mention', function () {
    instagramLoginChannel();
    Http::fake(["{$this->instagramGraph}/999*" => Http::response(['error' => ['code' => 100, 'error_subcode' => 33, 'message' => 'Unsupported get request']], 400)]);

    instagramWebhook('17841400000000000', 'comments', [
        'id' => '560', 'from' => ['id' => '42', 'username' => 'fan'], 'text' => '@me hi', 'media' => ['id' => '999', 'media_product_type' => 'FEED'],
    ]);

    expect(InboxMessage::query()->sole()->conversation->kind)->toBe(ConversationKind::Mention);
});

test('an undocumented error while checking who owns the media files nothing', function () {
    instagramLoginChannel();
    Http::fake(["{$this->instagramGraph}/999*" => Http::response(['error' => ['code' => 100, 'error_subcode' => 2207023, 'message' => 'Unknown']], 400)]);

    instagramWebhook('17841400000000000', 'comments', [
        'id' => '562', 'from' => ['id' => '42', 'username' => 'fan'], 'text' => '@me hi', 'media' => ['id' => '999', 'media_product_type' => 'FEED'],
    ]);

    expect(InboxMessage::query()->count())->toBe(0);
});

test('a rate limit while checking who owns the media retries the delivery', function () {
    instagramLoginChannel();
    Http::fake(["{$this->instagramGraph}/999*" => Http::response(['error' => ['code' => 4, 'message' => 'Application request limit reached']], 400)]);

    expect(fn () => instagramWebhook('17841400000000000', 'comments', [
        'id' => '561', 'from' => ['id' => '42', 'username' => 'fan'], 'text' => '@me hi', 'media' => ['id' => '999', 'media_product_type' => 'FEED'],
    ]))->toThrow(PlatformUnavailableException::class);
});

test('a channel connected through facebook reads the media it was tagged in as mentions, page by page', function () {
    $account = instagramFacebookChannel();
    Http::fake(["{$this->facebookGraph}/17841405726653026/tags*" => Http::sequence()
        ->push(['data' => [[
            'id' => '18011111111111111', 'caption' => 'With @me', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/t.jpg',
            'permalink' => 'https://www.instagram.com/p/tag/', 'timestamp' => now()->subDay()->toIso8601String(), 'username' => 'friend',
        ]], 'paging' => ['cursors' => ['before' => 'b1', 'after' => 'a1']]])
        ->push(['data' => [], 'paging' => ['cursors' => []]])]);

    $result = app(InstagramInboxDriver::class)->poll($account, ConversationKind::Mention, null, now()->subDays(30)->toImmutable());

    expect($result->messages)->toHaveCount(1)
        ->and($result->messages[0]->kind)->toBe(ConversationKind::Mention)
        ->and($result->messages[0]->platformMessageId)->toBe('18011111111111111')
        ->and($result->messages[0]->authorUsername)->toBe('friend')
        ->and($result->messages[0]->body)->toBe('With @me')
        ->and($result->messages[0]->meta)->toBe(['media_id' => '18011111111111111', 'tagged' => true])
        ->and($result->partial)->toBeFalse();
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/tags') && data_get($request->data(), 'after') === 'a1');
});

test('tagged media older than the last poll ends the read', function () {
    $account = instagramFacebookChannel();
    Http::fake(["{$this->facebookGraph}/17841405726653026/tags*" => Http::response(['data' => [[
        'id' => '18022222222222222', 'caption' => 'Old', 'media_type' => 'IMAGE', 'timestamp' => '2026-10-01T10:00:00+0000', 'username' => 'friend',
    ]], 'paging' => ['cursors' => ['after' => 'a1']]])]);

    $result = app(InstagramInboxDriver::class)->poll($account, ConversationKind::Mention, '2026-10-10T00:00:00+00:00', now()->subDays(30)->toImmutable());

    expect($result->messages)->toBe([]);
    Http::assertSentCount(1);
});

test('a tag polled is stored as a mention with no post', function () {
    $account = instagramFacebookChannel();
    Http::fake(["{$this->facebookGraph}/17841405726653026/tags*" => Http::response(['data' => [[
        'id' => '18033333333333333', 'caption' => 'Tagged', 'media_type' => 'IMAGE', 'timestamp' => now()->subHour()->toIso8601String(), 'username' => 'friend',
    ]]])]);

    (new SyncInboxAccount($account->id, ConversationKind::Mention))->handle();

    $conversation = InboxConversation::query()->sole();

    expect($conversation->kind)->toBe(ConversationKind::Mention)
        ->and($conversation->post_id)->toBeNull()
        ->and($conversation->subject_text)->toBe('Tagged');
});

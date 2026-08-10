<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Events\TelegramChannelConnected;
use App\Events\TelegramConnectFailed;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\Telegram\TelegramConnectCode;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config([
        'trypost.platforms.telegram.bot_token' => 'TESTTOKEN',
        'trypost.platforms.telegram.bot_username' => 'TryPostBot',
        'trypost.platforms.telegram.webhook_secret' => 'shh-secret',
    ]);

    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create([
        'current_workspace_id' => $this->workspace->id,
        'account_id' => $this->workspace->account_id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->refresh();
});

function telegramUpdate(string $code, array $chat = []): array
{
    return [
        'channel_post' => [
            'message_id' => 5,
            'chat' => array_merge([
                'id' => -1001234567890,
                'title' => 'My Channel',
                'username' => 'mychannel',
                'type' => 'channel',
            ], $chat),
            'text' => "/connect {$code}",
        ],
    ];
}

it('issues a signed connect code carrying the workspace', function () {
    $response = $this->actingAs($this->user)
        ->postJson(route('app.social.telegram.connect'))
        ->assertOk()
        ->assertJsonStructure(['code', 'nonce', 'bot_username', 'expires_at']);

    expect($response->json('bot_username'))->toBe('TryPostBot');
    expect(data_get(TelegramConnectCode::decode($response->json('code')), 'workspace_id'))
        ->toBe($this->workspace->id);
    expect($response->json('nonce'))
        ->toBe(data_get(TelegramConnectCode::decode($response->json('code')), 'nonce'));
});

it('links the channel when the webhook receives a matching /connect', function () {
    Http::fake();
    $code = TelegramConnectCode::issue($this->workspace->id, now()->addMinutes(15));

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate($code))
        ->assertNoContent();

    $account = SocialAccount::where('workspace_id', $this->workspace->id)
        ->where('platform', Platform::Telegram)
        ->first();

    expect($account)->not->toBeNull();
    expect($account->platform_user_id)->toBe('-1001234567890');
    expect($account->display_name)->toBe('My Channel');
    expect($account->username)->toBe('mychannel');
    expect(data_get($account->meta, 'chat_id'))->toBe('-1001234567890');
    expect(data_get($account->meta, 'connect_nonce'))
        ->toBe(data_get(TelegramConnectCode::decode($code), 'nonce'));
});

it('stores the channel photo as the account avatar on connect', function () {
    Storage::fake();

    Http::fake([
        '*/botTESTTOKEN/getChat*' => Http::response(['ok' => true, 'result' => ['photo' => ['big_file_id' => 'BIGFILE']]], 200),
        '*/botTESTTOKEN/getFile*' => Http::response(['ok' => true, 'result' => ['file_path' => 'photos/file_1.jpg']], 200),
        '*/file/botTESTTOKEN/photos/file_1.jpg' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $code = TelegramConnectCode::issue($this->workspace->id, now()->addMinutes(15));

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate($code))
        ->assertNoContent();

    $account = SocialAccount::where('platform', Platform::Telegram)->first();

    expect($account->getRawOriginal('avatar_url'))->not->toBeNull();
});

it('links a private channel that has no username', function () {
    Http::fake();
    $code = TelegramConnectCode::issue($this->workspace->id, now()->addMinutes(15));

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate($code, ['username' => null]))
        ->assertNoContent();

    $account = SocialAccount::where('platform', Platform::Telegram)->first();

    expect($account->username)->toBeNull();
    expect($account->display_name)->toBe('My Channel');
    expect(data_get($account->meta, 'username'))->toBeNull();
});

it('does not connect a second telegram channel when one is already connected', function () {
    config(['trypost.self_hosted' => false]);
    Event::fake([TelegramConnectFailed::class]);

    SocialAccount::factory()->telegram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '-1009999999999',
    ]);

    $code = TelegramConnectCode::issue($this->workspace->id, now()->addMinutes(15));

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate($code))
        ->assertNoContent();

    expect($this->workspace->socialAccounts()->count())->toBe(1);
    expect(
        SocialAccount::where('platform', Platform::Telegram)->where('platform_user_id', '-1001234567890')->exists()
    )->toBeFalse();

    Event::assertDispatched(
        TelegramConnectFailed::class,
        fn (TelegramConnectFailed $event): bool => $event->workspaceId === $this->workspace->id
            && $event->reason === 'network_taken',
    );
});

it('connects a second telegram channel in self-hosted mode', function () {
    Http::fake();
    config(['trypost.self_hosted' => true]);

    SocialAccount::factory()->telegram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '-1009999999999',
    ]);

    $code = TelegramConnectCode::issue($this->workspace->id, now()->addMinutes(15));

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate($code))
        ->assertNoContent();

    expect($this->workspace->socialAccounts()->count())->toBe(2);
    expect(
        SocialAccount::where('platform', Platform::Telegram)->where('platform_user_id', '-1001234567890')->exists()
    )->toBeTrue();
});

it('reconnects an existing telegram channel', function () {
    Http::fake();
    config(['trypost.self_hosted' => false]);

    SocialAccount::factory()->telegram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '-1001234567890',
    ]);

    $code = TelegramConnectCode::issue($this->workspace->id, now()->addMinutes(15));

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate($code))
        ->assertNoContent();

    expect($this->workspace->socialAccounts()->count())->toBe(1);
    expect(
        SocialAccount::where('platform', Platform::Telegram)->where('platform_user_id', '-1001234567890')->count()
    )->toBe(1);
});

it('consumes the code once so it cannot be replayed for another chat', function () {
    Http::fake();
    $code = TelegramConnectCode::issue($this->workspace->id, now()->addMinutes(15));

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate($code, ['id' => -1001111111111]))
        ->assertNoContent();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate($code, ['id' => -1002222222222]))
        ->assertNoContent();

    expect(SocialAccount::where('platform', Platform::Telegram)->count())->toBe(1);
    expect(
        SocialAccount::where('platform', Platform::Telegram)->where('platform_user_id', '-1001111111111')->exists()
    )->toBeTrue();
});

it('stores reaction counts on the matching post from a reaction update', function () {
    $account = SocialAccount::factory()->telegram()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $postPlatform = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Telegram,
        'platform_post_id' => '42',
    ]);

    $update = [
        'message_reaction_count' => [
            'chat' => ['id' => -1001234567890],
            'message_id' => 42,
            'reactions' => [
                ['type' => ['type' => 'emoji', 'emoji' => '👍'], 'total_count' => 12],
                ['type' => ['type' => 'emoji', 'emoji' => '❤️'], 'total_count' => 5],
            ],
        ],
    ];

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), $update)
        ->assertNoContent();

    expect(data_get($postPlatform->fresh()->meta, 'reactions'))
        ->toBe([['type' => '👍', 'count' => 12], ['type' => '❤️', 'count' => 5]]);
});

it('does not store reactions on a post from a different channel with the same message id', function () {
    $otherAccount = SocialAccount::factory()->telegram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '-1009999999999',
        'meta' => ['chat_id' => '-1009999999999', 'username' => 'other', 'type' => 'channel'],
    ]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $postPlatform = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $otherAccount->id,
        'platform' => Platform::Telegram,
        'platform_post_id' => '42',
    ]);

    $update = [
        'message_reaction_count' => [
            'chat' => ['id' => -1001234567890],
            'message_id' => 42,
            'reactions' => [['type' => ['type' => 'emoji', 'emoji' => '👍'], 'total_count' => 9]],
        ],
    ];

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), $update)
        ->assertNoContent();

    expect(data_get($postPlatform->fresh()->meta, 'reactions'))->toBeNull();
});

it('labels custom emoji reactions with a fallback', function () {
    $account = SocialAccount::factory()->telegram()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $postPlatform = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Telegram,
        'platform_post_id' => '77',
    ]);

    $update = [
        'message_reaction_count' => [
            'chat' => ['id' => -1001234567890],
            'message_id' => 77,
            'reactions' => [['type' => ['type' => 'custom_emoji', 'custom_emoji_id' => '555'], 'total_count' => 3]],
        ],
    ];

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), $update)
        ->assertNoContent();

    expect(data_get($postPlatform->fresh()->meta, 'reactions'))
        ->toBe([['type' => 'Custom', 'count' => 3]]);
});

it('connects without an avatar when the channel has no photo', function () {
    Http::fake([
        '*/botTESTTOKEN/getChat*' => Http::response(['ok' => true, 'result' => []], 200),
    ]);

    $code = TelegramConnectCode::issue($this->workspace->id, now()->addMinutes(15));

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate($code))
        ->assertNoContent();

    $account = SocialAccount::where('platform', Platform::Telegram)->first();

    expect($account)->not->toBeNull();
    expect($account->getRawOriginal('avatar_url'))->toBeNull();
});

it('rejects the webhook without the secret token', function () {
    $code = TelegramConnectCode::issue($this->workspace->id, now()->addMinutes(15));

    $this->postJson(route('telegram.webhook'), telegramUpdate($code))
        ->assertForbidden();
});

it('ignores the webhook for a tampered or expired code', function () {
    $expired = TelegramConnectCode::issue($this->workspace->id, now()->subMinute());

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate($expired))
        ->assertNoContent();

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate('not-a-valid-code'))
        ->assertNoContent();

    expect(SocialAccount::where('platform', Platform::Telegram)->count())->toBe(0);
});

it('broadcasts to the workspace with the nonce when a channel connects', function () {
    Event::fake([TelegramChannelConnected::class]);
    Http::fake();

    $code = TelegramConnectCode::issue($this->workspace->id, now()->addMinutes(15));
    $nonce = data_get(TelegramConnectCode::decode($code), 'nonce');

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate($code))
        ->assertNoContent();

    Event::assertDispatched(
        TelegramChannelConnected::class,
        fn (TelegramChannelConnected $event) => $event->workspaceId === $this->workspace->id
            && $event->nonce === $nonce,
    );
});

it('does not broadcast when the code is tampered or already used', function () {
    Event::fake([TelegramChannelConnected::class]);

    $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'shh-secret')
        ->postJson(route('telegram.webhook'), telegramUpdate('not-a-valid-code'))
        ->assertNoContent();

    Event::assertNotDispatched(TelegramChannelConnected::class);
});

it('verifies a connected telegram account via getChat', function () {
    config(['trypost.platforms.telegram.bot_token' => 'TESTTOKEN']);

    $account = SocialAccount::factory()->telegram()->create(['workspace_id' => $this->workspace->id]);

    Http::fake([
        '*/botTESTTOKEN/getChat*' => Http::response(['ok' => true, 'result' => ['id' => -1001234567890]], 200),
    ]);

    expect(app(ConnectionVerifier::class)->verify($account))->toBeTrue();
});

it('throws TokenExpiredException when getChat reports the chat is no longer reachable', function () {
    config(['trypost.platforms.telegram.bot_token' => 'TESTTOKEN']);

    $account = SocialAccount::factory()->telegram()->create(['workspace_id' => $this->workspace->id]);

    Http::fake([
        '*/botTESTTOKEN/getChat*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400),
    ]);

    expect(fn () => app(ConnectionVerifier::class)->verify($account))
        ->toThrow(TokenExpiredException::class);

    // Telegram has no per-account refresh flow (one bot token shared across
    // every connected account) — a confirmed rejection must not trigger a
    // second, identical getChat call against that shared budget.
    Http::assertSentCount(1);
});

it('throws PlatformUnavailableException when getChat fails transiently', function () {
    config(['trypost.platforms.telegram.bot_token' => 'TESTTOKEN']);

    $account = SocialAccount::factory()->telegram()->create(['workspace_id' => $this->workspace->id]);

    Http::fake([
        '*/botTESTTOKEN/getChat*' => Http::response(['ok' => false, 'description' => 'Internal Server Error'], 500),
    ]);

    expect(fn () => app(ConnectionVerifier::class)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

it('throws PlatformUnavailableException, not TokenExpiredException, when the shared bot token is rejected', function () {
    config(['trypost.platforms.telegram.bot_token' => 'TESTTOKEN']);

    $account = SocialAccount::factory()->telegram()->create(['workspace_id' => $this->workspace->id]);

    // 401 means the app-wide TELEGRAM_BOT_TOKEN is misconfigured — every
    // Telegram-connected account would fail identically. It is not evidence
    // that THIS account's connection is broken, so it must not disconnect it.
    Http::fake([
        '*/botTESTTOKEN/getChat*' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
    ]);

    expect(fn () => app(ConnectionVerifier::class)->verify($account))
        ->toThrow(PlatformUnavailableException::class);
});

it('registers the webhook via the artisan command', function () {
    Http::fake([
        '*/botTESTTOKEN/setWebhook' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $this->artisan('telegram:set-webhook')->assertSuccessful();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/setWebhook')
            && $request['secret_token'] === 'shh-secret'
            && str_contains($request['url'], 'telegram/webhook');
    });
});

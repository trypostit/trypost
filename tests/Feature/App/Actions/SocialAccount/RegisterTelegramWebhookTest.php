<?php

declare(strict_types=1);

use App\Actions\SocialAccount\RegisterTelegramWebhook;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'trypost.platforms.telegram.bot_token' => 'TESTTOKEN',
        'trypost.platforms.telegram.webhook_secret' => 'shh-secret',
    ]);
});

test('it registers the webhook with the url, secret and allowed updates', function () {
    Http::fake([
        '*/botTESTTOKEN/setWebhook' => Http::response(['ok' => true, 'result' => true], 200),
    ]);

    $url = RegisterTelegramWebhook::execute();

    expect($url)->toBe(route('telegram.webhook'));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/botTESTTOKEN/setWebhook')
            && $request['url'] === route('telegram.webhook')
            && $request['secret_token'] === 'shh-secret'
            && $request['allowed_updates'] === ['message', 'channel_post', 'message_reaction_count'];
    });
});

test('it throws when the bot token or secret is missing', function () {
    config(['trypost.platforms.telegram.webhook_secret' => '']);

    Http::fake();

    expect(fn () => RegisterTelegramWebhook::execute())->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});

test('it throws when telegram rejects the request', function () {
    Http::fake([
        '*/botTESTTOKEN/setWebhook' => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
    ]);

    expect(fn () => RegisterTelegramWebhook::execute())->toThrow(RuntimeException::class);
});

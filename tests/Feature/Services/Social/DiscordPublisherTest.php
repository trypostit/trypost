<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\DiscordPublishException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Discord\DiscordPublisher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush(); // channel list is cached per guild — isolate between tests
    config([
        'trypost.platforms.discord.bot_token' => 'BOTTOKEN',
        'services.discord.client_id' => '999000111', // bot user id, used by the channel permission check
    ]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '111222333', // guild id
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello Discord',
    ]);

    $this->makePostPlatform = fn (array $meta = ['channel_id' => '444555666']) => PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::Discord,
        'content_type' => ContentType::DiscordMessage,
        'meta' => $meta,
    ]);

    $this->publisher = new DiscordPublisher;
});

function fakeDiscord(array $messageResponse = ['id' => '777'], int $status = 200): void
{
    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*/channels' => Http::response([
            ['id' => '444555666', 'name' => 'general', 'type' => 0],
        ], 200),
        // @everyone (role id == guild id) grants VIEW_CHANNEL + SEND_MESSAGES (1024 + 2048).
        config('trypost.platforms.discord.api').'/guilds/*/roles' => Http::response([
            ['id' => '111222333', 'name' => '@everyone', 'permissions' => '3072'],
        ], 200),
        config('trypost.platforms.discord.api').'/guilds/*/members/*' => Http::response(['roles' => []], 200),
        config('trypost.platforms.discord.api').'/channels/*/messages' => Http::response($messageResponse, $status),
    ]);
}

test('publishes a text-only message and suppresses accidental pings', function () {
    fakeDiscord();

    $result = $this->publisher->publish(($this->makePostPlatform)());

    expect($result['id'])->toBe('777');
    expect($result['url'])->toBe('https://discord.com/channels/111222333/444555666/777');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/messages')) {
            return false; // only the message POST may satisfy this assertion
        }

        return $request->hasHeader('Authorization', 'Bot BOTTOKEN')
            && data_get($request->data(), 'content') === 'Hello Discord'
            // No mention chips → parse is empty, so typed "@everyone" never pings.
            && data_get($request->data(), 'allowed_mentions.parse') === [];
    });
});

test('builds allowed_mentions from explicit mention chips only', function () {
    fakeDiscord();

    $this->publisher->publish(($this->makePostPlatform)([
        'channel_id' => '444555666',
        'mentions' => [
            ['token' => '@everyone', 'label' => '@everyone'],
            ['token' => '<@&10>', 'label' => '@moderators'],
        ],
    ]));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/messages')) {
            return false;
        }
        $allowed = data_get($request->data(), 'allowed_mentions');

        return data_get($allowed, 'parse') === ['everyone']
            && data_get($allowed, 'roles') === ['10']
            && data_get($request->data(), 'content') === "Hello Discord\n\n@everyone <@&10>";
    });
});

test('includes rich embeds with clamped color', function () {
    fakeDiscord(['id' => '888']);

    $this->publisher->publish(($this->makePostPlatform)([
        'channel_id' => '444555666',
        'embeds' => [[
            'title' => 'Release v2',
            'description' => 'Shipped today',
            'color' => '#5865F2',
            'image' => 'https://example.com/banner.png',
        ]],
    ]));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/messages')) {
            return false;
        }
        $embed = data_get($request->data(), 'embeds.0');

        return data_get($embed, 'title') === 'Release v2'
            && data_get($embed, 'color') === hexdec('5865F2')
            && data_get($embed, 'image.url') === 'https://example.com/banner.png';
    });
});

test('uploads media as a multipart attachment', function () {
    $this->post->update([
        'media' => [[
            'id' => 'm1',
            'path' => 'media/2026-01/pic.jpg',
            'url' => 'https://example.com/media/2026-01/pic.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'pic.jpg',
        ]],
    ]);

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'discord_test_'), fn ($f) => file_put_contents($f, str_repeat('x', 1024))));

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*/channels' => Http::response([['id' => '444555666', 'name' => 'general', 'type' => 0]], 200),
        config('trypost.platforms.discord.api').'/guilds/*/roles' => Http::response([['id' => '111222333', 'name' => '@everyone', 'permissions' => '3072']], 200),
        config('trypost.platforms.discord.api').'/guilds/*/members/*' => Http::response(['roles' => []], 200),
        'example.com/*' => Http::response(str_repeat('x', 1024), 200),
        config('trypost.platforms.discord.api').'/channels/*/messages' => Http::response(['id' => '901'], 200),
    ]);

    $result = $this->publisher->publish(($this->makePostPlatform)());

    expect($result['id'])->toBe('901');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/messages')) {
            return false;
        }
        $names = collect($request->data())->pluck('name');

        return $names->contains('payload_json') && $names->contains('files[0]');
    });
});

test('sets the attachment description from image alt text, capped at the platform max', function () {
    $longAlt = str_repeat('a', 2000);

    $this->post->update([
        'media' => [[
            'id' => 'm1',
            'path' => 'media/2026-01/pic.jpg',
            'url' => 'https://example.com/media/2026-01/pic.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'pic.jpg',
            'meta' => ['alt_text' => $longAlt],
        ]],
    ]);

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'discord_test_'), fn ($f) => file_put_contents($f, str_repeat('x', 1024))));

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*/channels' => Http::response([['id' => '444555666', 'name' => 'general', 'type' => 0]], 200),
        config('trypost.platforms.discord.api').'/guilds/*/roles' => Http::response([['id' => '111222333', 'name' => '@everyone', 'permissions' => '3072']], 200),
        config('trypost.platforms.discord.api').'/guilds/*/members/*' => Http::response(['roles' => []], 200),
        'example.com/*' => Http::response(str_repeat('x', 1024), 200),
        config('trypost.platforms.discord.api').'/channels/*/messages' => Http::response(['id' => '902'], 200),
    ]);

    $this->publisher->publish(($this->makePostPlatform)());

    $expectedAlt = mb_substr($longAlt, 0, Platform::Discord->altTextMaxLength());

    Http::assertSent(function ($request) use ($expectedAlt) {
        if (! str_contains($request->url(), '/messages')) {
            return false;
        }
        $payloadPart = collect($request->data())->firstWhere('name', 'payload_json');
        $payload = json_decode(data_get($payloadPart, 'contents'), true);

        return data_get($payload, 'attachments.0.description') === $expectedAlt
            && mb_strlen($expectedAlt) === Platform::Discord->altTextMaxLength();
    });
});

test('omits the attachment description when the image has no alt text', function () {
    $this->post->update([
        'media' => [[
            'id' => 'm1',
            'path' => 'media/2026-01/pic.jpg',
            'url' => 'https://example.com/media/2026-01/pic.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'pic.jpg',
        ]],
    ]);

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'discord_test_'), fn ($f) => file_put_contents($f, str_repeat('x', 1024))));

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*/channels' => Http::response([['id' => '444555666', 'name' => 'general', 'type' => 0]], 200),
        config('trypost.platforms.discord.api').'/guilds/*/roles' => Http::response([['id' => '111222333', 'name' => '@everyone', 'permissions' => '3072']], 200),
        config('trypost.platforms.discord.api').'/guilds/*/members/*' => Http::response(['roles' => []], 200),
        'example.com/*' => Http::response(str_repeat('x', 1024), 200),
        config('trypost.platforms.discord.api').'/channels/*/messages' => Http::response(['id' => '903'], 200),
    ]);

    $this->publisher->publish(($this->makePostPlatform)());

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/messages')) {
            return false;
        }
        $payloadPart = collect($request->data())->firstWhere('name', 'payload_json');
        $payload = json_decode(data_get($payloadPart, 'contents'), true);

        return ! array_key_exists('description', data_get($payload, 'attachments.0'));
    });
});

test('does not set a description on a non-image attachment even if it carries alt text', function () {
    $this->post->update([
        'media' => [[
            'id' => 'm1',
            'path' => 'media/2026-01/clip.mp4',
            'url' => 'https://example.com/media/2026-01/clip.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'clip.mp4',
            'meta' => ['alt_text' => 'alt text must not be sent for a video'],
        ]],
    ]);

    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*/channels' => Http::response([['id' => '444555666', 'name' => 'general', 'type' => 0]], 200),
        config('trypost.platforms.discord.api').'/guilds/*/roles' => Http::response([['id' => '111222333', 'name' => '@everyone', 'permissions' => '3072']], 200),
        config('trypost.platforms.discord.api').'/guilds/*/members/*' => Http::response(['roles' => []], 200),
        'example.com/*' => Http::response(str_repeat('x', 1024), 200),
        config('trypost.platforms.discord.api').'/channels/*/messages' => Http::response(['id' => '904'], 200),
    ]);

    $this->publisher->publish(($this->makePostPlatform)());

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/messages')) {
            return false;
        }
        $payloadPart = collect($request->data())->firstWhere('name', 'payload_json');
        $payload = json_decode(data_get($payloadPart, 'contents'), true);

        return ! array_key_exists('description', data_get($payload, 'attachments.0'));
    });
});

test('throws when no channel is selected', function () {
    expect(fn () => $this->publisher->publish(($this->makePostPlatform)(meta: [])))
        ->toThrow(DiscordPublishException::class);
});

test('throws when the channel is not part of the guild', function () {
    fakeDiscord();

    expect(fn () => $this->publisher->publish(($this->makePostPlatform)(['channel_id' => '999999999'])))
        ->toThrow(DiscordPublishException::class);
});

test('retries (does not permanently fail) when the channel lookup is transiently down', function () {
    Http::fake([
        config('trypost.platforms.discord.api').'/guilds/*/channels' => Http::response('upstream down', 500),
        config('trypost.platforms.discord.api').'/channels/*/messages' => Http::response(['id' => '777'], 200),
    ]);

    // A 5xx on the channel guard must surface as PlatformUnavailableException so
    // the publish job reschedules — not a permanent "channel not in server" fail.
    expect(fn () => $this->publisher->publish(($this->makePostPlatform)()))
        ->toThrow(PlatformUnavailableException::class);
});

test('maps a missing-permission error to a publish exception', function () {
    fakeDiscord(['code' => 50013, 'message' => 'Missing Permissions'], 403);

    expect(fn () => $this->publisher->publish(($this->makePostPlatform)()))
        ->toThrow(DiscordPublishException::class);
});

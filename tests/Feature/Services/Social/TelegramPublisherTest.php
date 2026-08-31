<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\TelegramPublishException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\Telegram\TelegramPublisher;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['trypost.platforms.telegram.bot_token' => 'TESTTOKEN']);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->telegram()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello world',
    ]);

    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::Telegram,
        'content_type' => ContentType::TelegramPost,
    ]);

    $this->publisher = new TelegramPublisher;
});

function telegramOk(array $result): array
{
    return ['ok' => true, 'result' => $result];
}

test('telegram publisher sends a text-only message', function () {
    Http::fake([
        '*/botTESTTOKEN/sendMessage' => Http::response(telegramOk(['message_id' => 42]), 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('42');
    expect($result['url'])->toBe('https://t.me/mychannel/42');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sendMessage')
            && $request['chat_id'] === '-1001234567890'
            && $request['text'] === 'Hello world'
            && $request['parse_mode'] === 'HTML';
    });
});

test('telegram publisher sends a single image with caption', function () {
    $this->post->update([
        'content' => 'A photo',
        'media' => [[
            'id' => 'm1',
            'path' => 'media/2026-01/pic.jpg',
            'url' => 'https://cdn.test/pic.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'pic.jpg',
        ]],
    ]);

    Http::fake([
        '*/botTESTTOKEN/sendPhoto' => Http::response(telegramOk(['message_id' => 7]), 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sendPhoto')
            && str_contains($request['photo'], 'pic.jpg')
            && $request['caption'] === 'A photo'
            && $request['parse_mode'] === 'HTML';
    });
});

test('telegram publisher sends a single video', function () {
    $this->post->update([
        'content' => 'A clip',
        'media' => [[
            'id' => 'm1',
            'path' => 'media/clip.mp4',
            'url' => 'https://cdn.test/clip.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'clip.mp4',
        ]],
    ]);

    Http::fake([
        '*/botTESTTOKEN/sendVideo' => Http::response(telegramOk(['message_id' => 8]), 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sendVideo')
            && str_contains($request['video'], 'clip.mp4')
            && $request['caption'] === 'A clip';
    });
});

test('telegram publisher sends a non-image, non-video file as a document', function () {
    $this->post->update([
        'content' => 'A file',
        'media' => [[
            'id' => 'm1',
            'path' => 'media/report.pdf',
            'url' => 'https://cdn.test/report.pdf',
            'mime_type' => 'application/pdf',
            'original_filename' => 'report.pdf',
        ]],
    ]);

    Http::fake([
        '*/botTESTTOKEN/sendDocument' => Http::response(telegramOk(['message_id' => 9]), 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/sendDocument')
            && str_contains($request['document'], 'report.pdf');
    });
});

test('telegram publisher sends multiple media as an album', function () {
    $this->post->update([
        'content' => 'Album',
        'media' => [
            ['id' => 'm1', 'path' => 'media/a.jpg', 'url' => 'https://cdn.test/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg'],
            ['id' => 'm2', 'path' => 'media/b.jpg', 'url' => 'https://cdn.test/b.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'b.jpg'],
        ],
    ]);

    Http::fake([
        '*/botTESTTOKEN/sendMediaGroup' => Http::response(telegramOk([['message_id' => 11], ['message_id' => 12]]), 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('11');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/sendMediaGroup')) {
            return false;
        }
        $media = json_decode($request['media'], true);

        return count($media) === 2
            && $media[0]['type'] === 'photo'
            && $media[0]['caption'] === 'Album'
            && ! isset($media[1]['caption']);
    });
});

test('telegram publisher sends long text as its own message after media', function () {
    $longText = str_repeat('x', 1500); // over the 1024 caption limit

    $this->post->update([
        'content' => $longText,
        'media' => [[
            'id' => 'm1', 'path' => 'media/p.jpg', 'url' => 'https://cdn.test/p.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'p.jpg',
        ]],
    ]);

    Http::fake([
        '*/botTESTTOKEN/sendPhoto' => Http::response(telegramOk(['message_id' => 5]), 200),
        '*/botTESTTOKEN/sendMessage' => Http::response(telegramOk(['message_id' => 6]), 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    // Photo carries no caption (too long); the text follows as a separate message.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendPhoto') && $request['caption'] === '');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage') && $request['text'] === $longText);
});

test('telegram publisher rejects content over the 4096 limit', function () {
    $this->post->update(['content' => str_repeat('x', 4097)]);

    Http::fake();

    expect(fn () => $this->publisher->publish($this->postPlatform))->toThrow(Exception::class);

    Http::assertNothingSent();
});

test('telegram publisher throws on a non-ok response', function () {
    Http::fake([
        '*/botTESTTOKEN/sendMessage' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot is not a member of the channel chat'], 403),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))->toThrow(TelegramPublishException::class);
});

test('telegram publisher builds a private-channel url when there is no username', function () {
    $this->socialAccount->update(['username' => null, 'meta' => ['chat_id' => '-1009876543210', 'type' => 'channel']]);

    Http::fake([
        '*/botTESTTOKEN/sendMessage' => Http::response(telegramOk(['message_id' => 99]), 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['url'])->toBe('https://t.me/c/9876543210/99');
});

test('telegram publisher keeps links intact', function () {
    config()->set('trypost.platforms.x.defuse_links', true);

    $this->post->update(['content' => 'New post: https://acme.com/blog']);

    Http::fake(['*/botTESTTOKEN/sendMessage' => Http::response(telegramOk(['message_id' => 42]), 200)]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
        && $request['text'] === 'New post: https://acme.com/blog');
});

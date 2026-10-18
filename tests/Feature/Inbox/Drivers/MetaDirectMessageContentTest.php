<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Http\Resources\Api\InboxMessageResource;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxMessage;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Services\Inbox\Meta\MetaDirectMessages;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    $this->travelTo('2026-10-16 12:00:00');
    $this->instagram = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => 'app-scoped-1',
        'meta' => ['ig_user_id' => 'ig-me'],
        'token_expires_at' => null,
    ]);
    $this->service = app(MetaDirectMessages::class);
    $this->igGraph = config('trypost.platforms.instagram.graph_api');
    $this->pollWith = function (array $detail): array {
        Http::fake([
            "{$this->igGraph}/me/conversations*" => Http::response(['data' => [['id' => 'conv-1', 'updated_time' => '2026-10-16T11:00:00+0000']]]),
            "{$this->igGraph}/conv-1*" => Http::response(['messages' => ['data' => [['id' => 'mid-1', 'created_time' => '2026-10-16T11:00:00+0000']]]]),
            "{$this->igGraph}/mid-1*" => Http::response(['id' => 'mid-1', 'created_time' => '2026-10-16T11:00:00+0000', 'from' => ['id' => 'igsid-9', 'username' => 'ana.fan'], 'to' => ['data' => [['id' => 'ig-me']]], 'message' => '', ...$detail]),
            "{$this->igGraph}/igsid-9*" => Http::response(['username' => 'ana.fan']),
        ]);

        return $this->service->poll($this->instagram, null, CarbonImmutable::parse('2026-09-16'))->messages;
    };
    $this->event = fn (array $message): array => [
        'sender' => ['id' => 'igsid-9'],
        'recipient' => ['id' => 'ig-me'],
        'timestamp' => 1760608800000,
        'message' => ['mid' => 'mid-1', ...$message],
    ];
});

test('the poll asks each message for what it carries', function () {
    ($this->pollWith)(['message' => 'Hi']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/mid-1')
        && $request['fields'] === 'id,created_time,from,to,message,attachments,shares{link,name,description},story,reactions,is_unsupported');
});

test('the poll maps what a message carries', function (array $detail, array $media, ?array $content) {
    $messages = ($this->pollWith)($detail);

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->media)->toEqual($media)
        ->and(data_get($messages[0]->meta, 'content'))->toEqual($content);
})->with([
    'a shared reel' => [
        ['shares' => ['data' => [['link' => 'https://www.instagram.com/reel/DdbS2uhx6h1/']]]],
        [],
        ['type' => 'shared_post', 'url' => 'https://www.instagram.com/reel/DdbS2uhx6h1/'],
    ],
    'a shared post with its name' => [
        ['shares' => ['data' => [['link' => 'https://www.instagram.com/p/abc/', 'name' => 'Autumn drop']]]],
        [],
        ['type' => 'shared_post', 'url' => 'https://www.instagram.com/p/abc/', 'title' => 'Autumn drop'],
    ],
    'a story reply' => [
        ['message' => 'Love it', 'story' => ['reply_to' => ['link' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1', 'id' => 's-1']]],
        [],
        ['type' => 'story_reply', 'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1'],
    ],
    'a story mention' => [
        ['story' => ['mention' => ['link' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=2', 'id' => 's-2']]],
        [],
        ['type' => 'story_mention', 'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=2'],
    ],
    'unsupported content' => [
        ['is_unsupported' => true],
        [],
        ['type' => 'unsupported'],
    ],
    'a share without a link' => [
        ['shares' => ['data' => [['name' => 'Gone']]]],
        [],
        ['type' => 'unsupported'],
    ],
    'an image' => [
        ['attachments' => ['data' => [['image_data' => ['url' => 'https://lookaside.fbsbx.com/a.jpg', 'width' => 1290, 'height' => 1720]]]]],
        [['type' => 'image', 'url' => 'https://lookaside.fbsbx.com/a.jpg']],
        null,
    ],
    'an animated GIF' => [
        ['attachments' => ['data' => [['image_data' => ['url' => 'https://cdn.example/still.jpg', 'animated_gif_url' => 'https://cdn.example/a.gif']]]]],
        [['type' => 'image', 'url' => 'https://cdn.example/a.gif']],
        null,
    ],
    'a video' => [
        ['attachments' => ['data' => [['video_data' => ['url' => 'https://cdn.example/v.mp4']]]]],
        [['type' => 'video', 'url' => 'https://cdn.example/v.mp4']],
        null,
    ],
    'a template Meta cannot show' => [
        ['attachments' => ['data' => [['generic_template' => ['title' => 'Offer']]]]],
        [],
        ['type' => 'unsupported'],
    ],
    'a file' => [
        ['attachments' => ['data' => [['file_url' => 'https://cdn.example/f.pdf', 'name' => 'menu.pdf']]]],
        [],
        ['type' => 'attachment', 'url' => 'https://cdn.example/f.pdf', 'title' => 'menu.pdf'],
    ],
]);

test('the poll keeps the reactions a message received, from Instagram and Messenger', function (array $reaction) {
    $messages = ($this->pollWith)(['message' => 'Hi', 'reactions' => ['data' => [$reaction]]]);

    expect(data_get($messages[0]->meta, 'reactions'))->toEqual([['emoji' => '❤', 'user_id' => '17841400000000001']]);
})->with([
    'instagram' => [['users' => [['username' => 'ana.fan', 'id' => '17841400000000001']], 'emoji' => '❤']],
    'messenger' => [['reaction' => '❤', 'users' => [['username' => 'ana.fan', 'id' => '17841400000000001']]]],
]);

test('the poll skips a message that carries nothing to show', function (array $detail) {
    expect(($this->pollWith)($detail))->toBe([]);
})->with([
    'nothing at all' => [['is_unsupported' => false]],
    'only reactions' => [['reactions' => ['data' => [['users' => [['username' => 'x', 'id' => '1']], 'emoji' => '❤']]]]],
]);

test('webhook messages map what they carry', function (array $message, array $media, ?array $content) {
    Http::fake(["{$this->igGraph}/igsid-9*" => Http::response(['username' => 'ana.fan'])]);

    $incoming = $this->service->parseEvent($this->instagram, ($this->event)($message));

    expect($incoming->media)->toEqual($media)
        ->and(data_get($incoming->meta, 'content'))->toEqual($content);
})->with([
    'an Instagram post' => [['attachments' => [['type' => 'ig_post', 'payload' => ['url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1', 'title' => 'Autumn drop', 'ig_post_media_id' => '1']]]], [], ['type' => 'shared_post', 'title' => 'Autumn drop', 'thumbnail_url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1', 'thumbnail_type' => 'image']],
    'a legacy share' => [['attachments' => [['type' => 'share', 'payload' => ['url' => 'https://cdn.example/p.jpg']]]], [], ['type' => 'shared_post', 'thumbnail_url' => 'https://cdn.example/p.jpg', 'thumbnail_type' => 'image']],
    'a reel with its title' => [['attachments' => [['type' => 'ig_reel', 'payload' => ['url' => 'https://cdn.example/r.mp4', 'title' => 'New drop', 'reel_video_id' => 1]]]], [], ['type' => 'shared_post', 'title' => 'New drop', 'thumbnail_url' => 'https://cdn.example/r.mp4', 'thumbnail_type' => 'video']],
    'a shared link' => [['attachments' => [['type' => 'fallback', 'payload' => ['url' => 'https://example.com/a', 'title' => 'An article']]]], [], ['type' => 'shared_post', 'url' => 'https://example.com/a', 'title' => 'An article']],
    'a story mention' => [['attachments' => [['type' => 'story_mention', 'payload' => ['url' => 'https://cdn.example/story']]]], [], ['type' => 'story_mention', 'url' => 'https://cdn.example/story']],
    'a story reply' => [['text' => 'Love it', 'reply_to' => ['story' => ['url' => 'https://cdn.example/story', 'id' => 's-1']]], [], ['type' => 'story_reply', 'url' => 'https://cdn.example/story']],
    'unsupported media' => [['is_unsupported' => true], [], ['type' => 'unsupported']],
    'a share without a link' => [['attachments' => [['type' => 'fallback']]], [], ['type' => 'unsupported']],
    'a product template' => [['attachments' => [['type' => 'template', 'payload' => ['product' => ['elements' => []]]]]], [], ['type' => 'unsupported']],
    'an image' => [['attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.example/i.jpg']]]], [['type' => 'image', 'url' => 'https://cdn.example/i.jpg']], null],
    'a sticker' => [['attachments' => [['type' => 'sticker', 'payload' => ['url' => 'https://cdn.example/s.png', 'sticker_id' => 3]]]], [['type' => 'image', 'url' => 'https://cdn.example/s.png']], null],
    'a video' => [['attachments' => [['type' => 'video', 'payload' => ['url' => 'https://cdn.example/v.mp4']]]], [['type' => 'video', 'url' => 'https://cdn.example/v.mp4']], null],
    'a voice message' => [['attachments' => [['type' => 'audio', 'payload' => ['url' => 'https://cdn.example/a.mp4']]]], [], ['type' => 'attachment', 'url' => 'https://cdn.example/a.mp4']],
    'a file' => [['attachments' => [['type' => 'file', 'payload' => ['url' => 'https://cdn.example/f.pdf']]]], [], ['type' => 'attachment', 'url' => 'https://cdn.example/f.pdf']],
]);

test('a webhook message with nothing to show and a reaction event are ignored, a deleted one is kept', function () {
    Http::fake(["{$this->igGraph}/igsid-9*" => Http::response(['username' => 'ana.fan'])]);

    $empty = $this->service->parseEvent($this->instagram, ($this->event)(['text' => '']));
    $reaction = $this->service->parseEvent($this->instagram, [
        'sender' => ['id' => 'igsid-9'], 'recipient' => ['id' => 'ig-me'], 'timestamp' => 1760608800000,
        'reaction' => ['mid' => 'mid-1', 'action' => 'react', 'reaction' => 'love', 'emoji' => '❤️'],
    ]);
    $deleted = $this->service->parseEvent($this->instagram, ($this->event)(['is_deleted' => true]));

    expect($empty)->toBeNull()
        ->and($reaction)->toBeNull()
        ->and($deleted->removed)->toBeTrue();
});

test('reading a stored empty message again fills what it carries', function () {
    Storage::fake();
    fakePublicDns();
    Http::fake(["{$this->igGraph}/igsid-9*" => Http::response(['username' => 'ana.fan']), 'https://cdn.example/*' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')))]);
    $stored = UpsertInboxMessage::execute($this->instagram, $this->service->parseEvent($this->instagram, ($this->event)(['text' => 'placeholder'])), broadcast: false);
    $stored->forceFill(['body' => null, 'media' => [], 'meta' => []])->save();

    $image = $this->service->parseEvent($this->instagram, ($this->event)(['attachments' => [['type' => 'image', 'payload' => ['url' => 'https://cdn.example/a.jpg']]]]));
    UpsertInboxMessage::execute($this->instagram, $image, broadcast: false);
    storeAllInboxMedia();

    $message = InboxMessage::query()->sole();
    $resource = InboxMessageResource::make($message)->toArray(HttpRequest::create('/'));

    expect($resource['media'])->toEqual([['type' => 'image', 'url' => Storage::url(data_get($message->media, '0.path')), 'thumbnail_url' => null]])
        ->and(Media::query()->where('inbox_message_id', $message->id)->sole()->path)->toBe(data_get($message->media, '0.path'));
});

test('a share keeps the webhook preview and gains the link a later poll reads', function () {
    Storage::fake();
    fakePublicDns();
    Http::fake(["{$this->igGraph}/igsid-9*" => Http::response(['username' => 'ana.fan']), 'https://cdn.example/*' => Http::response(file_get_contents(base_path('tests/fixtures/sample.mp4')))]);
    $webhook = $this->service->parseEvent($this->instagram, ($this->event)(['attachments' => [['type' => 'ig_reel', 'payload' => ['url' => 'https://cdn.example/r.mp4', 'title' => 'New drop']]]]));
    UpsertInboxMessage::execute($this->instagram, $webhook, broadcast: false);

    $polled = ($this->pollWith)(['shares' => ['data' => [['link' => 'https://www.instagram.com/reel/abc/']]], 'reactions' => ['data' => [['users' => [['id' => 'igsid-9']], 'emoji' => '❤']]]]);
    UpsertInboxMessage::execute($this->instagram, $polled[0], broadcast: false);
    UpsertInboxMessage::execute($this->instagram, $this->service->parseEvent($this->instagram, ($this->event)(['attachments' => [['type' => 'ig_reel', 'payload' => ['url' => 'https://cdn.example/r.mp4', 'title' => 'New drop']]]])), broadcast: false);
    storeAllInboxMedia();

    $resource = InboxMessageResource::make(InboxMessage::query()->sole())->toArray(HttpRequest::create('/'));

    expect($resource['content'])->toEqual(['type' => 'shared_post', 'url' => 'https://www.instagram.com/reel/abc/', 'title' => 'New drop', 'thumbnail_type' => 'video', 'thumbnail_url' => Storage::url(data_get(InboxMessage::query()->sole()->meta, 'content.thumbnail_path'))])
        ->and($resource['reactions'])->toBe(['❤']);
});

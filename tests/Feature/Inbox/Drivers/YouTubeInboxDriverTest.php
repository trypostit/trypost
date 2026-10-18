<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Exceptions\Social\YouTubePublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\YouTubeInboxDriver;
use App\Services\Inbox\YouTubeQuota;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->account = SocialAccount::factory()->youtube()->create([
        'platform_user_id' => 'UCme',
        'token_expires_at' => now()->addHour(),
    ]);
    $this->api = config('trypost.platforms.youtube.data_api');
    $this->since = CarbonImmutable::parse('2026-09-16');
});

function youtubeComment(string $id, string $author = 'UCfan', string $at = '2026-10-16T10:00:00Z', ?string $parent = null): array
{
    return [
        'id' => $id,
        'snippet' => array_filter([
            'authorDisplayName' => $author === 'UCme' ? '@me' : '@fan',
            'authorProfileImageUrl' => 'https://yt3.ggpht.com/fan.jpg',
            'authorChannelId' => ['value' => $author],
            'textDisplay' => "Comment {$id}",
            'parentId' => $parent,
            'publishedAt' => $at,
            'updatedAt' => $at,
        ]),
    ];
}

function youtubeThread(string $id, int $replyCount, array $replies = [], string $at = '2026-10-16T10:00:00Z'): array
{
    return [
        'id' => $id,
        'snippet' => [
            'videoId' => 'vid1',
            'totalReplyCount' => $replyCount,
            'topLevelComment' => youtubeComment($id, at: $at),
        ],
        'replies' => ['comments' => $replies],
    ];
}

function youtubeVideos(): array
{
    return ['items' => [[
        'id' => 'vid1',
        'snippet' => [
            'title' => 'My video',
            'publishedAt' => '2026-10-10T10:00:00Z',
            'thumbnails' => ['medium' => ['url' => 'https://i.ytimg.com/vi/vid1/mqdefault.jpg']],
        ],
    ]]];
}

test('poll maps threads, inline replies and the channel own reply', function () {
    Http::fake([
        "{$this->api}/commentThreads*" => Http::response(['items' => [
            youtubeThread('c1', 1, [youtubeComment('c1.r1', 'UCme', '2026-10-16T11:00:00Z', 'c1')]),
        ]]),
        "{$this->api}/videos*" => Http::response(youtubeVideos()),
    ]);

    $result = app(YouTubeInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, $this->since);

    expect($result->messages)->toHaveCount(2)
        ->and($result->messages[0]->platformMessageId)->toBe('c1')
        ->and($result->messages[0]->direction)->toBe(MessageDirection::Inbound)
        ->and($result->messages[0]->authorUsername)->toBe('fan')
        ->and($result->messages[0]->meta)->toEqual(['total_reply_count' => 1, 'updated_at' => '2026-10-16T10:00:00Z'])
        ->and($result->messages[0]->subject->platformId)->toBe('vid1')
        ->and($result->messages[0]->subject->text)->toBe('My video')
        ->and($result->messages[0]->subject->remoteId)->toBe('vid1')
        ->and($result->messages[0]->subject->media)->toEqual([['type' => 'image', 'url' => 'https://i.ytimg.com/vi/vid1/mqdefault.jpg']])
        ->and($result->messages[1]->platformParentId)->toBe('c1')
        ->and($result->messages[1]->direction)->toBe(MessageDirection::Outbound)
        ->and($result->messages[1]->platformUrl)->toBe(config('trypost.platforms.youtube.web').'/watch?v=vid1&lc=c1.r1')
        ->and($result->cursor)->toBe('2026-10-16T10:00:00Z');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'allThreadsRelatedToChannelId=UCme')
        && str_contains($request->url(), 'textFormat=plainText'));
});

test('an unchanged thread is skipped without reading its replies', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id]);
    InboxMessage::factory()->for($conversation, 'conversation')->create([
        'platform_message_id' => 'c1',
        'platform_message_hash' => InboxConversation::hashOf('c1'),
        'meta' => ['total_reply_count' => 3, 'updated_at' => '2026-10-16T10:00:00Z'],
    ]);
    Http::fake([
        "{$this->api}/commentThreads*" => Http::response(['items' => [youtubeThread('c1', 3)]]),
        "{$this->api}/*" => Http::response(['items' => []]),
    ]);

    $result = app(YouTubeInboxDriver::class)->poll($this->account, ConversationKind::Comment, '2026-10-16T10:00:00Z', $this->since);

    expect($result->messages)->toBe([]);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/comments?'));
});

test('a thread whose reply count changed fetches its replies', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id]);
    InboxMessage::factory()->for($conversation, 'conversation')->create([
        'platform_message_id' => 'c1',
        'platform_message_hash' => InboxConversation::hashOf('c1'),
        'meta' => ['total_reply_count' => 0, 'updated_at' => '2026-09-20T10:00:00Z'],
    ]);
    Http::fake([
        "{$this->api}/commentThreads*" => Http::response(['items' => [youtubeThread('c1', 2, [], '2026-09-20T10:00:00Z')]]),
        "{$this->api}/comments*" => Http::response(['items' => [
            youtubeComment('c1.r1', at: '2026-10-16T09:00:00Z', parent: 'c1'),
            youtubeComment('c1.r2', at: '2026-10-16T09:30:00Z', parent: 'c1'),
        ]]),
        "{$this->api}/videos*" => Http::response(youtubeVideos()),
    ]);

    $result = app(YouTubeInboxDriver::class)->poll($this->account, ConversationKind::Comment, '2026-10-16T08:00:00Z', $this->since);

    expect(collect($result->messages)->pluck('platformMessageId')->all())->toBe(['c1', 'c1.r1', 'c1.r2']);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/comments?') && str_contains($request->url(), 'parentId=c1'));
});

test('a thread with several pages of replies reads them all up to the page cap', function () {
    $page = 0;
    Http::fake([
        "{$this->api}/commentThreads*" => Http::response(['items' => [youtubeThread('c1', 5000)]]),
        "{$this->api}/comments*" => function () use (&$page) {
            $page++;

            return Http::response(['items' => [youtubeComment("c1.r{$page}", at: '2026-10-16T09:00:00Z', parent: 'c1')], 'nextPageToken' => "page-{$page}"]);
        },
        "{$this->api}/videos*" => Http::response(youtubeVideos()),
    ]);

    $result = app(YouTubeInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, $this->since);

    expect($result->messages)->toHaveCount(11)
        ->and($page)->toBe(10);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/comments?') && str_contains($request->url(), 'pageToken=page-1'));
});

test('a quota refusal from google spends the day for every account', function () {
    Http::fake(["{$this->api}/*" => Http::response(['error' => ['code' => 403, 'message' => 'quota', 'errors' => [['reason' => 'quotaExceeded']]]], 403)]);

    expect(fn () => app(YouTubeInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, $this->since))
        ->toThrow(fn (YouTubePublishException $exception) => expect($exception->isLimit())->toBeTrue());
    expect(app(YouTubeQuota::class)->used())->toBe((int) config('trypost.inbox.youtube.daily_units'));
});

test('a dead token surfaces as token expired', function () {
    Http::fake(["{$this->api}/*" => Http::response(['error' => ['code' => 401, 'message' => 'Invalid Credentials']], 401)]);

    expect(fn () => app(YouTubeInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, $this->since))
        ->toThrow(TokenExpiredException::class);
});

test('a reply always targets the top-level comment and spends 50 units', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'platform_conversation_id' => 'vid1']);
    $root = InboxMessage::factory()->for($conversation, 'conversation')->create(['platform_message_id' => 'c1', 'platform_message_hash' => InboxConversation::hashOf('c1')]);
    $nested = InboxMessage::factory()->for($conversation, 'conversation')->create(['platform_message_id' => 'c1.r1', 'platform_message_hash' => InboxConversation::hashOf('c1.r1'), 'parent_id' => $root->id, 'root_id' => $root->id]);
    Http::fake(["{$this->api}/comments*" => Http::response(youtubeComment('c1.r9', 'UCme', '2026-10-16T12:00:00Z', 'c1'))]);

    $sent = app(YouTubeInboxDriver::class)->reply($this->account, $nested, '@fan thanks');

    expect($sent->platformMessageId)->toBe('c1.r9')
        ->and($sent->platformUrl)->toBe(config('trypost.platforms.youtube.web').'/watch?v=vid1&lc=c1.r9')
        ->and(app(YouTubeQuota::class)->used())->toBe(50);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_contains($request->url(), '/comments?part=snippet')
        && $request['snippet'] === ['parentId' => 'c1', 'textOriginal' => '@fan thanks']);
});

test('a channel without force-ssl has no inbox access', function () {
    expect($this->account->hasInboxAccess())->toBeTrue();

    $this->account->update(['scopes' => ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly']]);

    expect($this->account->fresh()->hasInboxAccess())->toBeFalse();
});

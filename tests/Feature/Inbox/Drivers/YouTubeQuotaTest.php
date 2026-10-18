<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Exceptions\Social\YouTubePublishException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\YouTubeInboxDriver;
use App\Services\Inbox\YouTubeQuota;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('trypost.inbox.youtube.daily_units', 100);
    config()->set('trypost.inbox.youtube.reply_reserve_units', 50);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 18:00:00', 'UTC'));
    $this->quota = app(YouTubeQuota::class);
});

test('polls stop at the poll ceiling and replies keep the reserve', function () {
    $this->quota->spendOnPoll(50);

    expect(fn () => $this->quota->spendOnPoll(1))->toThrow(YouTubePublishException::class);

    $this->quota->spendOnReply(50);
    expect($this->quota->used())->toBe(100)
        ->and(fn () => $this->quota->spendOnReply(1))->toThrow(YouTubePublishException::class);
});

test('the refusal is a rate limit that lifts at midnight pacific', function () {
    $this->quota->exhaust();

    try {
        $this->quota->spendOnPoll(1);
    } catch (YouTubePublishException $exception) {
        expect($exception->isLimit())->toBeTrue()
            ->and($exception->platformErrorCode)->toBe('quotaExceeded')
            ->and($exception->retryAt?->toIso8601String())->toBe('2026-10-17T07:00:00+00:00');
    }

    expect($exception ?? null)->not->toBeNull();
});

test('the count starts again on the next pacific day', function () {
    $this->quota->exhaust();
    $this->travelTo(CarbonImmutable::parse('2026-10-17 07:00:01', 'UTC'));

    $this->quota->spendOnPoll(1);

    expect($this->quota->used())->toBe(1);
});

test('each connected youtube channel gets an equal share of the poll units', function () {
    $first = SocialAccount::factory()->youtube()->create();
    SocialAccount::factory()->youtube()->create();

    $this->quota->spendOnPoll(24, $first);
    expect($this->quota->channelShare())->toBe(25)
        ->and($this->quota->withinShare($first))->toBeTrue();

    $this->quota->spendOnPoll(1, $first);
    expect($this->quota->withinShare($first))->toBeFalse();
});

test('the share counts only channels that may read their comments, once an hour', function () {
    SocialAccount::factory()->youtube()->create();
    SocialAccount::factory()->youtube()->create(['scopes' => ['https://www.googleapis.com/auth/youtube.upload']]);

    expect($this->quota->channelShare())->toBe(50);

    SocialAccount::factory()->youtube()->create();

    expect($this->quota->channelShare())->toBe(50);

    $this->travel(61)->minutes();

    expect($this->quota->channelShare())->toBe(25);
});

test('the youtube inbox polls every 30 minutes by default', function () {
    expect(config('trypost.inbox.poll_minutes.youtube'))->toBe(30);
});

test('a youtube channel past its daily share still reads one page per poll', function () {
    config()->set('trypost.inbox.youtube.daily_units', 150);
    $api = config('trypost.platforms.youtube.data_api');
    $account = SocialAccount::factory()->youtube()->create(['platform_user_id' => 'UCme', 'token_expires_at' => now()->addHour()]);
    SocialAccount::factory()->youtube()->create();
    $this->quota->spendOnPoll(50, $account);
    $thread = ['id' => 't1', 'snippet' => ['videoId' => 'v1', 'totalReplyCount' => 0, 'topLevelComment' => ['id' => 't1', 'snippet' => [
        'publishedAt' => now()->subHour()->toIso8601String(), 'updatedAt' => now()->subHour()->toIso8601String(), 'textDisplay' => 'Hi', 'authorDisplayName' => '@fan',
    ]]]];
    Http::fake([
        "{$api}/commentThreads*" => Http::response(['items' => [$thread], 'nextPageToken' => 'more']),
        "{$api}/videos*" => Http::response(['items' => []]),
    ]);

    app(YouTubeInboxDriver::class)->poll($account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable());

    Http::assertSentCount(2);
});

test('a thread read past the daily share stores only the replies it read, so a later poll fetches the rest', function () {
    config()->set('trypost.inbox.youtube.daily_units', 150);
    $api = config('trypost.platforms.youtube.data_api');
    $account = SocialAccount::factory()->youtube()->create(['platform_user_id' => 'UCme', 'token_expires_at' => now()->addDays(3)]);
    SocialAccount::factory()->youtube()->create();
    $this->quota->spendOnPoll(50, $account);
    $at = now()->subHour()->toIso8601String();
    $comment = fn (string $id, ?string $parent = null): array => ['id' => $id, 'snippet' => array_filter([
        'publishedAt' => $at, 'updatedAt' => $at, 'textDisplay' => 'Hi', 'authorDisplayName' => '@fan', 'parentId' => $parent,
    ])];
    $thread = ['id' => 't1', 'snippet' => ['videoId' => 'v1', 'totalReplyCount' => 3, 'topLevelComment' => $comment('t1')], 'replies' => ['comments' => [$comment('t1.r1', 't1')]]];
    Http::fake([
        "{$api}/commentThreads*" => Http::response(['items' => [$thread]]),
        "{$api}/comments*" => Http::response(['items' => [$comment('t1.r1', 't1'), $comment('t1.r2', 't1'), $comment('t1.r3', 't1')]]),
        "{$api}/videos*" => Http::response(['items' => []]),
    ]);

    $first = app(YouTubeInboxDriver::class)->poll($account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable());

    expect($first->messages)->toHaveCount(2)
        ->and($first->messages[0]->meta['total_reply_count'])->toBe(1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/comments?'));

    $conversation = InboxConversation::factory()->create(['social_account_id' => $account->id]);
    InboxMessage::factory()->for($conversation, 'conversation')->create([
        'social_account_id' => $account->id,
        'platform_message_id' => 't1',
        'platform_message_hash' => InboxConversation::hashOf('t1'),
        'meta' => $first->messages[0]->meta,
    ]);
    $this->travelTo(now()->addDay());

    $second = app(YouTubeInboxDriver::class)->poll($account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable());

    expect($second->messages)->toHaveCount(4);
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/comments?'));
});

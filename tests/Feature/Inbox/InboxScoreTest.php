<?php

declare(strict_types=1);

use App\Actions\Inbox\CalculateInboxScore;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\ScoreLevel;
use App\Events\InboxUpdated;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->travelTo(CarbonImmutable::parse('2026-10-18 15:00:00', 'UTC'));
    $this->conversation = InboxConversation::factory()->create();
    $this->account = $this->conversation->socialAccount;
    $this->workspace = $this->conversation->workspace;
    $this->user = $this->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->workspace->id, 'timezone' => 'UTC']);

    $this->comment = function (string $at, ?string $answeredAt = null, ?InboxConversation $conversation = null, array $attributes = []): InboxMessage {
        return InboxMessage::factory()->for($conversation ?? $this->conversation, 'conversation')->create([
            'platform_created_at' => CarbonImmutable::parse($at, 'UTC'),
            'answered_at' => $answeredAt ? CarbonImmutable::parse($answeredAt, 'UTC') : null,
            ...$attributes,
        ]);
    };
});

test('without comments in the last 7 days there is no score', function () {
    ($this->comment)('2026-10-10 12:00:00', '2026-10-10 12:30:00');

    expect(CalculateInboxScore::execute($this->workspace, $this->user))->toMatchArray([
        'score' => null,
        'level' => null,
        'response_rate' => null,
        'response_speed' => null,
        'consistency' => null,
        'comments_count' => 0,
        'answered_count' => 0,
    ]);
});

test('a few comments cap the response rate', function (int $comments, int $cap) {
    foreach (range(1, $comments) as $index) {
        ($this->comment)('2026-10-18 10:00:00', '2026-10-18 10:30:00');
    }

    $score = CalculateInboxScore::execute($this->workspace, $this->user);

    expect($score['response_rate'])->toBe($cap)
        ->and($score['response_speed'])->toBe(100)
        ->and($score['consistency'])->toBe(100)
        ->and($score['score'])->toBe((int) round(0.5 * $cap + 30 + 20));
})->with([
    'one comment' => [1, 20],
    'four comments' => [4, 20],
    'five comments' => [5, 60],
    'nine comments' => [9, 60],
    'ten comments' => [10, 100],
]);

test('the response rate counts answered comments, not resolved ones', function () {
    foreach (range(1, 7) as $index) {
        ($this->comment)('2026-10-18 10:00:00', '2026-10-18 11:00:00');
    }
    ($this->comment)('2026-10-18 10:00:00', attributes: ['resolved_at' => now()]);
    ($this->comment)('2026-10-18 10:00:00');
    ($this->comment)('2026-10-18 10:00:00');

    $score = CalculateInboxScore::execute($this->workspace, $this->user);

    expect($score)->toMatchArray([
        'comments_count' => 10,
        'answered_count' => 7,
        'response_rate' => 70,
        'response_speed' => 100,
        'consistency' => 100,
        'score' => 85,
        'level' => ScoreLevel::CommunityChampion->value,
    ]);
});

test('response speed is full within 2 hours and falls to nothing at 48 hours', function (int $seconds, float $expected) {
    expect(CalculateInboxScore::speedOf($seconds))->toEqualWithDelta($expected, 0.001);
})->with([
    'instant' => [0, 100.0],
    'two hours' => [7200, 100.0],
    'twenty five hours' => [25 * 3600, 50.0],
    'forty eight hours' => [48 * 3600, 0.0],
    'three days' => [72 * 3600, 0.0],
]);

test('response speed averages the first reply of every answered comment', function () {
    ($this->comment)('2026-10-16 10:00:00', '2026-10-16 11:00:00');
    ($this->comment)('2026-10-16 10:00:00', '2026-10-17 11:00:00');
    ($this->comment)('2026-10-13 10:00:00', '2026-10-16 10:00:00');
    ($this->comment)('2026-10-16 10:00:00');

    expect(CalculateInboxScore::execute($this->workspace, $this->user)['response_speed'])->toBe(50);
});

test('consistency is the share of days with comments that got a reply that day', function () {
    ($this->comment)('2026-10-12 09:00:00', '2026-10-12 10:00:00');
    ($this->comment)('2026-10-13 09:00:00', '2026-10-14 10:00:00');
    ($this->comment)('2026-10-15 09:00:00');
    ($this->comment)('2026-10-18 09:00:00', '2026-10-18 09:10:00');

    $score = CalculateInboxScore::execute($this->workspace, $this->user);

    expect($score['consistency'])->toBe(50)
        ->and($score['comments_count'])->toBe(4)
        ->and($score['answered_count'])->toBe(3);
});

test('reviews count while mentions, direct messages, our own messages and removed comments do not', function () {
    $review = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'kind' => ConversationKind::Review]);
    $mention = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'kind' => ConversationKind::Mention]);
    $direct = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'kind' => ConversationKind::DirectMessage]);

    ($this->comment)('2026-10-18 10:00:00', '2026-10-18 10:05:00');
    ($this->comment)('2026-10-18 10:00:00', conversation: $review);
    ($this->comment)('2026-10-18 10:00:00', conversation: $mention);
    ($this->comment)('2026-10-18 10:00:00', conversation: $direct);
    InboxMessage::factory()->for($this->conversation, 'conversation')->outbound()->create(['platform_created_at' => now()->subHour()]);
    ($this->comment)('2026-10-18 10:00:00', attributes: ['removed_at' => now()]);

    expect(CalculateInboxScore::execute($this->workspace, $this->user))->toMatchArray([
        'comments_count' => 2,
        'answered_count' => 1,
    ]);
});

test('the score keeps to the picked channels and to the workspace', function () {
    $other = InboxConversation::factory()->create([
        'social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id])->id,
    ]);
    ($this->comment)('2026-10-18 10:00:00', '2026-10-18 10:05:00');
    ($this->comment)('2026-10-18 10:00:00', conversation: $other);
    ($this->comment)('2026-10-18 10:00:00', conversation: InboxConversation::factory()->create());

    expect(CalculateInboxScore::execute($this->workspace, $this->user)['comments_count'])->toBe(2)
        ->and(CalculateInboxScore::execute($this->workspace, $this->user, [$this->account->id]))->toMatchArray(['comments_count' => 1, 'answered_count' => 1])
        ->and(CalculateInboxScore::execute($this->workspace, $this->user, [$other->social_account_id]))->toMatchArray(['comments_count' => 1, 'answered_count' => 0]);
});

test('the window is the last 7 days of the user\'s time zone', function () {
    ($this->comment)('2026-10-12 02:00:00', '2026-10-12 02:30:00');

    expect(CalculateInboxScore::execute($this->workspace, $this->user)['comments_count'])->toBe(1);

    $this->user->update(['timezone' => 'America/Sao_Paulo']);

    expect(CalculateInboxScore::execute($this->workspace, $this->user->fresh()))->toMatchArray([
        'comments_count' => 0,
        'starts_at' => '2026-10-12T03:00:00+00:00',
        'timezone' => 'America/Sao_Paulo',
    ]);
});

test('consistency follows the days of the user\'s time zone', function () {
    ($this->comment)('2026-10-17 23:00:00', '2026-10-18 01:00:00');

    expect(CalculateInboxScore::execute($this->workspace, $this->user)['consistency'])->toBe(0);

    $this->user->update(['timezone' => 'America/Sao_Paulo']);

    expect(CalculateInboxScore::execute($this->workspace, $this->user->fresh())['consistency'])->toBe(100);
});

test('the cached score is read again once the inbox changes', function () {
    ($this->comment)('2026-10-18 10:00:00');

    expect(CalculateInboxScore::cached($this->workspace, $this->user)['answered_count'])->toBe(0);

    InboxMessage::query()->update(['answered_at' => now()]);

    expect(CalculateInboxScore::cached($this->workspace, $this->user)['answered_count'])->toBe(0);

    InboxUpdated::dispatch($this->workspace->id, $this->conversation->id);

    expect(CalculateInboxScore::cached($this->workspace, $this->user)['answered_count'])->toBe(1);
});

test('the inbox page defers the score of the channels in the filter', function () {
    ($this->comment)('2026-10-18 10:00:00', '2026-10-18 10:05:00');
    $other = InboxConversation::factory()->create([
        'social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id])->id,
    ]);
    ($this->comment)('2026-10-18 10:00:00', conversation: $other);

    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('commentScore')
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('commentScore.comments_count', 2)
                ->where('commentScore.score', 60)
                ->where('commentScore.level', ScoreLevel::ReplyingRegularly->value)));

    $this->actingAs($this->user)->get(route('app.inbox.messages.index', ['channels' => [$other->social_account_id]]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->loadDeferredProps(fn (AssertableInertia $reload) => $reload
                ->where('commentScore.comments_count', 1)
                ->where('commentScore.answered_count', 0)
                ->where('commentScore.score', 0)));
});

test('the api returns the score and refuses a channel of another workspace', function () {
    ['user' => $user, 'workspace' => $workspace, 'token' => $token] = parityContext();
    $user->update(['timezone' => 'UTC']);
    $conversation = InboxConversation::factory()->create([
        'social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $workspace->id])->id,
    ]);
    ($this->comment)('2026-10-18 10:00:00', '2026-10-18 10:05:00', $conversation);

    $this->withHeaders(parityApi($token))->getJson(route('api.inbox.score.show'))
        ->assertOk()
        ->assertJsonPath('comments_count', 1)
        ->assertJsonPath('response_rate', 20)
        ->assertJsonPath('score', 60)
        ->assertJsonPath('level', ScoreLevel::ReplyingRegularly->value)
        ->assertJsonPath('window_days', 7);

    $this->withHeaders(parityApi($token))->getJson(route('api.inbox.score.show', ['channels' => [$this->account->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channels.0');

    config()->set('trypost.inbox.enabled', false);

    $this->withHeaders(parityApi($token))->getJson(route('api.inbox.score.show'))->assertNotFound();
});

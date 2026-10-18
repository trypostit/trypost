<?php

declare(strict_types=1);

use App\Actions\Inbox\CountOpenInboxMessages;
use App\Actions\Inbox\ListConversationThreads;
use App\Actions\Inbox\ListInboxConversations;
use App\Actions\Inbox\ListInboxMessages;
use App\Actions\Inbox\RecountInboxConversation;
use App\Actions\Inbox\ShowInboxConversation;
use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\DateRange;
use App\Enums\Inbox\StatusFilter;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->open = InboxConversation::factory()->create(['last_message_at' => now()->subHour()]);
    $this->workspace = $this->open->workspace;
    $account = $this->open->socialAccount;
    InboxMessage::factory()->for($this->open, 'conversation')->create();
    $this->answered = InboxConversation::factory()->create(['social_account_id' => $account->id, 'last_message_at' => now()]);
    InboxMessage::factory()->for($this->answered, 'conversation')->answered()->create();
    RecountInboxConversation::execute($this->open);
    RecountInboxConversation::execute($this->answered);
});

test('unanswered first lists open conversations before newer answered ones', function () {
    $ids = ListInboxConversations::execute($this->workspace, InboxFilters::fromArray([]))->pluck('id')->all();

    expect($ids)->toBe([$this->open->id, $this->answered->id]);
});

test('the status filter narrows the list', function (StatusFilter $status, string $expected) {
    $ids = ListInboxConversations::execute($this->workspace, InboxFilters::fromArray(['status' => $status->value]))->pluck('id')->all();

    expect($ids)->toBe([$this->{$expected}->id]);
})->with([[StatusFilter::Unanswered, 'open'], [StatusFilter::Replied, 'answered']]);

test('the date range excludes older conversations', function () {
    $old = InboxConversation::factory()->create([
        'social_account_id' => $this->open->social_account_id,
        'last_message_at' => now()->subDays(8),
    ]);

    $all = ListInboxConversations::execute($this->workspace, InboxFilters::fromArray([]))->pluck('id')->all();
    $week = ListInboxConversations::execute($this->workspace, InboxFilters::fromArray(['range' => DateRange::Last7Days->value]))->pluck('id')->all();

    expect($all)->toContain($old->id)
        ->and($week)->not->toContain($old->id)
        ->and($week)->toHaveCount(2);
});

test('the inbox switch off hides every list and count', function () {
    config()->set('trypost.inbox.enabled', false);

    expect(ListInboxConversations::execute($this->workspace, InboxFilters::fromArray([]))->count())->toBe(0)
        ->and(CountOpenInboxMessages::execute($this->workspace)['total'])->toBe(0);
});

test('another workspace never shows up', function () {
    InboxConversation::factory()->create();

    expect(ListInboxConversations::execute($this->workspace, InboxFilters::fromArray([]))->count())->toBe(2);
});

test('the open count is per channel and total', function () {
    $counts = CountOpenInboxMessages::execute($this->workspace);

    expect($counts['total'])->toBe(1)
        ->and($counts['channels'])->toBe([$this->open->social_account_id => 1]);
});

test('the message list returns only top-level inbound messages', function () {
    $top = InboxMessage::query()->where('inbox_conversation_id', $this->open->id)->first();
    $reply = InboxMessage::factory()->for($this->open, 'conversation')->outbound()->create(['parent_id' => $top->id, 'root_id' => $top->id, 'platform_created_at' => now()->subMinutes(2)]);
    $nested = InboxMessage::factory()->for($this->open, 'conversation')->create(['parent_id' => $reply->id, 'root_id' => $top->id, 'platform_created_at' => now()->subMinute()]);

    $messages = ListInboxMessages::execute($this->workspace, InboxFilters::fromArray([]))->get();

    expect($messages->pluck('id'))->not->toContain($reply->id)->not->toContain($nested->id)
        ->and($messages)->toHaveCount(2)
        ->and($messages->first()->id)->toBe($top->id)
        ->and($messages->first()->relationLoaded('treeMessages'))->toBeTrue()
        ->and($messages->first()->treeMessages->pluck('id')->all())->toBe([$reply->id, $nested->id])
        ->and($messages->first()->treeMessages->first()->relationLoaded('sentBy'))->toBeTrue();
});

test('showing a conversation loads its channel and replied count, not its messages', function () {
    $shown = ShowInboxConversation::execute($this->open);

    expect($shown->relationLoaded('socialAccount'))->toBeTrue()
        ->and($shown->relationLoaded('messages'))->toBeFalse()
        ->and($shown->replied_count)->toBe(0);
});

test('a conversation pages its top-level comments newest first with their reply trees', function () {
    config()->set('app.pagination.default', 2);
    $older = InboxMessage::factory()->for($this->open, 'conversation')->create(['platform_created_at' => now()->subDays(3)]);
    $reply = InboxMessage::factory()->for($this->open, 'conversation')->create(['parent_id' => $older->id, 'root_id' => $older->id, 'platform_created_at' => now()->subDays(2)]);
    $newest = InboxMessage::factory()->for($this->open, 'conversation')->create(['platform_created_at' => now()->subMinute()]);

    $first = ListConversationThreads::execute($this->open)->paginate(2, pageName: ListConversationThreads::PAGE_NAME);
    $second = ListConversationThreads::execute($this->open)->paginate(2, pageName: ListConversationThreads::PAGE_NAME, page: 2);

    expect($first->total())->toBe(3)
        ->and(collect($first->items())->first()->id)->toBe($newest->id)
        ->and(collect($second->items())->pluck('id')->all())->toBe([$older->id])
        ->and(collect($second->items())->first()->treeMessages->pluck('id')->all())->toBe([$reply->id]);
});

test('a message the network removed is never listed, in a conversation or the message list', function () {
    $standing = InboxMessage::factory()->for($this->open, 'conversation')->create(['platform_created_at' => now()->subMinutes(30)]);
    $reply = InboxMessage::factory()->for($this->open, 'conversation')->outbound()->create(['parent_id' => $standing->id, 'root_id' => $standing->id, 'removed_at' => now()]);
    $removed = InboxMessage::factory()->for($this->open, 'conversation')->create(['platform_created_at' => now(), 'removed_at' => now()]);

    $threads = ListConversationThreads::execute($this->open)->get();
    $list = ListInboxMessages::execute($this->workspace, InboxFilters::fromArray([]))->get();

    expect($threads->pluck('id'))->not->toContain($removed->id)
        ->and($threads->firstWhere('id', $standing->id)->treeMessages->pluck('id'))->not->toContain($reply->id)
        ->and($list->pluck('id'))->not->toContain($removed->id)
        ->and($list->firstWhere('id', $standing->id)->treeMessages->pluck('id'))->not->toContain($reply->id)
        ->and(ListConversationThreads::pageOf($this->open, $standing->id, 1))->toBe(1);
});

test('by post leaves reviews to the list view', function () {
    $review = InboxConversation::factory()->create([
        'social_account_id' => SocialAccount::factory()->googleBusiness()->for($this->workspace)->create(),
        'kind' => ConversationKind::Review,
        'last_message_at' => now(),
    ]);
    $filters = InboxFilters::fromArray([]);

    expect(ListInboxConversations::execute($this->workspace, $filters, [ConversationKind::Comment])->pluck('id'))->not->toContain($review->id)
        ->and(ListInboxConversations::execute($this->workspace, $filters)->pluck('id'))->toContain($review->id);
});

test('the message list finds an open follow-up under an answered comment', function () {
    $answered = InboxMessage::query()->where('inbox_conversation_id', $this->answered->id)->sole();
    InboxMessage::factory()->for($this->answered, 'conversation')->create(['parent_id' => $answered->id, 'root_id' => $answered->id, 'platform_created_at' => now()]);

    $unanswered = ListInboxMessages::execute($this->workspace, InboxFilters::fromArray(['status' => StatusFilter::Unanswered->value]))->pluck('id')->all();

    expect($unanswered)->toContain($answered->id)->toHaveCount(2);
});

test('the message list status and range filters match the comment or a message under it', function (StatusFilter $status, array $followUp) {
    $root = InboxMessage::factory()->for($this->open, 'conversation')->create(['platform_created_at' => now()->subDays(20)]);
    InboxMessage::factory()->for($this->open, 'conversation')->create([...$followUp, 'parent_id' => $root->id, 'root_id' => $root->id, 'platform_created_at' => now()->subDay()]);

    $filters = InboxFilters::fromArray(['status' => $status->value, 'range' => DateRange::Last7Days->value]);

    expect(ListInboxMessages::execute($this->workspace, $filters)->pluck('id')->all())->toContain($root->id);
})->with([
    'unanswered' => [StatusFilter::Unanswered, []],
    'replied' => [StatusFilter::Replied, ['answered_at' => now()]],
    'resolved' => [StatusFilter::Resolved, ['resolved_at' => now()]],
    'all' => [StatusFilter::All, []],
]);

test('the message list leaves out a thread with nothing in the range or status', function () {
    $root = InboxMessage::factory()->for($this->open, 'conversation')->answered()->create(['platform_created_at' => now()->subDays(20)]);
    InboxMessage::factory()->for($this->open, 'conversation')->answered()->create(['parent_id' => $root->id, 'root_id' => $root->id, 'platform_created_at' => now()->subDays(19)]);

    $week = ListInboxMessages::execute($this->workspace, InboxFilters::fromArray(['range' => DateRange::Last7Days->value]))->pluck('id')->all();
    $unanswered = ListInboxMessages::execute($this->workspace, InboxFilters::fromArray(['status' => StatusFilter::Unanswered->value]))->pluck('id')->all();

    expect($week)->not->toContain($root->id)
        ->and($unanswered)->not->toContain($root->id);
});

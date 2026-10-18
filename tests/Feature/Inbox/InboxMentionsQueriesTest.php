<?php

declare(strict_types=1);

use App\Actions\Inbox\CountOpenInboxMessages;
use App\Actions\Inbox\ListInboxConversations;
use App\Actions\Inbox\ListInboxMessages;
use App\Actions\Inbox\RecountInboxConversation;
use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\InboxTab;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->comment = InboxConversation::factory()->create();
    $this->workspace = $this->comment->workspace;
    $this->mention = InboxConversation::factory()->create([
        'social_account_id' => $this->comment->social_account_id,
        'kind' => ConversationKind::Mention,
    ]);
    InboxMessage::factory()->for($this->comment, 'conversation')->create();
    InboxMessage::factory()->for($this->mention, 'conversation')->count(2)->create();
    RecountInboxConversation::execute($this->comment);
    RecountInboxConversation::execute($this->mention);
});

test('each tab lists only its kinds', function () {
    $comments = ListInboxConversations::execute($this->workspace, InboxFilters::fromArray([]))->pluck('id')->all();
    $mentions = ListInboxConversations::execute($this->workspace, InboxFilters::fromArray(['tab' => 'mentions']))->pluck('id')->all();
    $feed = ListInboxMessages::execute($this->workspace, InboxFilters::fromArray(['tab' => 'mentions']))->pluck('inbox_conversation_id')->unique()->all();

    expect($comments)->toBe([$this->comment->id])
        ->and($mentions)->toBe([$this->mention->id])
        ->and($feed)->toBe([$this->mention->id]);
});

test('counts are per tab and the badge adds them', function () {
    expect(CountOpenInboxMessages::execute($this->workspace, InboxTab::Comments)['total'])->toBe(1)
        ->and(CountOpenInboxMessages::execute($this->workspace, InboxTab::Mentions)['total'])->toBe(2)
        ->and(CountOpenInboxMessages::execute($this->workspace)['total'])->toBe(3);
});

test('the channel filter narrows a tab count', function () {
    expect(CountOpenInboxMessages::execute($this->workspace, InboxTab::Mentions, [$this->comment->social_account_id])['total'])->toBe(2)
        ->and(CountOpenInboxMessages::execute($this->workspace, InboxTab::Mentions, [(string) str()->uuid()])['total'])->toBe(0);
});

test('the sidebar badge counts every tab in one query without loading conversations', function () {
    DB::enableQueryLog();

    CountOpenInboxMessages::execute($this->workspace);

    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'inbox_conversations')))->toHaveCount(1);
});

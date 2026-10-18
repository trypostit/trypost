<?php

declare(strict_types=1);

use App\Actions\Inbox\CountOpenInboxMessages;
use App\Actions\Inbox\ListInboxConversations;
use App\Actions\Inbox\ShowInboxConversation;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\DateRange;
use App\Enums\Inbox\InboxTab;
use App\Http\Resources\Api\InboxConversationResource;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->conversation = InboxConversation::factory()->create();
    $this->user = $this->conversation->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->conversation->workspace_id]);
});

test('messages_around reloads the list on the page holding that message thread', function () {
    config()->set('app.pagination.default', 2);
    $comments = collect(range(1, 5))->map(fn (int $hoursAgo): InboxMessage => InboxMessage::factory()->for($this->conversation, 'conversation')->create(['platform_created_at' => now()->subHours($hoursAgo)]));
    $oldest = $comments->last();
    $reply = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['parent_id' => $oldest->id, 'root_id' => $oldest->id, 'platform_created_at' => now()->subMinutes(5)]);

    $this->actingAs($this->user)
        ->get(route('app.inbox.messages.index', ['status' => 'all', 'sort' => 'newest', 'messages_around' => $reply->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('messages.data', 1)
            ->where('messages.data.0.id', $oldest->id));
});

test('messages_around must be a message of the workspace', function () {
    $foreign = InboxMessage::factory()->create();

    $this->actingAs($this->user)
        ->get(route('app.inbox.messages.index', ['messages_around' => $foreign->id]))
        ->assertSessionHasErrors('messages_around');
});

test('a DM conversation says whether every message the person wrote is resolved', function () {
    fakeDirectMessageDriver();
    $account = SocialAccount::factory()->instagram()->withDirectMessageScopes()->create();
    UpsertInboxMessage::execute($account, inboxDirectIncoming('m1', at: now()->subHour()->toDateTimeString()));
    UpsertInboxMessage::execute($account, inboxDirectIncoming('m2', at: now()->subMinutes(30)->toDateTimeString()));
    $dm = InboxConversation::query()->where('social_account_id', $account->id)->sole();
    $filters = InboxFilters::fromArray(['tab' => InboxTab::Messages->value, 'status' => 'all']);

    expect(InboxConversationResource::make(ShowInboxConversation::execute($dm->fresh()))->resolve()['resolved'])->toBeFalse();

    InboxMessage::query()->where('inbox_conversation_id', $dm->id)->update(['resolved_at' => now()]);

    DB::enableQueryLog();
    $listed = ListInboxConversations::execute($account->workspace, $filters)->get();
    $queries = count(DB::getQueryLog());
    $resolved = InboxConversationResource::collection($listed)->resolve();

    expect(data_get($resolved, '0.resolved'))->toBeTrue()
        ->and(count(DB::getQueryLog()))->toBe($queries)
        ->and(InboxConversationResource::make(ShowInboxConversation::execute($dm->fresh()))->resolve()['resolved'])->toBeTrue()
        ->and(InboxConversationResource::make($this->conversation)->resolve())->not->toHaveKey('resolved');
});

test('the inbox page counts follow the date range while the sidebar badge counts everything open', function () {
    InboxMessage::factory()->for($this->conversation, 'conversation')->create(['platform_created_at' => now()->subDays(2)]);
    InboxMessage::factory()->for($this->conversation, 'conversation')->create(['platform_created_at' => now()->subDays(20)]);
    $this->conversation->update(['open_count' => 2]);
    $workspace = $this->conversation->workspace;

    expect(CountOpenInboxMessages::execute($workspace, InboxTab::Comments, range: DateRange::Last7Days)['total'])->toBe(1)
        ->and(CountOpenInboxMessages::execute($workspace, InboxTab::Comments, range: DateRange::Last30Days)['total'])->toBe(2)
        ->and(CountOpenInboxMessages::execute($workspace)['total'])->toBe(2);

    $this->actingAs($this->user)
        ->get(route('app.inbox.messages.index', ['range' => DateRange::Last7Days->value]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tabCounts.comments', 1)
            ->where('inboxChannels.0.open_count', 1));
});

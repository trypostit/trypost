<?php

declare(strict_types=1);

use App\Actions\Inbox\ListInboxConversations;
use App\Actions\Inbox\RecountInboxConversation;
use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\DateRange;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Inbox\ListInboxConversationsTool;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->account = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id]);

    $this->commented = Post::factory()->forAccount($this->account)->published()->create(['content' => '<p>Commented post</p>', 'published_at' => now()->subDays(2)]);
    $this->conversation = InboxConversation::factory()->linkedTo($this->commented)->create();
    InboxMessage::factory()->for($this->conversation, 'conversation')->create();
    RecountInboxConversation::execute($this->conversation);

    $this->quiet = Post::factory()->forAccount($this->account)->published()->create(['content' => '<p>Quiet <b>launch</b></p>', 'published_at' => now()->subDay()]);
});

/**
 * @param  array<string, mixed>  $filters
 * @return list<array{id: ?string, post_id: ?string}>
 */
function allPostsRows(array $filters = []): array
{
    return collect(ListInboxConversations::paginate(test()->workspace, InboxFilters::fromArray(['posts' => 'all', ...$filters]))->items())
        ->map(fn (InboxConversation $conversation): array => ['id' => $conversation->id, 'post_id' => $conversation->post_id])
        ->all();
}

test('all posts lists the published posts without comments next to the commented ones', function () {
    expect(allPostsRows())->toBe([
        ['id' => $this->conversation->id, 'post_id' => $this->commented->id],
        ['id' => null, 'post_id' => $this->quiet->id],
    ]);

    $quiet = collect(ListInboxConversations::paginate($this->workspace, InboxFilters::fromArray(['posts' => 'all']))->items())->last();

    expect($quiet->exists)->toBeFalse()
        ->and($quiet->subject()['text'])->toBe('Quiet launch')
        ->and($quiet->open_count)->toBe(0)
        ->and($quiet->inbound_count)->toBe(0)
        ->and($quiet->social_account_id)->toBe($this->account->id);
});

test('without all posts the list keeps to posts with comments', function () {
    $ids = collect(ListInboxConversations::paginate($this->workspace, InboxFilters::fromArray([]))->items())->pluck('id')->all();

    expect($ids)->toBe([$this->conversation->id]);
});

test('all posts sorts a post without comments by its publish time among the conversations', function () {
    $this->conversation->update(['last_message_at' => now()->subDays(3)]);

    expect(allPostsRows(['sort' => 'newest'])[0]['post_id'])->toBe($this->quiet->id)
        ->and(allPostsRows(['sort' => 'oldest'])[0]['post_id'])->toBe($this->commented->id)
        ->and(allPostsRows()[0]['post_id'])->toBe($this->commented->id);
});

test('all posts keeps the conversations of posts TryPost does not know', function () {
    $unlinked = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'last_message_at' => now()->subDays(5)]);
    InboxMessage::factory()->for($unlinked, 'conversation')->create();
    RecountInboxConversation::execute($unlinked);

    expect(allPostsRows())->toContain(['id' => $unlinked->id, 'post_id' => null]);
});

test('all posts honours the channel and date range filters', function () {
    $other = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id]);
    $elsewhere = Post::factory()->forAccount($other)->published()->create();
    $old = Post::factory()->forAccount($this->account)->published()->create(['published_at' => now()->subDays(20)]);

    $everything = array_column(allPostsRows(), 'post_id');
    $channel = array_column(allPostsRows(['channels' => [$other->id]]), 'post_id');
    $week = array_column(allPostsRows(['range' => DateRange::Last7Days->value]), 'post_id');

    expect($everything)->toContain($elsewhere->id, $old->id)
        ->and($channel)->toBe([$elsewhere->id])
        ->and($week)->not->toContain($old->id)
        ->and($week)->toContain($this->quiet->id, $this->commented->id);
});

test('an old post with recent comments stays in the range', function () {
    $this->commented->forceFill(['published_at' => now()->subMonths(2)])->save();

    expect(array_column(allPostsRows(['range' => DateRange::Last7Days->value]), 'post_id'))->toContain($this->commented->id);
});

test('the status filter keeps to the conversations and still adds the posts without comments', function () {
    expect(allPostsRows(['status' => 'replied']))->toBe([['id' => null, 'post_id' => $this->quiet->id]]);
});

test('all posts leaves out drafts, other workspaces and channels without comments', function () {
    Post::factory()->forAccount($this->account)->draft()->create();
    Post::factory()->forAccount($this->account)->scheduled()->create();
    Post::factory()->forAccount(SocialAccount::factory()->bluesky()->create())->published()->create();
    Post::factory()->forAccount(SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]))->published()->create();
    Post::factory()->forAccount(SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]))->published()->create();

    expect(array_column(allPostsRows(), 'post_id'))->toBe([$this->commented->id, $this->quiet->id]);
});

test('all posts pages with the app page size in a fixed number of queries', function () {
    foreach (range(1, 4) as $index) {
        $post = Post::factory()->forAccount($this->account)->published()->create(['published_at' => now()->subHours($index)]);
        InboxConversation::factory()->linkedTo($post)->create();
        Post::factory()->forAccount($this->account)->published()->create(['published_at' => now()->subHours($index)]);
    }

    $queriesFor = function (int $perPage): int {
        config()->set('app.pagination.default', $perPage);
        DB::flushQueryLog();
        DB::enableQueryLog();
        ListInboxConversations::paginate($this->workspace, InboxFilters::fromArray(['posts' => 'all']));
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    config()->set('app.pagination.default', 4);
    $first = ListInboxConversations::paginate($this->workspace, InboxFilters::fromArray(['posts' => 'all']));
    $last = ListInboxConversations::paginate($this->workspace, InboxFilters::fromArray(['posts' => 'all']), page: 3);

    expect($first->items())->toHaveCount(4)
        ->and($first->perPage())->toBe(4)
        ->and($first->total())->toBe(10)
        ->and($last->items())->toHaveCount(2)
        ->and($queriesFor(10))->toBeLessThanOrEqual(9);
});

test('the by post page lists every post and opens one without comments', function () {
    $this->actingAs($this->user)->get(route('app.inbox.index', ['posts' => 'all', 'post' => $this->quiet->id]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('inbox/Index', false)
            ->where('filters.posts', 'all')
            ->where('conversations.data.1.id', null)
            ->where('conversations.data.1.post_id', $this->quiet->id)
            ->where('conversation.id', null)
            ->where('conversation.post_id', $this->quiet->id)
            ->where('conversation.post.id', $this->quiet->id)
            ->where('conversation.subject.text', 'Quiet launch')
            ->where('conversation.inbound_count', 0)
            ->where('conversationThreads.data', [])
            ->where('isConversationOpened', true));
});

test('picking a post that has comments opens its conversation', function () {
    $this->actingAs($this->user)->get(route('app.inbox.index', ['posts' => 'all', 'post' => $this->commented->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('conversation.id', $this->conversation->id)
            ->has('conversationThreads.data', 1));
});

test('a post of another workspace is never opened', function () {
    $foreign = Post::factory()->forAccount(SocialAccount::factory()->bluesky()->create())->published()->create();

    $this->actingAs($this->user)->get(route('app.inbox.index', ['posts' => 'all', 'post' => $foreign->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('conversation.id', $this->conversation->id)
            ->where('isConversationOpened', false));
});

test('all posts only applies to by post', function () {
    $this->actingAs($this->user)->get(route('app.inbox.messages.index', ['posts' => 'all']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.posts', 'commented'));
});

test('a bad posts value is refused on web, api and mcp', function () {
    $this->actingAs($this->user)->get(route('app.inbox.index', ['posts' => 'bogus']))->assertSessionHasErrors('posts');
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.inbox.conversations.index', ['posts' => 'bogus']))->assertUnprocessable();
    TryPostServer::actingAs($this->user)->tool(ListInboxConversationsTool::class, ['posts' => 'bogus'])->assertHasErrors();
});

test('all posts returns the same rows on the api and mcp', function () {
    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.inbox.conversations.index', ['posts' => 'all']))
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->conversation->id)
        ->assertJsonPath('data.1.id', null)
        ->assertJsonPath('data.1.post_id', $this->quiet->id)
        ->assertJsonPath('data.1.open_count', 0);
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListInboxConversationsTool::class, ['posts' => 'all'])
        ->assertOk()
        ->assertStructuredContent(parityMcpPage('conversations', $api));
});

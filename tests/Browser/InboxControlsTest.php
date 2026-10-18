<?php

declare(strict_types=1);

use App\Actions\Inbox\RecountInboxConversation;
use App\Enums\SocialAccount\Status;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
    $this->conversation = InboxConversation::factory()->create(['subject_text' => 'My launch post']);
    $this->comment = InboxMessage::factory()->for($this->conversation, 'conversation')->create([
        'body' => 'Love it',
        'author_username' => 'fan.bsky.social',
        'platform_url' => route('app.inbox.index'),
    ]);
    RecountInboxConversation::execute($this->conversation);
    $this->user = $this->conversation->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->conversation->workspace_id]);
    $this->actingAs($this->user->fresh());
});

test('copy link in the card menu copies the comment address', function (int $width) {
    $page = visit(route('app.inbox.index'))->resize($width, 900);

    waitForState($page, '@inbox-comment-menu', inViewport());
    $page->script('window.__copied = null; navigator.clipboard.writeText = async (text) => { window.__copied = text; }; true;');
    $page->click('@inbox-comment-menu');
    waitForState($page, '@inbox-comment-copy-link', inViewport());
    $page->click('@inbox-comment-copy-link');
    waitForCondition($page, 'window.__copied !== null');

    $page->assertScript('window.__copied', $this->comment->platform_url)->assertNoJavaScriptErrors();
})->with(['desktop' => 1280, 'mobile' => 390]);

test('view comment opens the comment on its network in a new tab', function (int $width) {
    $page = visit(route('app.inbox.index'))->resize($width, 900);

    waitForState($page, '@inbox-comment-view', inViewport());
    $page->assertAttribute('@inbox-comment-view', 'href', $this->comment->platform_url)
        ->assertAttribute('@inbox-comment-view', 'target', '_blank')
        ->assertSeeIn('@inbox-comment-view', __('inbox.comment.view.comment'));
    $page->click('@inbox-comment-view');
    $page->assertPathIs(parse_url(route('app.inbox.index'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
})->with(['desktop' => 1280, 'mobile' => 390]);

test('see more opens a long comment and see less closes it', function () {
    $this->comment->update(['body' => implode("\n", array_fill(0, 8, 'A long line of a long comment'))]);

    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-message-see-more', inViewport());
    waitForState($page, '@inbox-message-body', 'element.scrollHeight > element.clientHeight + 1');
    $page->click('@inbox-message-see-more');
    waitForState($page, '@inbox-message-body', 'element.scrollHeight <= element.clientHeight + 1');
    $page->assertSeeIn('@inbox-message-see-more', __('inbox.comment.see_less'));
    $page->click('@inbox-message-see-more');
    waitForState($page, '@inbox-message-body', 'element.scrollHeight > element.clientHeight + 1');
    $page->assertSeeIn('@inbox-message-see-more', __('inbox.comment.see_more'))->assertNoJavaScriptErrors();
});

test('the sort menu changes the date range and resets every filter', function () {
    $page = visit(route('app.inbox.index', ['status' => 'resolved', 'sort' => 'oldest']));

    waitForState($page, '@inbox-sort-menu', inViewport());
    $page->click('@inbox-sort-menu');
    waitForState($page, '@inbox-range-7d', inViewport());
    $page->click('@inbox-range-7d');
    waitForCondition($page, "location.search.includes('range=7d')");

    waitForState($page, '@inbox-sort-menu', inViewport());
    $page->click('@inbox-sort-menu');
    waitForState($page, '@inbox-reset-filters', inViewport());
    $page->click('@inbox-reset-filters');
    waitForCondition($page, "location.search === ''");
    $page->assertNoJavaScriptErrors();
});

test('reorder from the hint reorders the posts and hides the hint for good', function () {
    $second = InboxConversation::factory()->create(['social_account_id' => $this->conversation->social_account_id, 'workspace_id' => $this->conversation->workspace_id]);
    InboxMessage::factory()->for($second, 'conversation')->create();
    RecountInboxConversation::execute($second);

    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-reorder-hint-reorder', inViewport());
    $page->click('@inbox-reorder-hint-reorder');
    waitForGone($page, '@inbox-reorder-hint');
    $page->assertNoJavaScriptErrors();

    expect($this->user->fresh()->inbox_reorder_hint_seen_at)->not->toBeNull();
});

test('connect more channels in the listening state opens the connect dialog', function () {
    InboxMessage::query()->delete();
    InboxConversation::query()->delete();

    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-listening-connect', inViewport());
    $page->click('@inbox-listening-connect');
    waitForState($page, '@connect-channel-dialog', inViewport());
    $page->assertNoJavaScriptErrors();
});

test('connect a channel in the first-use state opens the connect dialog', function () {
    InboxMessage::query()->delete();
    InboxConversation::query()->delete();
    SocialAccount::query()->delete();

    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-empty-connect', inViewport());
    $page->click('@inbox-empty-connect');
    waitForState($page, '@connect-channel-dialog', inViewport());
    $page->assertNoJavaScriptErrors();
});

test('a comment that arrives on the open post shows the new comments pill and divider', function () {
    $older = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['body' => 'Older comment', 'platform_created_at' => now()->subHours(3)]);
    RecountInboxConversation::execute($this->conversation);

    $page = visit(route('app.inbox.conversations.show', $this->conversation));
    waitForState($page, "[data-thread-id=\"{$older->id}\"]", inViewport());

    InboxMessage::factory()->for($this->conversation, 'conversation')->create(['body' => 'Brand new comment', 'platform_created_at' => now()]);
    RecountInboxConversation::execute($this->conversation);
    emitWorkspaceEvent($page, $this->conversation->workspace_id, 'inbox.updated', ['conversation_id' => $this->conversation->id]);

    waitForState($page, '@inbox-new-comments-pill', inViewport());
    waitForLayout($page, '@inbox-new-comments-pill', '@inbox-subject-card', 'a.bottom <= b.top');
    waitFor($page, '@inbox-new-comments-divider');
    $page->assertSeeIn('@inbox-new-comments-pill', __('inbox.conversation.new_available'));
    $page->click('@inbox-new-comments-pill');
    waitForGone($page, '@inbox-new-comments-pill');
    $page->assertNoJavaScriptErrors();
});

test('resolving on the post the index opened keeps that post and only its comments', function () {
    $this->conversation->update(['last_message_at' => now()->subHours(2)]);
    InboxMessage::factory()->for($this->conversation, 'conversation')->create(['body' => 'Second on launch', 'platform_created_at' => now()->subHours(2)]);
    RecountInboxConversation::execute($this->conversation);
    $other = inboxSecondConversation($this->conversation, 'Another post');
    $other->update(['last_message_at' => now()]);
    $otherComment = InboxMessage::query()->where('inbox_conversation_id', $other->id)->sole();

    $page = visit(route('app.inbox.index'));

    waitForText($page, '@inbox-subject-card', 'My launch post');
    waitForState($page, "[data-thread-id=\"{$this->comment->id}\"] [data-testid=\"inbox-resolve\"]", inViewport());
    $page->click("[data-thread-id=\"{$this->comment->id}\"] [data-testid=\"inbox-resolve\"]");
    waitForState($page, "[data-thread-id=\"{$this->comment->id}\"] [data-testid=\"inbox-resolve\"]", "element.dataset.resolved === 'true' && !element.disabled");
    waitForState($page, "@inbox-post-card-{$this->conversation->id}", "element.getAttribute('aria-current') === 'true'");

    $page->assertSeeIn('@inbox-subject-card', 'My launch post')
        ->assertMissing("[data-thread-id=\"{$otherComment->id}\"]")
        ->assertNoJavaScriptErrors();
});

test('on mobile next loads the next page of posts', function () {
    config()->set('app.pagination.default', 1);
    $second = inboxSecondConversation($this->conversation);
    $second->update(['open_count' => 0, 'last_message_at' => now()->subDay()]);

    $page = visit(route('app.inbox.index'))->resize(390, 844);

    waitForState($page, '@inbox-post-next', '!element.disabled');
    $page->click('@inbox-post-next');
    waitForText($page, '@inbox-subject-card', 'Second post');
    $page->assertNoJavaScriptErrors();
});

test('a comment card picked with the keyboard opens its reply box', function () {
    $second = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['body' => 'Another comment', 'platform_created_at' => now()->subHours(2)]);
    RecountInboxConversation::execute($this->conversation);

    $page = visit(route('app.inbox.index'));

    waitForState($page, "[data-thread-id=\"{$second->id}\"] [data-testid=\"inbox-comment-card\"]", inViewport());
    $page->assertMissing("[data-thread-id=\"{$second->id}\"] [data-testid=\"inbox-reply-box\"]");
    $page->assertAttribute("[data-thread-id=\"{$second->id}\"] [data-testid=\"inbox-comment-card\"]", 'tabindex', '0');
    $page->keys("[data-thread-id=\"{$second->id}\"] [data-testid=\"inbox-comment-card\"]", 'Enter');
    waitForState($page, "[data-thread-id=\"{$second->id}\"] [data-testid=\"inbox-reply-input\"]", inViewport());
    $page->assertNoJavaScriptErrors();
});

test('coming back to comments from mentions opens the view the user had', function () {
    inboxMentionThread($this->conversation);

    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-tab-mentions', inViewport());
    $page->click('@inbox-tab-mentions');
    waitForCondition($page, "location.search.includes('tab=mentions')");
    waitForState($page, '@inbox-tab-comments', inViewport());
    $page->click('@inbox-tab-comments');
    waitForCondition($page, "!location.search.includes('tab=mentions')");
    waitForPath($page, parse_url(route('app.inbox.index'), PHP_URL_PATH));
    waitFor($page, '@inbox-by-post');
    $page->assertPathIs(parse_url(route('app.inbox.index'), PHP_URL_PATH))->assertNoJavaScriptErrors();
});

test('the list says all caught up only when nothing is open in the tab', function () {
    config()->set('app.pagination.default', 1);
    $this->comment->update(['platform_created_at' => now()->subDays(2)]);
    InboxMessage::factory()->for($this->conversation, 'conversation')->create(['body' => 'Answered', 'platform_created_at' => now(), 'answered_at' => now()]);
    RecountInboxConversation::execute($this->conversation);

    $page = visit(route('app.inbox.messages.index', ['sort' => 'newest']));

    waitForText($page, '@inbox-comment-card', 'Answered');
    $page->assertMissing('@inbox-caught-up')->assertNoJavaScriptErrors();
});

test('the status filter names what the tab shows', function () {
    inboxMentionThread($this->conversation);

    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']));

    waitForState($page, '@inbox-status-filter', inViewport());
    $page->assertAttribute('@inbox-status-filter', 'aria-label', __('inbox.filters.status_label_mentions').': '.__('inbox.filters.status.all'))->assertNoJavaScriptErrors();
});

test('a facebook page that must be reconnected is named without an at sign', function () {
    $page = SocialAccount::factory()->facebook()->withInboxScopes()->create([
        'workspace_id' => $this->conversation->workspace_id,
        'display_name' => 'Acme Coffee',
        'username' => 'acmecoffee',
        'status' => Status::TokenExpired,
    ]);

    $browser = visit(route('app.inbox.index'));

    waitForState($browser, "@inbox-reconnect-title-{$page->id}", inViewport());
    $browser->assertSeeIn("@inbox-reconnect-title-{$page->id}", __('inbox.reconnect.title', ['name' => 'Acme Coffee']))
        ->assertDontSeeIn("@inbox-reconnect-title-{$page->id}", '@')
        ->assertNoJavaScriptErrors();
});

test('a member who needs approval reads the inbox without reply, resolve or bulk controls', function () {
    $member = workspaceMember($this->conversation->workspace, 'approval');
    $member->update(['current_workspace_id' => $this->conversation->workspace_id]);
    $this->actingAs($member->fresh());

    $page = visit(route('app.inbox.index'));

    waitForText($page, '@inbox-message', 'Love it');
    $page->assertMissing('@inbox-reply-box')
        ->assertMissing('@inbox-resolve')
        ->assertMissing('@inbox-bulk-resolve')
        ->assertNoJavaScriptErrors();

    $page->click('@inbox-comment-card');
    $page->assertMissing('@inbox-reply-input')->assertNoJavaScriptErrors();
});

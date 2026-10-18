<?php

declare(strict_types=1);

use App\Actions\Inbox\RecountInboxConversation;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\MessageDirection;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
    $this->conversation = InboxConversation::factory()->create(['subject_text' => 'My launch post', 'last_message_at' => now()]);
    $this->comment = InboxMessage::factory()->for($this->conversation, 'conversation')->create([
        'body' => 'Love it',
        'author_username' => 'fan.bsky.social',
        'platform_created_at' => now(),
    ]);
    RecountInboxConversation::execute($this->conversation);
    $this->user = $this->conversation->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->conversation->workspace_id]);
    $this->actingAs($this->user->fresh());
});

/** Clicks two controls in the same task, so the second starts while the first request is still on its way. */
function clickBoth(mixed $page, string $first, string $second): void
{
    $page->script('(() => { document.querySelector('.json_encode($first).').click(); document.querySelector('.json_encode($second).').click(); return true; })()');
}

/** Records the fewest items the list ever held from now on, so a reload that starts it over is caught even if it scrolls back. */
function watchFewestItems(mixed $page, string $selector): void
{
    $selector = json_encode($selector);
    $page->script("(() => { const count = () => document.querySelectorAll({$selector}).length; window.__fewestItems = count(); new MutationObserver(() => { window.__fewestItems = Math.min(window.__fewestItems, count()); }).observe(document.body, { childList: true, subtree: true }); return true; })()");
}

function outboundBodies(): array
{
    return InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->pluck('body')->all();
}

test('a reply sent right before picking another post is stored once and its draft is cleared', function () {
    $second = inboxSecondConversation($this->conversation);
    $second->update(['last_message_at' => now()->subHour()]);

    $page = visit(route('app.inbox.conversations.show', $this->conversation));

    waitForState($page, '@inbox-reply-input', "element.value === '@fan.bsky.social '");
    $page->fill('@inbox-reply-input', '@fan.bsky.social thanks!');
    waitForState($page, '@inbox-reply-send', '!element.disabled');
    $page->assertEnabled('@inbox-reply-send');

    clickBoth($page, '[data-testid="inbox-reply-send"]', "[data-testid=\"inbox-post-card-{$second->id}\"]");

    waitForText($page, '@inbox-subject-card', 'Second post');
    $page->assertSeeIn('@inbox-subject-card', 'Second post');

    $page->click("@inbox-post-card-{$this->conversation->id}");
    waitFor($page, '@inbox-outbound-message');
    $page->assertVisible('@inbox-outbound-message');
    expect(outboundBodies())->toBe(['@fan.bsky.social thanks!']);

    $page->click('@inbox-message-author');
    waitFor($page, '@inbox-reply-input');
    $page->assertValue('@inbox-reply-input', '@fan.bsky.social ')->assertNoJavaScriptErrors();
});

test('a reply sent right before resolving the comment is stored and the comment is resolved', function () {
    $page = visit(route('app.inbox.conversations.show', $this->conversation));

    waitForState($page, '@inbox-reply-input', "element.value === '@fan.bsky.social '");
    $page->fill('@inbox-reply-input', '@fan.bsky.social thanks!');
    waitForState($page, '@inbox-reply-send', '!element.disabled');
    $page->assertEnabled('@inbox-reply-send');

    clickBoth($page, '[data-testid="inbox-reply-send"]', '[data-testid="inbox-resolve"]');

    waitForState($page, '@inbox-resolve', "element.dataset.resolved === 'true' && !element.disabled");
    waitFor($page, '@inbox-outbound-message');
    $page->assertVisible('@inbox-outbound-message')->assertAttribute('@inbox-resolve', 'data-resolved', 'true');
    expect(outboundBodies())->toBe(['@fan.bsky.social thanks!'])
        ->and($this->comment->fresh()->resolved_at)->not->toBeNull();
    $page->assertNoJavaScriptErrors();
});

test('resolving a card in the List view keeps every page loaded and updates the card in place', function () {
    config()->set('app.pagination.default', 2);
    inboxSecondConversation($this->conversation, 'Second post')->update(['last_message_at' => now()->subHour()]);
    inboxSecondConversation($this->conversation, 'Third post')->update(['last_message_at' => now()->subHours(2)]);
    InboxMessage::query()->where('inbox_conversation_id', '!=', $this->conversation->id)->get()
        ->each(fn (InboxMessage $message, int $index) => $message->update(['platform_created_at' => now()->subHours($index + 1)]));

    $page = visit(route('app.inbox.messages.index', ['status' => 'all', 'sort' => 'newest']));

    waitForCondition($page, "document.querySelectorAll('[data-testid=\"inbox-comment-card\"]').length === 3");
    $page->assertScript("document.querySelectorAll('[data-testid=\"inbox-comment-card\"]').length", 3);

    $resolve = "[data-thread-id=\"{$this->comment->id}\"] [data-testid=\"inbox-resolve\"]";
    waitForState($page, $resolve, inViewport());
    watchFewestItems($page, '[data-testid="inbox-comment-card"]');
    $page->click($resolve);
    waitForState($page, $resolve, "element.dataset.resolved === 'true' && !element.disabled");

    $page->assertAttribute($resolve, 'data-resolved', 'true')
        ->assertScript("document.querySelectorAll('[data-testid=\"inbox-comment-card\"]').length", 3)
        ->assertScript('window.__fewestItems', 3)
        ->assertNoJavaScriptErrors();
    expect($this->comment->fresh()->resolved_at)->not->toBeNull();
});

test('news about a conversation that is not loaded flags the List view instead of starting it over', function () {
    config()->set('app.pagination.default', 2);
    inboxSecondConversation($this->conversation, 'Second post')->update(['last_message_at' => now()->subHour()]);
    inboxSecondConversation($this->conversation, 'Third post')->update(['last_message_at' => now()->subHours(2)]);
    InboxMessage::query()->where('inbox_conversation_id', '!=', $this->conversation->id)->get()
        ->each(fn (InboxMessage $message, int $index) => $message->update(['platform_created_at' => now()->subHours($index + 1)]));

    $page = visit(route('app.inbox.messages.index', ['status' => 'all', 'sort' => 'newest']));

    waitForCondition($page, "document.querySelectorAll('[data-testid=\"inbox-comment-card\"]').length === 3");
    $page->assertScript("document.querySelectorAll('[data-testid=\"inbox-comment-card\"]').length", 3);

    watchFewestItems($page, '[data-testid="inbox-comment-card"]');
    $fresh = inboxSecondConversation($this->conversation, 'Fresh post');
    InboxMessage::query()->where('inbox_conversation_id', $fresh->id)->update(['platform_created_at' => now()->addMinute()]);
    emitWorkspaceEvent($page, $this->conversation->workspace_id, 'inbox.updated', ['conversation_id' => $fresh->id]);

    waitForState($page, '@inbox-feed-new-pill', inViewport());
    waitForLayout($page, '@inbox-feed-new-pill', '@inbox-comment-card', 'a.bottom <= b.top');
    waitForState($page, '@inbox-open-count', "element.textContent.trim() === '4'");
    $page->assertSeeIn('@inbox-open-count', '4')->assertSeeIn('@inbox-feed-new-pill', __('inbox.conversation.new_available'))
        ->assertScript("document.querySelectorAll('[data-testid=\"inbox-comment-card\"]').length", 3)
        ->assertScript('window.__fewestItems', 3);

    $page->click('@inbox-feed-new-pill');
    waitForGone($page, '@inbox-feed-new-pill');
    waitForText($page, '@inbox-feed', 'Fresh post');
    $page->assertSeeIn('@inbox-feed', 'Fresh post')->assertMissing('@inbox-feed-new-pill')->assertNoJavaScriptErrors();
});

test('sending a message keeps every loaded conversation in the Messages list', function () {
    config()->set('app.pagination.default', 2);
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->conversation->workspace_id,
        'username' => 'brand',
        'token_expires_at' => null,
        'scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights', 'instagram_business_manage_comments', 'instagram_business_manage_messages'],
    ]);
    collect(['igsid-1', 'igsid-2', 'igsid-3'])->each(fn (string $participant, int $index) => UpsertInboxMessage::execute(
        $account,
        inboxDirectIncoming("m{$index}", at: now()->subMinutes(10 + $index)->toDateTimeString(), participant: $participant),
    ));

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForCondition($page, "document.querySelectorAll('#inbox-dm-items > li').length === 3");
    $page->assertScript("document.querySelectorAll('#inbox-dm-items > li').length", 3);

    waitForState($page, '@inbox-dm-input', inViewport());
    $page->fill('@inbox-dm-input', 'Thanks for writing!');
    waitForState($page, '@inbox-dm-send', '!element.disabled');
    watchFewestItems($page, '#inbox-dm-items > li');
    $page->click('@inbox-dm-send');
    waitForState($page, '@inbox-dm-message[data-direction="outbound"]', "element.dataset.status === 'sending'");
    waitForState($page, '@inbox-dm-input', "element.value === ''");

    $page->assertValue('@inbox-dm-input', '')
        ->assertScript("document.querySelectorAll('#inbox-dm-items > li').length", 3)
        ->assertScript('window.__fewestItems', 3)
        ->assertNoJavaScriptErrors();
});

test('an unsent direct message never shows its text in the conversation list', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->conversation->workspace_id,
        'username' => 'brand',
        'token_expires_at' => null,
        'scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights', 'instagram_business_manage_comments', 'instagram_business_manage_messages'],
    ]);
    UpsertInboxMessage::execute($account, inboxDirectIncoming('m1', at: now()->subHour()->toDateTimeString()));
    $dm = InboxConversation::query()->where('social_account_id', $account->id)->sole();
    InboxMessage::query()->where('inbox_conversation_id', $dm->id)->update(['body' => 'Secret text', 'removed_at' => now()]);

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForText($page, "@inbox-dm-card-{$dm->id} @inbox-dm-card-preview", __('inbox.direct.removed'));
    $page->assertSeeIn("@inbox-dm-card-{$dm->id}", __('inbox.direct.removed'))
        ->assertDontSeeIn("@inbox-dm-card-{$dm->id}", 'Secret text')
        ->assertNoJavaScriptErrors();
});

test('a link a network sent that is not http(s) is never rendered', function () {
    $this->comment->update(['platform_url' => 'javascript:alert(1)']);
    $this->conversation->update(['subject_url' => 'javascript:alert(2)']);

    $page = visit(route('app.inbox.conversations.show', $this->conversation));

    waitFor($page, '@inbox-comment-footer');
    $page->assertVisible('@inbox-comment-footer')
        ->assertMissing('@inbox-comment-view')
        ->assertMissing('@inbox-subject-link')
        ->assertScript("[...document.querySelectorAll('a[href]')].some((link) => link.getAttribute('href').startsWith('javascript:'))", false)
        ->assertNoJavaScriptErrors();
});

test('an instagram-only filter keeps full post cards that show their text', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->conversation->workspace_id,
        'username' => 'brand',
        'token_expires_at' => null,
        'scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights', 'instagram_business_manage_comments'],
    ]);
    $post = InboxConversation::factory()->create([
        'social_account_id' => $account->id,
        'subject_text' => 'Sunset reel',
        'subject_media' => [['type' => 'image', 'path' => 'inbox/sunset.jpg']],
    ]);
    InboxMessage::factory()->for($post, 'conversation')->create(['body' => 'Wow']);
    RecountInboxConversation::execute($post);

    $page = visit(route('app.inbox.index', ['channels' => [$account->id]]));

    waitForText($page, "@inbox-post-card-{$post->id}", 'Sunset reel');
    $page->assertSeeIn("@inbox-post-card-{$post->id}", 'Sunset reel')->assertNoJavaScriptErrors();
});

test('news from another tab only refreshes the counts, never the new pill', function () {
    $page = visit(route('app.inbox.messages.index'));

    waitFor($page, '@inbox-comment-card');
    $other = inboxSecondConversation($this->conversation, 'Elsewhere');
    emitWorkspaceEvent($page, $this->conversation->workspace_id, 'inbox.updated', ['conversation_id' => $other->id, 'kind' => 'mention', 'channel_id' => $other->social_account_id]);
    $page->script('window.__sentAt = Date.now(); true;');
    waitForCondition($page, 'Date.now() - window.__sentAt > 1500', 'the realtime debounce to pass');
    $page->assertMissing('@inbox-feed-new-pill');

    emitWorkspaceEvent($page, $this->conversation->workspace_id, 'inbox.updated', ['conversation_id' => $other->id, 'kind' => 'comment', 'channel_id' => $other->social_account_id]);
    waitFor($page, '@inbox-feed-new-pill');
    $page->assertVisible('@inbox-feed-new-pill')->assertNoJavaScriptErrors();
});

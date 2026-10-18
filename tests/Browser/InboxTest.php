<?php

declare(strict_types=1);

use App\Actions\Inbox\RecountInboxConversation;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\SocialAccount\Status;
use App\Jobs\Inbox\SendInboxReply;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
    $this->conversation = InboxConversation::factory()->create(['subject_text' => 'My launch post']);
    $this->comment = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['body' => 'Love it', 'author_username' => 'fan.bsky.social']);
    RecountInboxConversation::execute($this->conversation);
    $this->user = $this->conversation->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->conversation->workspace_id]);
    $this->actingAs($this->user->fresh());
});

test('reply to a comment from the inbox', function () {
    $page = visit(route('app.inbox.index'));

    waitFor($page, "@inbox-post-card-{$this->conversation->id}");
    $page->assertSee('My launch post')->assertSee('Love it');
    waitForState($page, '@inbox-reply-input', "element.value === '@fan.bsky.social '");
    $page->assertValue('@inbox-reply-input', '@fan.bsky.social ');

    waitForState($page, '@inbox-reply-send', inViewport());
    $page->fill('@inbox-reply-input', '@fan.bsky.social thanks!')->click('@inbox-reply-send');

    waitForState($page, '@inbox-outbound-message', "element.dataset.status === 'sending'");
    expect(InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->value('body'))->toBe('@fan.bsky.social thanks!');
    Queue::assertPushed(SendInboxReply::class);
    $page->assertNoJavaScriptErrors();
});

test('resolve and undo update the open count', function () {
    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-open-count', "element.textContent.trim() === '1'");
    waitForState($page, '@inbox-resolve', inViewport());
    $page->click('@inbox-resolve');
    waitForState($page, '@inbox-open-count', "element.textContent.trim() === '0'");
    $page->assertSeeIn('@inbox-open-count', '0');
    expect($this->comment->fresh()->resolved_at)->not->toBeNull();

    waitForState($page, '@inbox-resolve', "element.dataset.resolved === 'true' && !element.disabled");
    waitForState($page, '@inbox-resolve', inViewport());
    $page->click('@inbox-resolve');
    waitForState($page, '@inbox-open-count', "element.textContent.trim() === '1'");
    $page->assertSeeIn('@inbox-open-count', '1')->assertNoJavaScriptErrors();

    expect($this->comment->fresh()->resolved_at)->toBeNull();
});

test('switching to the list view shows the comment feed', function () {
    $page = visit(route('app.inbox.index'));

    waitFor($page, '@inbox-view-list');
    waitForState($page, '@inbox-view-list', inViewport());
    $page->click('@inbox-view-list');
    waitForPath($page, parse_url(route('app.inbox.messages.index'), PHP_URL_PATH));
    waitFor($page, '@inbox-comment-card');
    $page->assertSee('Love it')->assertSee('My launch post')->assertNoJavaScriptErrors();
});

test('on mobile the posts panel opens above the conversation and picks a post', function () {
    $second = inboxSecondConversation($this->conversation);

    $page = visit(route('app.inbox.index'))->resize(390, 844);

    waitForState($page, '@inbox-post-navigator', inViewport());
    $page->assertMissing('@inbox-post-list');

    $page->click('@inbox-posts-toggle');
    waitForState($page, '@inbox-post-list', inViewport());
    waitForGone($page, '@inbox-post-navigator');
    waitForLayout($page, '@inbox-post-list', '@inbox-conversation', 'a.bottom <= b.top');

    $page->click("@inbox-post-card-{$second->id}");
    waitForState($page, "@inbox-post-card-{$second->id}", "element.getAttribute('aria-current') === 'true'");
    waitForText($page, '@inbox-subject-card', 'Second post');

    $page->click('@inbox-posts-collapse');
    waitForState($page, '@inbox-post-navigator', inViewport());
    $page->assertMissing('@inbox-post-list')->assertNoJavaScriptErrors();
});

test('on mobile the post navigator moves between posts', function () {
    inboxSecondConversation($this->conversation);

    $page = visit(route('app.inbox.index'))->resize(390, 844);

    waitForState($page, '@inbox-post-previous', 'element.disabled');
    waitForState($page, '@inbox-post-next', '!element.disabled');
    $first = $page->script("document.querySelector('[data-testid=\"inbox-subject-card\"]').textContent.trim()");

    $page->click('@inbox-post-next');
    waitForState($page, '@inbox-post-previous', '!element.disabled');
    waitForState($page, '@inbox-post-next', 'element.disabled');
    waitForState($page, '@inbox-subject-card', 'element.textContent.trim() !== '.json_encode($first));

    $page->click('@inbox-post-previous');
    waitForState($page, '@inbox-post-previous', 'element.disabled');
    waitForState($page, '@inbox-subject-card', 'element.textContent.trim() === '.json_encode($first));
    $page->assertNoJavaScriptErrors();
});

test('picking a status updates the filter it was picked from', function () {
    $page = visit(route('app.inbox.index'));

    waitFor($page, '@inbox-status-filter');
    $page->click('@inbox-status-filter');
    waitForState($page, '@inbox-status-resolved', inViewport());
    $page->click('@inbox-status-resolved');
    waitForCondition($page, "location.search.includes('status=resolved')");
    waitForText($page, '@inbox-status-filter', __('inbox.filters.status.resolved'));
    $page->assertSeeIn('@inbox-status-filter', __('inbox.filters.status.resolved'))->assertNoJavaScriptErrors();
});

test('on mobile the tabs open in a bottom sheet', function () {
    $page = visit(route('app.inbox.index'))->resize(390, 844);

    waitForState($page, '@inbox-tab-menu', inViewport());
    $page->assertMissing('@inbox-tab-comments')->assertMissing('@inbox-status-filter');
    waitForState($page, '@inbox-filters @inbox-view-post', inViewport());
    waitForState($page, '@inbox-channel-filter', inViewport());
    $page->assertScript("document.querySelector('[data-testid=\"inbox-channel-filter\"]').innerText.trim() === ''", true)
        ->assertScript("(() => { const label = document.querySelector('[data-testid=\"inbox-tab-menu\"]'); const clipped = [label, ...label.querySelectorAll('*')].filter((node) => getComputedStyle(node).textOverflow === 'ellipsis'); return clipped.length > 0 && clipped.every((node) => node.scrollWidth <= node.clientWidth); })()", true);

    $page->click('@inbox-tab-menu');
    waitForState($page, '@inbox-tab-sheet', inViewport());
    waitForState($page, '@inbox-tab-sheet', 'Math.round(element.getBoundingClientRect().bottom) === window.innerHeight');

    $page->click('@inbox-tab-sheet-comments');
    waitForGone($page, '@inbox-tab-sheet');
    $page->assertVisible('@inbox-tab-menu')->assertNoJavaScriptErrors();
});

test('resolving a comment in the unanswered list removes its card', function () {
    $page = visit(route('app.inbox.messages.index', ['status' => 'unanswered']));

    waitFor($page, '@inbox-comment-card');
    waitForState($page, '@inbox-resolve', inViewport());
    $page->click('@inbox-resolve');
    waitForGone($page, '@inbox-comment-card');
    $page->assertMissing('@inbox-comment-card')->assertNoJavaScriptErrors();

    expect($this->comment->fresh()->resolved_at)->not->toBeNull();
});

test('try again sends the same failed reply instead of a new one', function () {
    $failed = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create([
        'parent_id' => $this->comment->id,
        'root_id' => $this->comment->id,
        'delivery_status' => DeliveryStatus::Failed,
        'delivery_error' => 'reply_failed',
    ]);

    $page = visit(route('app.inbox.index'));

    waitFor($page, '@inbox-message-retry');
    $page->assertSee(__('inbox.errors.reply_failed', ['network' => 'Bluesky']))->click('@inbox-message-retry');
    waitForState($page, '@inbox-outbound-message', "element.dataset.status === 'sending'");
    $page->assertAttribute('@inbox-outbound-message', 'data-status', 'sending')->assertNoJavaScriptErrors();

    expect($failed->fresh()->delivery_status)->toBe(DeliveryStatus::Sending)
        ->and(InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->count())->toBe(1);
    Queue::assertPushed(SendInboxReply::class);
});

test('the first use shows only the empty state when no inbox channel is connected', function () {
    InboxMessage::query()->delete();
    InboxConversation::query()->delete();
    SocialAccount::query()->delete();

    $page = visit(route('app.inbox.index'));

    waitFor($page, '@inbox-empty');
    $page->assertVisible('@inbox-empty-illustration')
        ->assertMissing('@inbox-view-list')
        ->assertMissing('@inbox-view-post')
        ->assertMissing('@inbox-filters')
        ->assertNoJavaScriptErrors();
});

test('a connected channel without comments shows the listening state inside the inbox layout', function () {
    InboxMessage::query()->delete();
    InboxConversation::query()->delete();

    $page = visit(route('app.inbox.index'));

    waitFor($page, '@inbox-listening');
    $page->assertVisible('@inbox-view-list')
        ->assertVisible('@inbox-filters')
        ->assertVisible('@inbox-posts-empty')
        ->assertVisible('@inbox-listening-post')
        ->assertVisible('@inbox-listening-channels')
        ->assertMissing('@inbox-empty')
        ->assertNoJavaScriptErrors();

    waitForState($page, '@inbox-view-list', inViewport());
    $page->click('@inbox-view-list');
    waitForPath($page, parse_url(route('app.inbox.messages.index'), PHP_URL_PATH));
    waitFor($page, '@inbox-listening');
    $page->assertMissing('@inbox-caught-up')->assertNoJavaScriptErrors();
});

test('the reorder hint shows once and Got it hides it for good', function () {
    $second = InboxConversation::factory()->create(['social_account_id' => $this->conversation->social_account_id, 'workspace_id' => $this->conversation->workspace_id]);
    InboxMessage::factory()->for($second, 'conversation')->create();
    RecountInboxConversation::execute($second);

    $page = visit(route('app.inbox.index'));

    waitFor($page, '@inbox-reorder-hint');
    $page->click('@inbox-reorder-hint-dismiss');
    waitForGone($page, '@inbox-reorder-hint');
    expect($this->user->fresh()->inbox_reorder_hint_seen_at)->not->toBeNull();

    $page = visit(route('app.inbox.index'));
    waitFor($page, "@inbox-post-card-{$second->id}");
    $page->assertMissing('@inbox-reorder-hint')->assertNoJavaScriptErrors();
});

test('the reorder button reads the posts again and brings new comments to the top', function () {
    $this->user->forceFill(['inbox_reorder_hint_seen_at' => now()])->save();
    $this->conversation->update(['last_message_at' => now()->subHour()]);
    $second = InboxConversation::factory()->create(['social_account_id' => $this->conversation->social_account_id, 'workspace_id' => $this->conversation->workspace_id]);
    InboxMessage::factory()->for($second, 'conversation')->create(['platform_created_at' => now()->subDays(2)]);
    RecountInboxConversation::execute($second);
    foreach (range(1, 10) as $index) {
        InboxConversation::factory()->create(['social_account_id' => $this->conversation->social_account_id, 'workspace_id' => $this->conversation->workspace_id, 'last_message_at' => now()->subDays(3)]);
    }

    $page = visit(route('app.inbox.index'));

    waitFor($page, "#inbox-post-items > li:first-child @inbox-post-card-{$this->conversation->id}");
    $page->assertMissing('@inbox-reorder-menu');

    InboxMessage::factory()->for($second, 'conversation')->create(['platform_created_at' => now()]);
    RecountInboxConversation::execute($second);

    $page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="inbox-post-items-scroller"]');
            scroller.scrollTop = scroller.scrollHeight;
            window.__sawReorderSpinner = false;
            new MutationObserver(() => {
                if (document.querySelector('[data-testid="inbox-reorder-loading"]')) {
                    window.__sawReorderSpinner = true;
                }
            }).observe(document.body, { childList: true, subtree: true });
        })();
    JS);
    waitForState($page, '@inbox-post-items-scroller', 'element.scrollTop > 0');

    waitForState($page, '@inbox-reorder', inViewport());
    $page->click('@inbox-reorder');

    waitForCondition($page, 'window.__sawReorderSpinner === true');
    waitFor($page, "#inbox-post-items > li:first-child @inbox-post-card-{$second->id}");
    waitForState($page, '@inbox-reorder', '!element.disabled');
    waitForState($page, '@inbox-post-items-scroller', 'element.scrollTop === 0');
    $page->assertMissing('@inbox-reorder-menu')
        ->assertScript("new URLSearchParams(location.search).has('sort')", false)
        ->assertNoJavaScriptErrors();
});

test('the posts column buttons explain themselves above them, inside the viewport', function (int $width) {
    inboxSecondConversation($this->conversation);

    $page = visit(route('app.inbox.index'))->resize($width, 844);

    if ($width < 768) {
        waitForState($page, '@inbox-posts-toggle', inViewport());
        $page->click('@inbox-posts-toggle');
    }

    foreach (['@inbox-reorder' => __('inbox.posts.reorder'), '@inbox-all-posts' => __('inbox.posts.show_all')] as $button => $label) {
        waitForState($page, $button, inViewport());
        $page->hover($button);
        waitForText($page, '@inbox-tooltip:not([data-state="closed"])', $label);
        waitForState($page, '@inbox-tooltip:not([data-state="closed"])', inViewport());
        waitForLayout($page, '@inbox-tooltip:not([data-state="closed"])', $button, 'a.bottom <= b.top');
    }

    $page->assertNoJavaScriptErrors();
})->with(['desktop' => 1280, 'mobile' => 390]);

test('show all posts lists a post without comments and opens it with an empty state', function () {
    inboxSecondConversation($this->conversation);
    $quiet = Post::factory()->forAccount($this->conversation->socialAccount)->published()->create(['content' => '<p>Quiet launch</p>']);
    $card = "@inbox-post-card-post-{$quiet->id}";

    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-all-posts', "element.getAttribute('aria-pressed') === 'false'");
    $page->assertMissing($card)->click('@inbox-all-posts');

    waitForState($page, '@inbox-all-posts', "element.getAttribute('aria-pressed') === 'true'");
    waitForCondition($page, "new URLSearchParams(location.search).get('posts') === 'all'");
    waitFor($page, $card);
    $page->assertSeeIn($card, 'Quiet launch')
        ->assertSeeIn("[data-testid=\"inbox-post-card-post-{$quiet->id}\"] [data-testid=\"inbox-post-card-no-comments\"]", __('inbox.posts.no_comments'));

    $page->hover('@inbox-conversation');
    $page->hover('@inbox-all-posts');
    waitForText($page, '@inbox-tooltip', __('inbox.posts.show_commented'));

    $page->click($card);
    waitForState($page, $card, "element.getAttribute('aria-current') === 'true'");
    waitForText($page, '@inbox-subject-card', 'Quiet launch');
    waitForState($page, '@inbox-post-no-comments', inViewport());
    $page->assertSeeIn('@inbox-post-no-comments', __('inbox.conversation.no_comments_title'))
        ->assertVisible('@inbox-subject-details')
        ->assertMissing('@inbox-comment-card')
        ->assertScript("new URLSearchParams(location.search).get('post')", $quiet->id);

    $page = visit(route('app.inbox.index', ['posts' => 'all']));
    waitForState($page, '@inbox-all-posts', "element.getAttribute('aria-pressed') === 'true'");
    waitFor($page, $card);

    $page->click('@inbox-all-posts');
    waitForState($page, '@inbox-all-posts', "element.getAttribute('aria-pressed') === 'false'");
    waitForGone($page, $card);
    $page->assertScript("new URLSearchParams(location.search).has('posts')", false)
        ->assertVisible("@inbox-post-card-{$this->conversation->id}")
        ->assertNoJavaScriptErrors();
});

test('show all posts works on mobile', function () {
    inboxSecondConversation($this->conversation);
    $quiet = Post::factory()->forAccount($this->conversation->socialAccount)->published()->create(['content' => '<p>Quiet launch</p>']);
    $card = "@inbox-post-card-post-{$quiet->id}";

    $page = visit(route('app.inbox.index'))->resize(390, 844);

    waitForState($page, '@inbox-posts-toggle', inViewport());
    $page->click('@inbox-posts-toggle');
    waitForState($page, '@inbox-all-posts', inViewport());
    $page->click('@inbox-all-posts');

    waitForState($page, $card, inViewport());
    $page->click($card);
    waitForState($page, $card, "element.getAttribute('aria-current') === 'true'");
    waitFor($page, '@inbox-post-no-comments');
    $page->assertSeeIn('@inbox-post-no-comments', __('inbox.conversation.no_comments_title'))
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});

test('show all posts is left out of the list view and comes back on by post', function () {
    inboxSecondConversation($this->conversation);

    $page = visit(route('app.inbox.index', ['posts' => 'all']));

    waitForState($page, '@inbox-view-list', inViewport());
    $page->click('@inbox-view-list');
    waitForPath($page, parse_url(route('app.inbox.messages.index'), PHP_URL_PATH));
    waitFor($page, '@inbox-comment-card');
    $page->assertMissing('@inbox-all-posts')
        ->assertScript("new URLSearchParams(location.search).has('posts')", false);

    $page->click('@inbox-view-post');
    waitForPath($page, parse_url(route('app.inbox.index'), PHP_URL_PATH));
    waitForState($page, '@inbox-all-posts', "element.getAttribute('aria-pressed') === 'true'");
    $page->assertNoJavaScriptErrors();
});

test('the sidebar shows the open inbox count as a highlighted pill', function () {
    $page = visit(route('app.inbox.index'));

    waitForState($page, '@sidebar-inbox-count', "element.textContent.trim() === '1'");
    $page->assertSeeIn('@sidebar-inbox-count', '1')
        ->assertScript("getComputedStyle(document.querySelector('[data-testid=\"sidebar-inbox-count\"]')).backgroundColor !== 'rgba(0, 0, 0, 0)'", true)
        ->assertScript("getComputedStyle(document.querySelector('[data-testid=\"sidebar-publish-count\"]')).backgroundColor === 'rgba(0, 0, 0, 0)'", true)
        ->assertNoJavaScriptErrors();
});

test('icon buttons explain themselves on hover', function () {
    $page = visit(route('app.inbox.index'));

    waitFor($page, '@inbox-sort-menu');
    $page->hover('@inbox-sort-menu');
    waitForText($page, '@inbox-tooltip', __('inbox.filters.menu'));
    $page->assertSeeIn('@inbox-tooltip', __('inbox.filters.menu'))
        ->assertScript("document.querySelector('[data-testid=\"inbox-tooltip\"]').getBoundingClientRect().bottom <= document.querySelector('[data-testid=\"inbox-sort-menu\"]').getBoundingClientRect().top", true)
        ->assertNoJavaScriptErrors();
});

test('every header menu opens anchored inside the viewport', function (string $trigger, string $option, string $query) {
    $page = visit(route('app.inbox.index'));

    waitFor($page, "@{$trigger}");
    $page->click("@{$trigger}");
    waitForState($page, '[role="menu"]', inViewport());
    waitForState($page, "@{$trigger}", "element.getAttribute('aria-expanded') === 'true'");

    if ($option !== '') {
        $page->click("@{$option}");
        waitForGone($page, '[role="menu"]');
        if ($query !== '') {
            waitForCondition($page, "location.search.includes('{$query}')");
        }
    }
    $page->assertNoJavaScriptErrors();
})->with([
    'status filter' => ['inbox-status-filter', 'inbox-status-resolved', 'status=resolved'],
    'filter and sort menu' => ['inbox-sort-menu', 'inbox-sort-oldest', 'sort=oldest'],
    'bulk resolve menu' => ['inbox-bulk-resolve', '', ''],
]);

test('the channel filter searches, selects every channel and clears them', function () {
    $this->conversation->socialAccount->update(['display_name' => 'Acme Launch']);
    $second = SocialAccount::factory()->bluesky()->withInboxScopes()->create(['workspace_id' => $this->conversation->workspace_id, 'display_name' => 'Zephyr Studio']);
    $first = $this->conversation->social_account_id;

    $page = visit(route('app.inbox.index'));
    waitForState($page, '@inbox-channel-filter', inViewport());
    $page->click('@inbox-channel-filter');
    waitForState($page, '[data-slot="popover-content"]', inViewport());
    waitForState($page, '@inbox-channel-search', inViewport());
    waitForState($page, "@inbox-channel-option-{$first}", inViewport());
    waitForState($page, "@inbox-channel-option-{$second->id}", inViewport());

    $page->fill('@inbox-channel-search', 'zephyr');
    waitForGone($page, "@inbox-channel-option-{$first}");
    $page->assertVisible("@inbox-channel-option-{$second->id}");
    $page->fill('@inbox-channel-search', '');
    waitForState($page, "@inbox-channel-option-{$first}", inViewport());

    waitForState($page, '@inbox-channel-toggle-all', inViewport());
    $page->click('@inbox-channel-toggle-all');
    waitForCondition($page, "location.search.includes('{$first}') && location.search.includes('{$second->id}')");
    waitForState($page, '@inbox-channel-count', "element.textContent.trim() === '2'");
    $page->assertSeeIn('@inbox-channel-count', '2');

    $page->click('@inbox-channel-toggle-all');
    waitForCondition($page, "!location.search.includes('channels')");
    waitForGone($page, '@inbox-channel-count');
    $page->assertScript("location.search.includes('channels')", false)->assertNoJavaScriptErrors();
});

test('picking two channels lists only their posts', function () {
    $makePost = function (string $subject): InboxConversation {
        $account = SocialAccount::factory()->bluesky()->withInboxScopes()->create(['workspace_id' => $this->conversation->workspace_id]);
        $conversation = InboxConversation::factory()->create(['social_account_id' => $account->id, 'workspace_id' => $this->conversation->workspace_id, 'subject_text' => $subject]);
        InboxMessage::factory()->for($conversation, 'conversation')->create();
        RecountInboxConversation::execute($conversation);

        return $conversation;
    };
    $second = $makePost('Second post');
    $third = $makePost('Third post');

    $page = visit(route('app.inbox.index'));
    waitFor($page, "@inbox-post-card-{$third->id}");
    $page->click('@inbox-channel-filter');
    waitForState($page, '[data-slot="popover-content"]', inViewport());
    waitForState($page, "@inbox-channel-option-{$this->conversation->social_account_id}", inViewport());
    $page->click("@inbox-channel-option-{$this->conversation->social_account_id}");
    waitForCondition($page, "location.search.includes('{$this->conversation->social_account_id}')");
    waitForState($page, "@inbox-channel-option-{$second->social_account_id}", inViewport());
    $page->click("@inbox-channel-option-{$second->social_account_id}");
    waitForCondition($page, "location.search.includes('{$this->conversation->social_account_id}') && location.search.includes('{$second->social_account_id}')");

    waitForGone($page, "@inbox-post-card-{$third->id}");
    waitFor($page, "@inbox-post-card-{$second->id}");
    $page->assertVisible("@inbox-post-card-{$this->conversation->id}")
        ->assertVisible("@inbox-post-card-{$second->id}")
        ->assertMissing("@inbox-post-card-{$third->id}")
        ->assertScript("location.search.includes('{$third->social_account_id}')", false)
        ->assertNoJavaScriptErrors();
});

test('the header menus stay inside the viewport on mobile', function () {
    $page = visit(route('app.inbox.index'))->resize(390, 844);

    waitFor($page, '@inbox-sort-menu');
    $page->click('@inbox-sort-menu');
    waitForState($page, '[role="menu"]', inViewport());
    waitForState($page, '@inbox-menu-status-resolved', inViewport());
    $page->click('@inbox-menu-status-resolved');
    waitForCondition($page, "location.search.includes('status=resolved')");
    $page->assertNoJavaScriptErrors();
});

test('the emoji picker opens inside the viewport and inserts the emoji', function () {
    $page = visit(route('app.inbox.index'));

    waitFor($page, '@inbox-reply-emoji');
    $page->click('@inbox-reply-emoji');
    waitForState($page, '[data-slot="popover-content"]', inViewport());
    $page->assertNoJavaScriptErrors();
});

test('an expired channel keeps its conversations and shows the reconnect banner', function () {
    $this->conversation->socialAccount->forceFill(['status' => Status::TokenExpired])->saveQuietly();

    $page = visit(route('app.inbox.index'));

    waitFor($page, "@inbox-reconnect-{$this->conversation->social_account_id}");
    $page->assertVisible("@inbox-post-card-{$this->conversation->id}")
        ->assertMissing('@inbox-empty')
        ->assertNoJavaScriptErrors();
});

test('the mentions tab opens the mentions feed and resolves a mention', function () {
    $mention = inboxMentionThread($this->conversation);

    $page = visit(route('app.inbox.index'));
    waitForState($page, '@inbox-mentions-count', "element.textContent.trim() === '1'");
    $page->click('@inbox-tab-mentions');
    waitForCondition($page, "location.search.includes('tab=mentions')");
    waitForState($page, "[data-thread-id=\"{$mention->id}\"]", inViewport());
    waitForState($page, '@inbox-tab-mentions', "element.getAttribute('aria-current') === 'page'");
    $page->assertSee('Shoutout to @me')
        ->assertDontSee('Love it')
        ->assertMissing('@inbox-view-post')
        ->assertMissing('@inbox-subject-card')
        ->assertSeeIn('@inbox-open-count', '1');

    $page->click("[data-thread-id=\"{$mention->id}\"] [data-testid=\"inbox-resolve\"]");
    waitForState($page, '@inbox-mentions-count', "element.textContent.trim() === '0'");
    $page->assertSeeIn('@inbox-open-count', '1')->assertNoJavaScriptErrors();

    expect($mention->fresh()->resolved_at)->not->toBeNull()
        ->and($this->comment->fresh()->resolved_at)->toBeNull();
});

test('the comments tab goes back from mentions', function () {
    inboxMentionThread($this->conversation);

    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']));
    waitFor($page, '@inbox-tab-comments');
    $page->click('@inbox-tab-comments');
    waitForPath($page, parse_url(route('app.inbox.index'), PHP_URL_PATH));
    waitForState($page, "@inbox-post-card-{$this->conversation->id}", inViewport());
    $page->assertSee('Love it')->assertNoJavaScriptErrors();
});

test('resolving all mentions from the menu leaves comments open', function () {
    $mention = inboxMentionThread($this->conversation);

    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']));
    waitFor($page, '@inbox-bulk-resolve');
    waitForState($page, '@inbox-bulk-resolve', inViewport());
    $page->click('@inbox-bulk-resolve');
    waitForState($page, '@inbox-resolve-all', inViewport());
    $page->assertSeeIn('@inbox-resolve-all', 'Resolve all mentions')
        ->assertSeeIn('@inbox-resolve-older', 'Resolve older mentions')
        ->assertMissing('@inbox-resolve-conversation');

    $page->click('@inbox-resolve-all');
    waitForState($page, '@inbox-mentions-count', "element.textContent.trim() === '0'");
    $page->assertNoJavaScriptErrors();

    expect($mention->fresh()->resolved_at)->not->toBeNull()
        ->and($this->comment->fresh()->resolved_at)->toBeNull();
});

test('on mentions a channel without mentions is shown but cannot be picked', function () {
    inboxMentionThread($this->conversation);
    $youtube = SocialAccount::factory()->youtube()->withInboxScopes()->create(['workspace_id' => $this->conversation->workspace_id]);

    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']));
    waitFor($page, '@inbox-channel-filter');
    $page->click('@inbox-channel-filter');
    waitForState($page, '[data-slot="popover-content"]', inViewport());
    waitForState($page, "@inbox-channel-option-{$youtube->id}", inViewport());
    waitForState($page, "@inbox-channel-option-{$youtube->id}", "element.hasAttribute('data-disabled')");
    $page->hover("@inbox-channel-option-{$youtube->id}");
    waitForState($page, "@inbox-channel-reason-{$youtube->id}", inViewport());
    $page->assertSeeIn("@inbox-channel-reason-{$youtube->id}", 'YouTube');

    $page->click("@inbox-channel-option-{$youtube->id}");
    $page->click('@inbox-channel-toggle-all');
    waitForCondition($page, "location.search.includes('{$this->conversation->social_account_id}')");
    waitForState($page, "@inbox-channel-checkbox-{$this->conversation->social_account_id}", "element.dataset.state === 'checked'");
    $page->assertAttribute("@inbox-channel-checkbox-{$youtube->id}", 'data-state', 'unchecked')
        ->assertAttribute("@inbox-channel-checkbox-{$this->conversation->social_account_id}", 'data-state', 'checked')
        ->assertScript("location.search.includes('{$youtube->id}')", false)
        ->assertScript("location.search.includes('{$this->conversation->social_account_id}')", true)
        ->assertNoJavaScriptErrors();
});

test('the mentions tab explains which networks have mentions when no channel has them', function () {
    $this->conversation->socialAccount->update(['platform' => 'youtube', 'scopes' => ['https://www.googleapis.com/auth/youtube.force-ssl']]);

    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']));
    waitForState($page, '@inbox-mentions-availability', inViewport());
    waitForState($page, '@inbox-mentions-network-x', inViewport());
    $page->assertSeeIn('@inbox-mentions-network-x', 'Coming soon')
        ->assertMissing('@inbox-channel-filter')
        ->assertMissing('@inbox-view-list')
        ->assertNoJavaScriptErrors();
});

test('a network tile on the mentions availability card starts its connection', function () {
    $this->conversation->socialAccount->update(['platform' => 'youtube', 'scopes' => ['https://www.googleapis.com/auth/youtube.force-ssl']]);

    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']));
    waitForState($page, '@inbox-mentions-connect-bluesky', inViewport());
    $page->click('@inbox-mentions-connect-bluesky');
    waitForPath($page, parse_url(route('app.social.bluesky.connect'), PHP_URL_PATH));
    $page->assertNoJavaScriptErrors();
});

test('on mobile the tab sheet switches to mentions', function () {
    $mention = inboxMentionThread($this->conversation);

    $page = visit(route('app.inbox.index'))->resize(390, 844);
    waitForState($page, '@inbox-tab-menu', inViewport());
    $page->click('@inbox-tab-menu');
    waitForState($page, '@inbox-tab-sheet-mentions', inViewport());
    $page->click('@inbox-tab-sheet-mentions');
    waitForGone($page, '@inbox-tab-sheet');
    waitForCondition($page, "location.search.includes('tab=mentions')");
    waitForState($page, "[data-thread-id=\"{$mention->id}\"]", inViewport());
    $page->assertSeeIn('@inbox-tab-menu', __('inbox.tabs.mentions'))->assertNoJavaScriptErrors();
});

test('resolving older mentions from the menu resolves only the old ones', function () {
    $old = inboxMentionThread($this->conversation);
    $old->update(['platform_created_at' => now()->subMonths(2)]);
    $recent = inboxMentionThread($this->conversation);

    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']));
    waitFor($page, '@inbox-bulk-resolve');
    waitForState($page, '@inbox-bulk-resolve', inViewport());
    $page->click('@inbox-bulk-resolve');
    waitForState($page, '@inbox-resolve-older', inViewport());
    $page->click('@inbox-resolve-older');
    waitForState($page, '@inbox-mentions-count', "element.textContent.trim() === '1'");
    $page->assertNoJavaScriptErrors();

    expect($old->fresh()->resolved_at)->not->toBeNull()
        ->and($recent->fresh()->resolved_at)->toBeNull();
});

test('a Threads channel without the mentions permission shows the mentions reconnect banner', function () {
    config()->set('trypost.platforms.threads.oauth_url', '');
    $threads = SocialAccount::factory()->threads()->withInboxScopes()->create(['workspace_id' => $this->conversation->workspace_id, 'username' => 'brand.threads']);

    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']));
    waitForState($page, "@inbox-reconnect-{$threads->id}", inViewport());
    $page->assertSeeIn("@inbox-reconnect-{$threads->id}", __('inbox.mentions.reconnect_title', ['name' => '@brand.threads']))
        ->assertNoJavaScriptErrors();

    waitForState($page, "@inbox-reconnect-button-{$threads->id}", inViewport());
    $page->click("@inbox-reconnect-button-{$threads->id}");
    $authorize = parse_url(config('trypost.platforms.threads.oauth_url').'/oauth/authorize', PHP_URL_PATH);
    waitForPath($page, $authorize);
    $page->assertPathIs($authorize);
});

test('the mentions tab listens before the first mention arrives', function () {
    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']));
    waitForState($page, '@inbox-listening', inViewport());
    $page->assertSeeIn('@inbox-listening', __('inbox.mentions.listening_title'))->assertNoJavaScriptErrors();
});

test('the mentions tab says when every mention is handled and when filters match none', function () {
    $mention = inboxMentionThread($this->conversation);
    $mention->update(['resolved_at' => now()]);
    RecountInboxConversation::execute($mention->conversation);

    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']));
    waitForState($page, '@inbox-caught-up', inViewport());
    $page->assertSeeIn('@inbox-caught-up', __('inbox.mentions.caught_up_description'))
        ->assertMissing('@inbox-replied-recently');

    $filtered = visit(route('app.inbox.messages.index', ['tab' => 'mentions', 'status' => 'replied']));
    waitForState($filtered, '@inbox-empty-filtered', inViewport());
    $filtered->assertSeeIn('@inbox-empty-filtered', __('inbox.mentions.filtered_title'))->assertNoJavaScriptErrors();
});

test('on mobile the menu shows a channel without mentions as unavailable', function () {
    inboxMentionThread($this->conversation);
    $youtube = SocialAccount::factory()->youtube()->withInboxScopes()->create(['workspace_id' => $this->conversation->workspace_id]);

    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']))->resize(390, 844);
    waitForState($page, '@inbox-channel-filter', inViewport());
    $page->click('@inbox-channel-filter');
    waitForState($page, '[data-slot="popover-content"]', inViewport());
    waitForState($page, "@inbox-channel-option-{$youtube->id}", inViewport());
    waitForState($page, "@inbox-channel-option-{$youtube->id}", "element.hasAttribute('data-disabled')");
    $page->hover("@inbox-channel-option-{$youtube->id}");
    waitForState($page, "@inbox-channel-reason-{$youtube->id}", inViewport());
    $page->assertSeeIn("@inbox-channel-reason-{$youtube->id}", 'YouTube')->assertNoJavaScriptErrors();
});

test('a channel whose network has no inbox is listed but cannot be picked', function () {
    $pinterest = SocialAccount::factory()->pinterest()->create(['workspace_id' => $this->conversation->workspace_id]);
    $inboxChannel = $this->conversation->social_account_id;

    $page = visit(route('app.inbox.index'));
    waitForState($page, '@inbox-channel-filter', inViewport());
    $page->click('@inbox-channel-filter');
    waitForState($page, '[data-slot="popover-content"]', inViewport());
    waitForState($page, "@inbox-channel-option-{$pinterest->id}", inViewport());
    waitForState($page, "@inbox-channel-option-{$pinterest->id}", "element.getAttribute('aria-disabled') === 'true'");

    $page->hover("@inbox-channel-option-{$pinterest->id}");
    waitForState($page, "@inbox-channel-reason-{$pinterest->id}", inViewport());
    $page->assertSeeIn("@inbox-channel-reason-{$pinterest->id}", __('inbox.filters.unavailable.network'));

    $page->click("@inbox-channel-option-{$pinterest->id}");
    $page->assertAttribute("@inbox-channel-checkbox-{$pinterest->id}", 'data-state', 'unchecked');

    $page->click('@inbox-channel-toggle-all');
    waitForCondition($page, "location.search.includes('{$inboxChannel}')");
    waitForState($page, "@inbox-channel-checkbox-{$inboxChannel}", "element.dataset.state === 'checked'");
    $page->assertAttribute("@inbox-channel-checkbox-{$pinterest->id}", 'data-state', 'unchecked')
        ->assertScript("location.search.includes('{$pinterest->id}')", false)
        ->assertNoJavaScriptErrors();
});

test('picking a channel on the mentions tab shows its mentions', function () {
    $mention = inboxMentionThread($this->conversation);
    $channelId = $this->conversation->social_account_id;

    $page = visit(route('app.inbox.messages.index', ['tab' => 'mentions']));
    waitFor($page, '@inbox-channel-filter');
    $page->click('@inbox-channel-filter');
    waitForState($page, "@inbox-channel-option-{$channelId}", inViewport());
    $page->click("@inbox-channel-option-{$channelId}");
    waitForCondition($page, "location.search.includes('{$channelId}') && location.search.includes('tab=mentions')");
    waitForState($page, "[data-thread-id=\"{$mention->id}\"]", inViewport());
    $page->assertScript("location.search.includes('{$channelId}')", true)
        ->assertSee('Shoutout to @me')
        ->assertNoJavaScriptErrors();
});

test('switching to mentions drops a channel filter mentions do not cover', function () {
    $youtube = SocialAccount::factory()->youtube()->withInboxScopes()->create(['workspace_id' => $this->conversation->workspace_id]);

    $page = visit(route('app.inbox.messages.index', ['channels' => [$youtube->id]]));
    waitFor($page, '@inbox-tab-mentions');
    $page->click('@inbox-tab-mentions');
    waitForCondition($page, "location.search.includes('tab=mentions')");
    waitForState($page, '@inbox-tab-mentions', "element.getAttribute('aria-current') === 'page'");
    $page->assertScript('location.search.includes("channels")', false)->assertNoJavaScriptErrors();
});

test('a busy post loads its next comments as the conversation scrolls', function () {
    config()->set('app.pagination.default', 2);
    $older = collect(range(1, 3))->map(fn (int $hours): InboxMessage => InboxMessage::factory()->for($this->conversation, 'conversation')->create([
        'body' => "Older comment {$hours}",
        'platform_created_at' => now()->subHours(1 + $hours),
    ]));
    RecountInboxConversation::execute($this->conversation);
    $oldest = $older->last();

    $page = visit(route('app.inbox.conversations.show', $this->conversation));
    waitFor($page, '@inbox-conversation');
    $page->assertMissing("[data-thread-id=\"{$oldest->id}\"]");

    $page->script("document.querySelector('[data-testid=\"inbox-conversation\"]').parentElement.scrollTo(0, 99999)");
    waitForState($page, "[data-thread-id=\"{$oldest->id}\"]", 'element.getBoundingClientRect().height > 0');
    $page->script("document.querySelector('[data-thread-id=\"{$oldest->id}\"]').scrollIntoView()");
    waitForState($page, "[data-thread-id=\"{$oldest->id}\"]", inViewport());
    $page->assertSee('Older comment 3')->assertNoJavaScriptErrors();
});

test('resolving a comment on a later page keeps the page and the reply draft', function () {
    config()->set('app.pagination.default', 2);
    $older = collect(range(1, 3))->map(fn (int $hours): InboxMessage => InboxMessage::factory()->for($this->conversation, 'conversation')->create([
        'body' => "Older comment {$hours}",
        'platform_created_at' => now()->subHours(1 + $hours),
    ]));
    RecountInboxConversation::execute($this->conversation);
    $oldest = $older->last();
    $draftTarget = $older->get(1);

    $page = visit(route('app.inbox.conversations.show', $this->conversation));
    waitFor($page, '@inbox-conversation');
    $page->script("document.querySelector('[data-testid=\"inbox-conversation\"]').parentElement.scrollTo(0, 99999)");
    waitForState($page, "[data-thread-id=\"{$oldest->id}\"]", 'element.getBoundingClientRect().height > 0');

    $page->script("document.querySelector('[data-thread-id=\"{$draftTarget->id}\"]').scrollIntoView()");
    $page->click("[data-thread-id=\"{$draftTarget->id}\"] [data-testid=\"inbox-message\"]");
    waitForState($page, "[data-thread-id=\"{$draftTarget->id}\"] [data-testid=\"inbox-reply-input\"]", 'element.getBoundingClientRect().height > 0');
    $page->fill("[data-thread-id=\"{$draftTarget->id}\"] [data-testid=\"inbox-reply-input\"]", 'Half written reply');

    $page->script("document.querySelector('[data-thread-id=\"{$oldest->id}\"]').scrollIntoView()");
    $page->click("[data-thread-id=\"{$oldest->id}\"] [data-testid=\"inbox-resolve\"]");
    waitForState($page, "[data-thread-id=\"{$oldest->id}\"] [data-testid=\"inbox-resolve\"]", "element.dataset.resolved === 'true'");

    $page->assertScript("document.querySelector('[data-thread-id=\"{$oldest->id}\"] [data-testid=\"inbox-resolve\"]').dataset.resolved", 'true')
        ->assertValue("[data-thread-id=\"{$draftTarget->id}\"] [data-testid=\"inbox-reply-input\"]", 'Half written reply')
        ->assertNoJavaScriptErrors();
    waitForState($page, "[data-thread-id=\"{$oldest->id}\"]", inViewport());
    $page->assertScript("(r => r.top >= 0 && r.bottom <= window.innerHeight)(document.querySelector('[data-thread-id=\"{$oldest->id}\"]').getBoundingClientRect())", true);

    expect($oldest->fresh()->resolved_at)->not->toBeNull();
});

test('the sidebar opens the list view when every inbox channel has reviews only', function () {
    $workspaceId = $this->conversation->workspace_id;
    SocialAccount::query()->whereKey($this->conversation->social_account_id)->delete();
    SocialAccount::factory()->googleBusiness()->withInboxScopes()->create(['workspace_id' => $workspaceId]);

    $page = visit(route('app.calendar'));
    waitFor($page, '[data-testid="nav-/inbox"]');
    $page->click('[data-testid="nav-/inbox"]');
    waitForPath($page, parse_url(route('app.inbox.messages.index'), PHP_URL_PATH));
    waitForState($page, '@inbox-list', inViewport());
    $page->assertPathIs(parse_url(route('app.inbox.messages.index'), PHP_URL_PATH))->assertNoJavaScriptErrors();
});

test('a comment that arrives while the post is open shows on top after a reload', function () {
    config()->set('app.pagination.default', 2);
    $older = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['body' => 'Older comment', 'platform_created_at' => now()->subHours(3)]);
    RecountInboxConversation::execute($this->conversation);

    $page = visit(route('app.inbox.conversations.show', $this->conversation));
    waitForState($page, "[data-thread-id=\"{$older->id}\"]", 'element.getBoundingClientRect().height > 0');

    $arrived = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['body' => 'Brand new comment', 'platform_created_at' => now()]);
    RecountInboxConversation::execute($this->conversation);
    $page->click("[data-thread-id=\"{$older->id}\"] [data-testid=\"inbox-resolve\"]");
    waitForState($page, "[data-thread-id=\"{$arrived->id}\"]", 'element.getBoundingClientRect().height > 0');

    $page->assertScript("document.querySelector('#inbox-conversation-items [data-thread-id]').dataset.threadId", $arrived->id)
        ->assertNoJavaScriptErrors();
});

test('a channel opens its own inbox from the sidebar, with its open count, and stays there', function () {
    $other = InboxConversation::factory()->create(['workspace_id' => $this->conversation->workspace_id, 'social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->conversation->workspace_id])->id, 'subject_text' => 'Another channel post']);
    InboxMessage::factory()->for($other, 'conversation')->create(['body' => 'Elsewhere']);
    RecountInboxConversation::execute($other);
    $channel = $this->conversation->socialAccount;

    $page = visit(route('app.channels.publish', $channel));

    waitForText($page, "@sidebar-channel-{$channel->id}-inbox-count", '1');
    $page->click("@sidebar-channel-{$channel->id}-inbox");
    waitForPath($page, parse_url(route('app.channels.inbox', $channel), PHP_URL_PATH));
    waitFor($page, "@inbox-post-card-{$this->conversation->id}");
    $page->assertMissing("@inbox-post-card-{$other->id}")
        ->assertMissing('@inbox-channel-filter')
        ->assertAttribute("@sidebar-channel-{$channel->id}-inbox", 'data-active', 'true');

    $page->click('@inbox-view-list');
    waitForPath($page, parse_url(route('app.channels.inbox.messages', $channel), PHP_URL_PATH));
    $page->assertSee('Love it')->assertDontSee('Elsewhere')->assertNoJavaScriptErrors();
});

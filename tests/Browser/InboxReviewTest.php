<?php

declare(strict_types=1);

use App\Actions\Inbox\RecountInboxConversation;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\ReviewReplyState;
use App\Jobs\Inbox\SendInboxReply;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
    $this->location = SocialAccount::factory()->googleBusiness()->create(['username' => 'My Cafe', 'display_name' => 'My Cafe']);
    $this->conversation = InboxConversation::factory()->create([
        'social_account_id' => $this->location->id,
        'kind' => ConversationKind::Review,
        'rating' => 4,
        'participant_name' => 'Ana Souza',
        'subject_text' => 'Great coffee',
    ]);
    $this->review = InboxMessage::factory()->for($this->conversation, 'conversation')->create([
        'author_name' => 'Ana Souza',
        'author_username' => null,
        'body' => 'Great coffee',
    ]);
    RecountInboxConversation::execute($this->conversation);
    $this->user = $this->location->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->location->workspace_id]);
    $this->actingAs($this->user->fresh());
});

test('a review shows its stars and is answered without a mention', function () {
    $page = visit(route('app.inbox.messages.index'));

    waitFor($page, '@inbox-review-stars');
    $page->assertSee('Great coffee')->assertSee('Ana Souza');
    $page->assertVisible('@inbox-review-stars')
        ->assertAttribute('@inbox-review-stars', 'data-rating', '4')
        ->assertMissing('@inbox-replying-to')
        ->assertValue('@inbox-reply-input', '');

    waitForLayout($page, '@inbox-message-author', '@inbox-review-stars', 'b.top >= a.bottom');
    waitForState($page, '@inbox-review-stars', 'element.getBoundingClientRect().width === 78');
    $page->assertVisible('@inbox-message-author');
    waitForLayout($page, '@inbox-review-stars', '@inbox-message-body', 'b.top - a.bottom === 6');

    $page->fill('@inbox-reply-input', '😀');
    $page->assertMissing('@inbox-reply-counter');
    $page->fill('@inbox-reply-input', str_repeat('😀', 950));
    waitForText($page, '@inbox-reply-counter', __('inbox.counter.bytes', ['length' => 3800, 'max' => 4096]));
    $page->assertSeeIn('@inbox-reply-counter', __('inbox.counter.bytes', ['length' => 3800, 'max' => 4096]));

    waitForState($page, '@inbox-reply-send', inViewport());
    $page->fill('@inbox-reply-input', 'Thank you, Ana!')->click('@inbox-reply-send');

    waitForState($page, '@inbox-outbound-message', "element.dataset.status === 'sending'");
    Queue::assertPushed(SendInboxReply::class);
    $page->assertNoJavaScriptErrors();
});

test('a reply waiting for google shows its state and the replace hint', function () {
    InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create([
        'parent_id' => $this->review->id,
        'root_id' => $this->review->id,
        'body' => 'Thanks!',
        'review_reply_state' => ReviewReplyState::Pending,
    ]);
    RecountInboxConversation::execute($this->conversation);

    $page = visit(route('app.inbox.messages.index', ['status' => 'all']));

    waitForState($page, '@inbox-review-reply-state', "element.dataset.state === 'pending'");
    $page->assertVisible('@inbox-review-reply-state')
        ->assertAttribute('@inbox-review-reply-state', 'data-state', 'pending');
    $page->click('@inbox-comment-card');
    waitFor($page, '@inbox-review-replaces-reply');
    $page->assertVisible('@inbox-review-replaces-reply');
    $page->assertNoJavaScriptErrors();
});

test('choosing a review channel in by post opens the list view', function () {
    SocialAccount::factory()->bluesky()->for($this->location->workspace)->create();

    $page = visit(route('app.inbox.index'));

    waitFor($page, '@inbox-channel-filter');
    $page->click('@inbox-channel-filter');
    waitForState($page, '[data-slot="popover-content"]', inViewport());
    waitForState($page, "@inbox-channel-option-{$this->location->id}", inViewport());
    $page->click("@inbox-channel-option-{$this->location->id}");
    waitForPath($page, parse_url(route('app.inbox.messages.index'), PHP_URL_PATH));
    waitFor($page, '@inbox-review-stars');
    $page->assertVisible('@inbox-review-stars')->assertNoJavaScriptErrors();
});

test('a rejected reply says why in words and opens the reply box with its text to edit', function () {
    $this->conversation->update(['subject_url' => 'https://business.google.com/reviews/r1']);
    InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create([
        'parent_id' => $this->review->id,
        'root_id' => $this->review->id,
        'body' => 'Call me at 555-0100',
        'review_reply_state' => ReviewReplyState::Rejected,
        'meta' => ['policy_violation' => 'PERSONAL_INFO'],
    ]);
    RecountInboxConversation::execute($this->conversation);

    $page = visit(route('app.inbox.messages.index', ['status' => 'all']));

    waitForState($page, '@inbox-review-reply-state', "element.dataset.state === 'rejected'");
    $page->assertVisible('@inbox-review-reply-state')
        ->assertVisible('@inbox-review-rejected-reason')
        ->assertSeeIn('@inbox-review-rejected-reason', __('inbox.review.rejection.personal_info'))
        ->assertVisible('@inbox-subject-link');

    $page->click('@inbox-comment-card');
    waitFor($page, '@inbox-reply-input');
    $page->assertVisible('@inbox-reply-input')->assertValue('@inbox-reply-input', 'Call me at 555-0100')
        ->assertMissing('@inbox-review-replaces-reply');
    $page->assertNoJavaScriptErrors();
});

test('an anonymous review shows the anonymous reviewer name and avatar', function () {
    $this->conversation->update(['participant_name' => null]);
    $this->review->update(['author_name' => null, 'author_avatar_path' => null]);

    $page = visit(route('app.inbox.messages.index', ['status' => 'all']));

    waitFor($page, '@inbox-message-author');
    $page->assertVisible('@inbox-message-avatar')
        ->assertVisible('@inbox-message-author')
        ->assertSeeIn('@inbox-message-author', __('inbox.review.anonymous'))
        ->assertNoJavaScriptErrors();
});

test('the by post link with a review channel lands on the list view', function () {
    $page = visit(route('app.inbox.index', ['channels' => [$this->location->id]]));

    waitForPath($page, parse_url(route('app.inbox.messages.index'), PHP_URL_PATH));
    waitFor($page, '@inbox-review-stars');
    $page->assertVisible('@inbox-review-stars')->assertNoJavaScriptErrors();
});

test('a review-only channel has no view toggle and uses review wording when all answered', function () {
    $this->review->update(['answered_at' => now()]);
    InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create([
        'parent_id' => $this->review->id,
        'root_id' => $this->review->id,
        'body' => 'Thanks!',
        'review_reply_state' => ReviewReplyState::Approved,
    ]);
    RecountInboxConversation::execute($this->conversation);

    $page = visit(route('app.inbox.messages.index', ['channels' => [$this->location->id]]));

    waitFor($page, '@inbox-caught-up');
    $page->assertVisible('@inbox-caught-up')
        ->assertSeeIn('@inbox-caught-up', __('inbox.feed.caught_up_description_reviews'))
        ->assertVisible('@inbox-replied-recently')
        ->assertSeeIn('@inbox-replied-recently', trans_choice('inbox.feed.replied_recently_reviews', 1, ['count' => 1]))
        ->assertVisible('@inbox-replied-includes')
        ->assertMissing('@inbox-view-post')
        ->assertMissing('@inbox-view-list')
        ->assertNoJavaScriptErrors();
});

test('a workspace with a comment channel keeps the view toggle and comment wording', function () {
    SocialAccount::factory()->bluesky()->for($this->location->workspace)->create();

    $page = visit(route('app.inbox.messages.index'));

    waitFor($page, '@inbox-view-list');
    $page->assertVisible('@inbox-view-post')->assertVisible('@inbox-view-list')->assertNoJavaScriptErrors();
});

test('a reviews-only channel with nothing yet speaks of reviews and offers no post to write', function () {
    $empty = SocialAccount::factory()->googleBusiness()->withInboxScopes()->create(['workspace_id' => $this->location->workspace_id, 'username' => 'Second Cafe']);

    $page = visit(route('app.channels.inbox', $empty));

    waitForText($page, '@inbox-listening', __('inbox.listening.reviews_title'));
    $page->assertSeeIn('@inbox-listening', __('inbox.listening.reviews_description'))
        ->assertMissing('@inbox-listening-post')
        ->assertVisible('@inbox-listening-connect')
        ->assertNoJavaScriptErrors();
});

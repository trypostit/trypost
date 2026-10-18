<?php

declare(strict_types=1);

use App\Actions\Inbox\RecountInboxConversation;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
    $this->conversation = InboxConversation::factory()->create(['subject_text' => 'My launch post']);
    $this->user = $this->conversation->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->conversation->workspace_id, 'timezone' => 'UTC']);
    $this->actingAs($this->user->fresh());
});

function seedScoredComments(InboxConversation $conversation): void
{
    InboxMessage::factory()->for($conversation, 'conversation')->count(7)->create([
        'platform_created_at' => now()->subMinutes(30),
        'answered_at' => now()->subMinutes(10),
    ]);
    InboxMessage::factory()->for($conversation, 'conversation')->count(3)->create([
        'platform_created_at' => now()->subMinutes(30),
    ]);
    RecountInboxConversation::execute($conversation);
}

test('the comment score badge opens its breakdown inside the viewport', function (int $width) {
    seedScoredComments($this->conversation);

    $page = visit(route('app.inbox.index'))->resize($width, 900);

    waitForText($page, '@inbox-score-badge-value', '85');
    waitForState($page, '@inbox-score-badge', inViewport());
    $page->assertAttribute('@inbox-score-badge', 'aria-label', __('inbox.score.badge', ['score' => 85]));
    $page->click('@inbox-score-badge');

    waitForState($page, '@inbox-score-popover', inViewport());
    waitForState($page, '@inbox-score-gauge', inViewport());
    $page->assertSeeIn('@inbox-score-title', __('inbox.score.title_all'))
        ->assertSeeIn('@inbox-score-value', '85')
        ->assertSeeIn('@inbox-score-level', __('inbox.score.levels.community_champion'))
        ->assertSeeIn('@inbox-score-metric-response_rate', __('inbox.score.metrics.response_rate'))
        ->assertSeeIn('@inbox-score-metric-response_rate', '70%')
        ->assertSeeIn('@inbox-score-metric-response_speed', '100%')
        ->assertSeeIn('@inbox-score-metric-consistency', '100%');
    waitForState($page, '@inbox-score-metric-consistency', inViewport());
    $page->assertMissing('@inbox-score-how-content');

    waitForState($page, '@inbox-score-how', inViewport());
    $page->click('@inbox-score-how');
    waitForState($page, '@inbox-score-how-content', inViewport());
    $page->assertSeeIn('@inbox-score-how-content', __('inbox.score.how_body'))
        ->assertNoJavaScriptErrors();
})->with(['desktop' => 1280, 'mobile' => 390]);

test('the popover names the channel picked in the filter or whose own inbox this is', function (string $route) {
    seedScoredComments($this->conversation);
    $account = $this->conversation->socialAccount;

    $page = visit($route === 'filter' ? route('app.inbox.index', ['channels' => [$account->id]]) : route('app.channels.inbox', $account));

    waitForText($page, '@inbox-score-badge-value', '85');
    $page->click('@inbox-score-badge');
    waitForState($page, '@inbox-score-popover', inViewport());
    $page->assertSeeIn('@inbox-score-title', __('inbox.score.title_channel', ['channel' => $account->display_name ?: $account->username]))
        ->assertNoJavaScriptErrors();
})->with(['filter', 'channel page']);

test('without comments this week the badge has no score and the popover says why', function () {
    InboxMessage::factory()->for($this->conversation, 'conversation')->create(['platform_created_at' => now()->subDays(10)]);
    RecountInboxConversation::execute($this->conversation);

    $page = visit(route('app.inbox.index'))->resize(390, 844);

    waitForText($page, '@inbox-score-badge-value', '–');
    $page->click('@inbox-score-badge');
    waitForState($page, '@inbox-score-empty', inViewport());
    $page->assertSeeIn('@inbox-score-empty', __('inbox.score.empty'))
        ->assertMissing('@inbox-score-gauge')
        ->assertNoJavaScriptErrors();
});

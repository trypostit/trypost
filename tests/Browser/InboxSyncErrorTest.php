<?php

declare(strict_types=1);

use App\Actions\Inbox\RecountInboxConversation;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\SyncError;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
    $this->account = SocialAccount::factory()->instagram()->create([
        'username' => 'brand',
        'token_expires_at' => null,
        'scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights', 'instagram_business_manage_comments', 'instagram_business_manage_messages'],
    ]);
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1', at: now()->subHour()->toDateTimeString()));
    $post = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'subject_text' => 'My launch post']);
    InboxMessage::factory()->for($post, 'conversation')->create(['body' => 'Love it']);
    RecountInboxConversation::execute($post);
    $this->user = $this->account->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->account->workspace_id]);
    $this->actingAs($this->user->fresh());
});

function inboxSyncState(SocialAccount $account, ConversationKind $kind, array $attributes): InboxSyncState
{
    return InboxSyncState::factory()->create([
        'social_account_id' => $account->id,
        'kind' => $kind,
        'last_synced_at' => now()->subDay(),
        ...$attributes,
    ]);
}

test('a channel not sharing its messages says how to turn them on, on the Messages tab only', function () {
    inboxSyncState($this->account, ConversationKind::DirectMessage, ['error_category' => SyncError::MessagesUnavailable]);
    $banner = "@inbox-sync-error-{$this->account->id}";

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, $banner, inViewport());
    $page->assertAttribute($banner, 'data-category', 'messages_unavailable')
        ->assertSeeIn("@inbox-sync-error-title-{$this->account->id}", __('inbox.sync_error.messages_unavailable_title', ['name' => '@brand']))
        ->assertSeeIn("@inbox-sync-error-description-{$this->account->id}", __('inbox.sync_error.messages_unavailable_instagram'))
        ->assertMissing("@inbox-sync-error-reconnect-{$this->account->id}")
        ->assertVisible("@inbox-sync-error-dismiss-{$this->account->id}");

    $page->click('@inbox-tab-comments');
    waitForCondition($page, "!location.search.includes('tab=messages')");
    waitFor($page, '@inbox-comment-card');
    $page->assertMissing($banner)->assertNoJavaScriptErrors();
});

test('Not now hides the banner', function () {
    inboxSyncState($this->account, ConversationKind::DirectMessage, ['error_category' => SyncError::MessagesUnavailable]);
    $banner = "@inbox-sync-error-{$this->account->id}";

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, $banner, inViewport());
    $page->click("@inbox-sync-error-dismiss-{$this->account->id}");
    waitForGone($page, $banner);
    $page->assertMissing($banner)->assertNoJavaScriptErrors();
});

test('comments that keep failing to load say so on the Comments tab with when they last loaded', function () {
    inboxSyncState($this->account, ConversationKind::Comment, ['consecutive_failures' => SyncError::FAILING_AFTER]);
    $banner = "@inbox-sync-error-{$this->account->id}";

    $page = visit(route('app.inbox.index'));

    waitForState($page, $banner, inViewport());
    $page->assertAttribute($banner, 'data-category', 'failing')
        ->assertSeeIn("@inbox-sync-error-title-{$this->account->id}", __('inbox.sync_error.title_comments', ['name' => '@brand']))
        ->assertSeeIn("@inbox-sync-error-description-{$this->account->id}", Str::before(__('inbox.sync_error.failing_description'), ':time'))
        ->assertVisible("@inbox-sync-error-reconnect-{$this->account->id}");

    $page->click('@inbox-tab-messages');
    waitForCondition($page, "location.search.includes('tab=messages')");
    waitFor($page, '@inbox-dm-thread');
    $page->assertMissing($banner)->assertNoJavaScriptErrors();
});

test('a channel that lost a permission offers to reconnect it', function () {
    inboxSyncState($this->account, ConversationKind::Comment, ['error_category' => SyncError::Permission]);

    $page = visit(route('app.inbox.index'));

    waitForState($page, "@inbox-sync-error-{$this->account->id}", inViewport());
    $page->assertSeeIn("@inbox-sync-error-description-{$this->account->id}", __('inbox.sync_error.permission_description'))
        ->assertVisible("@inbox-sync-error-reconnect-{$this->account->id}")
        ->assertNoJavaScriptErrors();
});

test('a member who cannot fix it sees who can and no action button', function () {
    inboxSyncState($this->account, ConversationKind::DirectMessage, ['error_category' => SyncError::MessagesUnavailable]);
    $member = workspaceMember($this->account->workspace);
    $this->actingAs($member->fresh());

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, "@inbox-sync-error-{$this->account->id}", inViewport());
    $page->assertSeeIn("@inbox-sync-error-description-{$this->account->id}", __('inbox.sync_error.messages_unavailable_member', ['network' => 'Instagram']))
        ->assertMissing("@inbox-sync-error-reconnect-{$this->account->id}")
        ->assertVisible("@inbox-sync-error-dismiss-{$this->account->id}")
        ->assertNoJavaScriptErrors();
});

test('try again clears the error and syncs the channel again', function () {
    $state = inboxSyncState($this->account, ConversationKind::DirectMessage, ['error_category' => SyncError::MessagesUnavailable, 'consecutive_failures' => 4]);
    $retry = "@inbox-sync-error-retry-{$this->account->id}";

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, $retry, inViewport());
    $page->click($retry);
    waitForGone($page, "@inbox-sync-error-{$this->account->id}");

    expect($state->fresh())
        ->error_category->toBeNull()
        ->consecutive_failures->toBe(0);
    Queue::assertPushed(SyncInboxAccount::class);
    $page->assertMissing("@inbox-sync-error-{$this->account->id}")->assertNoJavaScriptErrors();
});

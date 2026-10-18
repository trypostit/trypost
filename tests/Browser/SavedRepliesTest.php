<?php

declare(strict_types=1);

use App\Actions\Inbox\RecountInboxConversation;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\MessageDirection;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSavedReply;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
});

/**
 * @return array{0: User, 1: Workspace}
 */
function savedRepliesOwner(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return [$user->fresh(), $workspace];
}

/**
 * A Bluesky comment from "Maria Silva" (@maria.bsky.social) in the owner's inbox.
 *
 * @return array{0: User, 1: InboxConversation, 2: InboxMessage}
 */
function savedRepliesCommentInbox(): array
{
    $conversation = InboxConversation::factory()->create(['subject_text' => 'My launch post']);
    $comment = InboxMessage::factory()->for($conversation, 'conversation')->create([
        'body' => 'Love it',
        'author_name' => 'Maria Silva',
        'author_username' => 'maria.bsky.social',
    ]);
    RecountInboxConversation::execute($conversation);
    $user = $conversation->workspace->owner;
    $user->update(['current_workspace_id' => $conversation->workspace_id]);

    return [$user->fresh(), $conversation, $comment];
}

/** The script that puts the caret of a textarea at `$position`. */
function savedRepliesPlaceCaret(mixed $page, string $testId, int $position): void
{
    $page->script("(() => { const element = document.querySelector('[data-testid=\"{$testId}\"]'); element.focus(); element.setSelectionRange({$position}, {$position}); })()");
}

function savedRepliesOutboundCount(): int
{
    return InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->count();
}

test('the settings page starts empty and creates, searches, edits and deletes saved replies', function (int $width, int $height) {
    [$user] = savedRepliesOwner();
    $this->actingAs($user);

    $page = visit(route('app.saved-replies.index'))->resize($width, $height);

    waitForState($page, '@saved-replies-empty', inViewport());
    $page->assertSeeIn('@saved-replies-empty', __('saved_replies.empty_title'))
        ->assertSeeIn('@saved-replies-empty', __('saved_replies.empty_description'))
        ->assertVisible('[data-testid="saved-replies-empty"] [data-testid="saved-replies-empty-illustration"]');

    $page->click('@saved-replies-empty-create');
    waitForState($page, '@saved-reply-dialog', inViewport());
    waitForState($page, '@submit-saved-reply', 'element.disabled');
    $page->assertSeeIn('@saved-reply-counter', '0/1000');

    waitForState($page, '@saved-reply-name', inViewport());
    $page->fill('@saved-reply-name', 'Thanks');
    waitForState($page, '@submit-saved-reply', 'element.disabled');
    $page->fill('@saved-reply-content', 'Thanks, ');
    waitForText($page, '@saved-reply-counter', '8/1000');
    $page->assertSeeIn('@saved-reply-counter', '8/1000');

    waitForState($page, '@saved-reply-variable-first_name', inViewport());
    $page->click('@saved-reply-variable-first_name');
    waitForState($page, '@saved-reply-content', "element.value === 'Thanks, {first_name}'");
    $page->assertValue('@saved-reply-content', 'Thanks, {first_name}');

    waitForState($page, '@submit-saved-reply', '!element.disabled && '.inViewport());
    $page->click('@submit-saved-reply');
    waitForGone($page, '@saved-reply-dialog');
    $page->assertMissing('@saved-reply-dialog');

    $thanks = WorkspaceSavedReply::query()->sole();
    expect($thanks->content)->toBe('Thanks, {first_name}');
    waitForState($page, "@saved-reply-row-{$thanks->id}", inViewport());
    $page->assertSeeIn("@saved-reply-row-{$thanks->id}", 'Thanks, {first_name}');

    $refunds = WorkspaceSavedReply::factory()->create(['workspace_id' => $thanks->workspace_id, 'name' => 'Refunds', 'content' => 'Refunds take a week.']);
    $page->navigate(route('app.saved-replies.index'));
    waitForState($page, "@saved-reply-row-{$refunds->id}", inViewport());

    waitForState($page, '@header-search-input', inViewport());
    $page->fill('@header-search-input', 'refund');
    waitForGone($page, "@saved-reply-row-{$thanks->id}");
    $page->assertMissing("@saved-reply-row-{$thanks->id}")->assertVisible("@saved-reply-row-{$refunds->id}");
    $page->fill('@header-search-input', 'nothing like this');
    waitForState($page, '@saved-replies-no-results', inViewport());
    $page->assertVisible('@saved-replies-no-results');
    $page->clear('@header-search-input');
    waitForState($page, "@saved-reply-row-{$thanks->id}", inViewport());

    waitForState($page, "@edit-saved-reply-{$thanks->id}", inViewport());
    $page->click("@edit-saved-reply-{$thanks->id}");
    waitForState($page, '@saved-reply-dialog', inViewport());
    waitForState($page, '@saved-reply-name', "element.value === 'Thanks'");
    waitForState($page, '@submit-saved-reply', 'element.disabled');
    $page->assertSeeIn('@submit-saved-reply', __('saved_replies.edit.submit'));
    $page->fill('@saved-reply-name', 'Thank you');
    waitForState($page, '@submit-saved-reply', '!element.disabled && '.inViewport());
    $page->click('@submit-saved-reply');
    waitForGone($page, '@saved-reply-dialog');
    waitForText($page, "@saved-reply-row-{$thanks->id}", 'Thank you');
    expect($thanks->fresh()->name)->toBe('Thank you');

    $page->click("@edit-saved-reply-{$thanks->id}");
    waitForState($page, '@cancel-saved-reply', inViewport());
    $page->click('@cancel-saved-reply');
    waitForGone($page, '@saved-reply-dialog');
    $page->assertMissing('@saved-reply-dialog');

    waitForState($page, "@delete-saved-reply-{$refunds->id}", inViewport());
    $page->click("@delete-saved-reply-{$refunds->id}");
    waitForState($page, '@confirm-delete-modal', inViewport());
    $page->assertSeeIn('@confirm-delete-description', __('saved_replies.delete.description', ['name' => 'Refunds']));
    waitForState($page, '@confirm-delete-action', inViewport());
    $page->click('@confirm-delete-action');
    waitForGone($page, "@saved-reply-row-{$refunds->id}");
    $page->assertMissing("@saved-reply-row-{$refunds->id}")->assertNoJavaScriptErrors();

    expect($refunds->fresh()->trashed())->toBeTrue();
})->with([
    'desktop' => [1280, 800],
    'mobile' => [390, 844],
]);

test('the settings sidebar lists saved replies only for members who can reply in the inbox', function () {
    [$user, $workspace] = savedRepliesOwner();
    $this->actingAs($user);

    $page = visit(route('app.saved-replies.index'))->resize(1280, 800);
    waitForState($page, '@settings-nav-saved-replies', inViewport());
    $page->click('@settings-nav-saved-replies');
    waitForState($page, '@saved-replies-empty', inViewport());
    $page->assertNoJavaScriptErrors();

    $member = workspaceMember($workspace, 'approval');
    $this->actingAs($member);

    $page = visit(route('app.signatures.index'))->resize(1280, 800);
    waitFor($page, '@settings-nav-signatures');
    $page->assertMissing('@settings-nav-saved-replies')->assertNoJavaScriptErrors();
});

test('the comment reply box inserts a saved reply at the caret with the commenter filled in', function (int $width, int $height) {
    [$user, $conversation] = savedRepliesCommentInbox();
    $thanks = WorkspaceSavedReply::factory()->create(['workspace_id' => $conversation->workspace_id, 'name' => 'Thanks', 'content' => 'Thanks {first_name} ({name}, @{username})!', 'created_at' => now()]);
    $refunds = WorkspaceSavedReply::factory()->create(['workspace_id' => $conversation->workspace_id, 'name' => 'Refunds', 'content' => 'Refunds take a week.', 'created_at' => now()->subMinute()]);
    $this->actingAs($user);

    $page = visit(route('app.inbox.index'))->resize($width, $height);

    waitForState($page, '@inbox-reply-input', "element.value === '@maria.bsky.social '");
    $page->fill('@inbox-reply-input', '@maria.bsky.social  See you');
    savedRepliesPlaceCaret($page, 'inbox-reply-input', 19);

    waitForState($page, '@inbox-reply-saved-replies', inViewport());
    $page->click('@inbox-reply-saved-replies');
    waitForState($page, '@inbox-reply-saved-replies-popover', inViewport());
    waitForState($page, "@inbox-reply-saved-replies-item-{$thanks->id}", 'document.activeElement === element');
    $page->assertSeeIn('@inbox-reply-saved-replies-popover', __('saved_replies.title'))
        ->assertVisible("@inbox-reply-saved-replies-item-{$refunds->id}");

    $page->type('@inbox-reply-saved-replies-search', 'refund');
    waitForGone($page, "@inbox-reply-saved-replies-item-{$thanks->id}");
    $page->assertVisible("@inbox-reply-saved-replies-item-{$refunds->id}");
    $page->fill('@inbox-reply-saved-replies-search', 'zzz');
    waitForState($page, '@inbox-reply-saved-replies-no-results', inViewport());
    $page->assertVisible('@inbox-reply-saved-replies-no-results');
    $page->clear('@inbox-reply-saved-replies-search');
    waitForState($page, "@inbox-reply-saved-replies-item-{$thanks->id}", inViewport());

    $page->hover("@inbox-reply-saved-replies-item-{$thanks->id}");
    waitForState($page, "@inbox-reply-saved-replies-insert-{$thanks->id}", "getComputedStyle(element).opacity === '1'");
    $page->click("@inbox-reply-saved-replies-item-{$thanks->id}");

    $expected = '@maria.bsky.social Thanks Maria (Maria Silva, @maria.bsky.social)! See you';
    waitForGone($page, '@inbox-reply-saved-replies-popover');
    waitForState($page, '@inbox-reply-input', 'element.value === '.json_encode($expected));
    $page->assertValue('@inbox-reply-input', $expected);
    $caret = 19 + mb_strlen('Thanks Maria (Maria Silva, @maria.bsky.social)!');
    waitForState($page, '@inbox-reply-input', "document.activeElement === element && element.selectionStart === {$caret}");
    expect(savedRepliesOutboundCount())->toBe(0)
        ->and(WorkspaceSavedReply::query()->find($thanks->id)->content)->toBe('Thanks {first_name} ({name}, @{username})!');
    $page->assertNoJavaScriptErrors();
})->with([
    'desktop' => [1280, 800],
    'mobile' => [390, 844],
]);

test('Enter on a saved reply inserts it without sending, and Escape closes the picker', function () {
    [$user, $conversation] = savedRepliesCommentInbox();
    $thanks = WorkspaceSavedReply::factory()->create(['workspace_id' => $conversation->workspace_id, 'name' => 'Thanks', 'content' => 'Thanks {first_name}!']);
    $this->actingAs($user);

    $page = visit(route('app.inbox.index'))->resize(1280, 800);

    waitForState($page, '@inbox-reply-input', "element.value === '@maria.bsky.social '");
    waitForState($page, '@inbox-reply-saved-replies', inViewport());
    $page->click('@inbox-reply-saved-replies');
    waitForState($page, "@inbox-reply-saved-replies-item-{$thanks->id}", 'document.activeElement === element');
    $page->keys("@inbox-reply-saved-replies-item-{$thanks->id}", 'Escape');
    waitForGone($page, '@inbox-reply-saved-replies-popover');
    $page->assertMissing('@inbox-reply-saved-replies-popover');

    $page->click('@inbox-reply-saved-replies');
    waitForState($page, "@inbox-reply-saved-replies-item-{$thanks->id}", 'document.activeElement === element');
    $page->keys("@inbox-reply-saved-replies-item-{$thanks->id}", 'Enter');
    waitForState($page, '@inbox-reply-input', "element.value === '@maria.bsky.social Thanks Maria!' && document.activeElement === element");
    $page->assertValue('@inbox-reply-input', '@maria.bsky.social Thanks Maria!');

    expect(savedRepliesOutboundCount())->toBe(0);
    $page->assertMissing('@inbox-outbound-message')->assertNoJavaScriptErrors();
});

test('the picker starts empty, creates a saved reply inline and links to settings', function (int $width, int $height) {
    [$user] = savedRepliesCommentInbox();
    $this->actingAs($user);

    $page = visit(route('app.inbox.index'))->resize($width, $height);

    waitForState($page, '@inbox-reply-saved-replies', inViewport());
    $page->click('@inbox-reply-saved-replies');
    waitForState($page, '@inbox-reply-saved-replies-empty', inViewport());
    $page->assertSeeIn('@inbox-reply-saved-replies-empty', __('saved_replies.empty_title'));

    waitForState($page, '@inbox-reply-saved-replies-empty-create', inViewport());
    $page->click('@inbox-reply-saved-replies-empty-create');
    waitForState($page, '@inbox-reply-saved-replies-form-name', inViewport().' && document.activeElement === element');
    waitForState($page, '@submit-inbox-reply-saved-replies-form', 'element.disabled');
    $page->fill('@inbox-reply-saved-replies-form-name', 'Welcome');
    $page->fill('@inbox-reply-saved-replies-form-content', 'Welcome, {first_name}!');
    waitForText($page, '@inbox-reply-saved-replies-form-counter', '22/1000');
    waitForState($page, '@submit-inbox-reply-saved-replies-form', '!element.disabled && '.inViewport());
    $page->click('@submit-inbox-reply-saved-replies-form');

    waitForState($page, '[data-testid^="inbox-reply-saved-replies-item-"]', inViewport());
    $created = WorkspaceSavedReply::query()->sole();
    expect($created->only(['name', 'content']))->toBe(['name' => 'Welcome', 'content' => 'Welcome, {first_name}!']);

    waitForState($page, "@inbox-reply-saved-replies-item-{$created->id}", inViewport());
    $page->click("@inbox-reply-saved-replies-item-{$created->id}");
    waitForState($page, '@inbox-reply-input', "element.value === '@maria.bsky.social Welcome, Maria!'");
    $page->assertValue('@inbox-reply-input', '@maria.bsky.social Welcome, Maria!');

    $page->click('@inbox-reply-saved-replies');
    waitForState($page, '@inbox-reply-saved-replies-create', inViewport());
    $page->click('@inbox-reply-saved-replies-create');
    waitForState($page, '@cancel-inbox-reply-saved-replies-form', inViewport());
    $page->click('@cancel-inbox-reply-saved-replies-form');
    waitForState($page, '@inbox-reply-saved-replies-settings', inViewport());
    $page->click('@inbox-reply-saved-replies-settings');
    waitForPath($page, parse_url(route('app.saved-replies.index'), PHP_URL_PATH));
    waitForState($page, "@saved-reply-row-{$created->id}", inViewport());
    $page->assertPathIs(parse_url(route('app.saved-replies.index'), PHP_URL_PATH))->assertNoJavaScriptErrors();
})->with([
    'desktop' => [1280, 800],
    'mobile' => [390, 844],
]);

test('the direct message composer inserts a saved reply with the participant filled in', function (int $width, int $height) {
    $account = SocialAccount::factory()->instagram()->create([
        'username' => 'brand',
        'token_expires_at' => null,
        'scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights', 'instagram_business_manage_comments', 'instagram_business_manage_messages'],
    ]);
    UpsertInboxMessage::execute($account, inboxDirectIncoming('m1', at: now()->subHour()->toDateTimeString()));
    $thanks = WorkspaceSavedReply::factory()->create(['workspace_id' => $account->workspace_id, 'name' => 'Thanks', 'content' => 'Hi {first_name}, thanks for writing!']);
    $user = $account->workspace->owner;
    $user->update(['current_workspace_id' => $account->workspace_id]);
    $this->actingAs($user->fresh());

    $dm = InboxConversation::query()->sole();
    $page = visit(route('app.inbox.index', ['tab' => 'messages']))->resize($width, $height);

    waitForState($page, "@inbox-dm-card-{$dm->id}", inViewport());
    $page->click("@inbox-dm-card-{$dm->id}");
    waitForState($page, '@inbox-dm-window', "element.dataset.state === 'open'");
    waitForState($page, '@inbox-dm-input', inViewport());
    $page->fill('@inbox-dm-input', 'See you');
    waitForState($page, '@inbox-dm-input', "element.value === 'See you'");
    savedRepliesPlaceCaret($page, 'inbox-dm-input', 0);

    waitForState($page, '@inbox-dm-saved-replies', inViewport());
    $page->click('@inbox-dm-saved-replies');
    waitForState($page, '@inbox-dm-saved-replies-popover', inViewport());
    waitForState($page, "@inbox-dm-saved-replies-item-{$thanks->id}", 'document.activeElement === element');
    $page->type('@inbox-dm-saved-replies-search', 'thanks');
    waitForState($page, "@inbox-dm-saved-replies-item-{$thanks->id}", inViewport());
    $page->keys("@inbox-dm-saved-replies-item-{$thanks->id}", 'Enter');

    $expected = 'Hi Ana, thanks for writing!See you';
    waitForState($page, '@inbox-dm-input', 'element.value === '.json_encode($expected).' && document.activeElement === element && element.selectionStart === 27');
    $page->assertValue('@inbox-dm-input', $expected);
    $page->assertMissing('@inbox-dm-counter');

    expect(savedRepliesOutboundCount())->toBe(0);
    $page->assertNoJavaScriptErrors();
})->with([
    'desktop' => [1280, 800],
    'mobile' => [390, 844],
]);

test('a member who needs approval sees no saved replies page', function () {
    [, $workspace] = savedRepliesOwner();
    $member = workspaceMember($workspace, 'approval');
    $this->actingAs($member);

    $page = visit(route('app.saved-replies.index'));
    waitForCondition($page, "document.readyState === 'complete'");
    $page->assertMissing('@create-saved-reply-button')->assertMissing('@saved-replies-empty');
});

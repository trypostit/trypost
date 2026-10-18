<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\SocialAccount\Status;
use App\Jobs\Inbox\SendInboxReply;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Support\Inbox\InboxMediaSlots;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
    $this->account = SocialAccount::factory()->instagram()->create([
        'username' => 'brand',
        'token_expires_at' => null,
        'scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights', 'instagram_business_manage_comments', 'instagram_business_manage_messages'],
    ]);
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1', at: now()->subHour()->toDateTimeString()));
    $this->dm = InboxConversation::query()->sole();
    $this->user = $this->account->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->account->workspace_id]);
    $this->actingAs($this->user->fresh());
});

test('the Messages tab opens from the tab row and answers a DM', function () {
    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-tab-messages', inViewport());
    $page->click('@inbox-tab-messages');
    waitForState($page, "@inbox-dm-card-{$this->dm->id}", inViewport());
    waitForState($page, '@inbox-dm-window', "element.dataset.state === 'open'");
    waitForState($page, '@inbox-dm-input', inViewport());
    $page->assertMissing('@inbox-view-list');

    waitForState($page, '@inbox-dm-input', inViewport());
    $page->fill('@inbox-dm-input', 'Thanks for writing!');
    waitForState($page, '@inbox-dm-send', inViewport().' && !element.disabled');
    $page->click('@inbox-dm-send');

    waitForState($page, '@inbox-dm-message[data-direction="outbound"]', "element.dataset.status === 'sending'");
    waitForState($page, '@inbox-dm-input', "element.value === ''");
    expect(InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->value('body'))->toBe('Thanks for writing!');
    Queue::assertPushed(SendInboxReply::class);
    $page->assertNoJavaScriptErrors();
});

test('the emoji button opens the picker', function () {
    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, '@inbox-dm-emoji', inViewport());
    $page->click('@inbox-dm-emoji');
    waitForState($page, '[role="dialog"]', inViewport());
    $page->assertNoJavaScriptErrors();
});

test('a message past the byte limit cannot be sent', function () {
    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitFor($page, '@inbox-dm-input');
    waitForState($page, '@inbox-dm-input', inViewport());
    $page->fill('@inbox-dm-input', str_repeat('😀', 300));
    waitForText($page, '@inbox-dm-counter', __('inbox.counter.bytes', ['length' => 1200, 'max' => 1000]));
    waitForState($page, '@inbox-dm-send', 'element.disabled');
    $page->assertNoJavaScriptErrors();
});

test('a closed window hides the composer', function () {
    $this->dm->forceFill(['last_inbound_at' => now()->subDay()->subMinute()])->save();

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, '@inbox-dm-window', "element.dataset.state === 'closed'");
    waitForState($page, '@inbox-dm-window', inViewport());
    $page->assertMissing('@inbox-dm-input')->assertNoJavaScriptErrors();
});

test('resolve and reopen follow the Messages tab count', function () {
    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, '@inbox-messages-count', "element.textContent.trim() === '1'");
    waitForState($page, '@inbox-dm-resolve', inViewport());
    $page->click('@inbox-dm-resolve');
    waitForState($page, '@inbox-messages-count', "element.textContent.trim() === '0'");
    waitForState($page, '@inbox-dm-resolve', "element.dataset.resolved === 'true' && !element.disabled");
    $page->click('@inbox-dm-resolve');
    waitForState($page, '@inbox-messages-count', "element.textContent.trim() === '1'");
    $page->assertNoJavaScriptErrors();

    expect($this->dm->fresh()->open_count)->toBe(1);
});

test('the bulk menu resolves every message', function () {
    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitFor($page, '@inbox-bulk-resolve');
    waitForState($page, '@inbox-bulk-resolve', inViewport());
    $page->click('@inbox-bulk-resolve');
    waitForState($page, '@inbox-resolve-all', inViewport());
    $page->assertSeeIn('@inbox-resolve-all', 'Resolve all messages');
    $page->click('@inbox-resolve-all');
    waitForState($page, '@inbox-messages-count', "element.textContent.trim() === '0'");
    $page->assertNoJavaScriptErrors();
});

test('older messages load when the thread scrolls up', function () {
    config()->set('app.pagination.default', 5);
    collect(range(2, 9))->each(fn (int $minute) => UpsertInboxMessage::execute($this->account, inboxDirectIncoming("m{$minute}", at: now()->subMinutes(60 - $minute)->toDateTimeString())));

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, '@inbox-dm-message[data-message-id]', 'true');
    $oldest = InboxMessage::query()->where('platform_message_id', 'm1')->value('id');
    $page->script("document.querySelector('[data-testid=\"inbox-dm-messages\"]').scrollTo({ top: 0 })");
    waitForState($page, "[data-message-id=\"{$oldest}\"]", 'element.getBoundingClientRect().height > 0');
    $page->assertNoJavaScriptErrors();
});

test('a channel that cannot read messages shows the reconnect banner', function () {
    $this->account->update(['scopes' => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights', 'instagram_business_manage_comments']]);

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, "@inbox-reconnect-messages-{$this->account->id}", inViewport());
    waitForState($page, "@inbox-reconnect-button-{$this->account->id}", inViewport());
    $page->assertNoJavaScriptErrors();
});

test('on mobile the thread opens full screen and back returns to the list', function () {
    $page = visit(route('app.inbox.index', ['tab' => 'messages']))->resize(390, 844);

    waitForState($page, "@inbox-dm-card-{$this->dm->id}", inViewport());
    $page->assertMissing('@inbox-dm-thread');
    $page->click("@inbox-dm-card-{$this->dm->id}");
    waitForState($page, '@inbox-back', inViewport());
    waitForState($page, '@inbox-dm-input', inViewport());
    waitForLayout($page, '@inbox-dm-header', '@inbox-dm-pane', 'Math.abs(a.top - b.top) <= 1 && Math.abs(a.left - b.left) <= 1 && Math.abs(a.right - b.right) <= 1');
    waitForLayout($page, '@inbox-dm-composer-bar', '@inbox-dm-pane', 'Math.abs(a.bottom - b.bottom) <= 1 && a.bottom <= window.innerHeight');
    waitForCondition($page, 'document.scrollingElement.scrollHeight <= window.innerHeight');
    waitForState($page, '@inbox-dm-list', 'element.getBoundingClientRect().height === 0');
    $page->click('@inbox-back');
    waitForState($page, "@inbox-dm-card-{$this->dm->id}", inViewport());
    $page->assertNoJavaScriptErrors();
});

test('on mobile the tab sheet offers Messages', function () {
    $page = visit(route('app.inbox.index'))->resize(390, 844);

    waitFor($page, '@inbox-tab-menu');
    $page->click('@inbox-tab-menu');
    waitForState($page, '@inbox-tab-sheet-messages', inViewport());
    $page->click('@inbox-tab-sheet-messages');
    waitForState($page, "@inbox-dm-card-{$this->dm->id}", inViewport());
    $page->assertNoJavaScriptErrors();
});

test('the Bluesky connect page asks for DM access', function () {
    $page = visit(route('app.social.bluesky.connect'));

    waitForState($page, '@bluesky-message-access-hint', inViewport());
    $page->assertNoJavaScriptErrors();
});

test('a participant picture that no longer loads falls back to the initial', function () {
    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitFor($page, "@inbox-dm-card-{$this->dm->id}");
    waitForGone($page, "@inbox-dm-card-{$this->dm->id} [data-slot=\"avatar\"] img");
    $page->assertSeeIn("@inbox-dm-card-{$this->dm->id}", 'AF')->assertNoJavaScriptErrors();
});

test('a conversation the person never wrote in shows the closed window', function () {
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('echo-1', MessageDirection::Outbound, now()->subMinutes(5)->toDateTimeString(), participant: 'igsid-echo'));
    $echoOnly = InboxConversation::query()->where('platform_conversation_id', 'igsid-echo')->sole();

    $page = visit(route('app.inbox.conversations.show', $echoOnly));

    waitForState($page, '@inbox-dm-window', "element.dataset.state === 'closed'");
    $page->assertMissing('@inbox-dm-input')->assertNoJavaScriptErrors();
});

function secondDirectConversation(SocialAccount $account): InboxConversation
{
    UpsertInboxMessage::execute($account, inboxDirectIncoming('b1', at: now()->subMinutes(10)->toDateTimeString(), participant: 'igsid-2'));
    $conversation = InboxConversation::query()->where('platform_conversation_id', 'igsid-2')->sole();
    $conversation->forceFill(['participant_name' => 'Bia Lima', 'participant_username' => 'bia.lima'])->save();

    return $conversation;
}

test('resolving the conversation the index opened keeps that person on screen', function () {
    secondDirectConversation($this->account);

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForText($page, '@inbox-dm-participant', 'Bia Lima');
    waitForState($page, '@inbox-dm-resolve', inViewport());
    $page->click('@inbox-dm-resolve');
    waitForState($page, '@inbox-messages-count', "element.textContent.trim() === '1'");
    waitForState($page, '@inbox-dm-resolve', "element.dataset.resolved === 'true' && !element.disabled");

    $page->assertSeeIn('@inbox-dm-participant', 'Bia Lima')
        ->assertNoJavaScriptErrors();
});

test('on mobile the bulk menu offers to resolve a conversation only while it is open', function () {
    $page = visit(route('app.inbox.index', ['tab' => 'messages']))->resize(390, 844);

    waitForState($page, '@inbox-bulk-resolve', inViewport());
    $page->click('@inbox-bulk-resolve');
    waitForState($page, '@inbox-resolve-all', inViewport());
    $page->assertMissing('@inbox-resolve-conversation');
    $page->keys('@inbox-resolve-all', 'Escape');
    waitForGone($page, '[role="menu"]');

    $page->click("@inbox-dm-card-{$this->dm->id}");
    waitForState($page, '@inbox-back', inViewport());
    $page->click('@inbox-bulk-resolve');
    waitForState($page, '@inbox-resolve-conversation', inViewport());
    $page->assertNoJavaScriptErrors();
});

test('a failed message without a stored reason says it could not be sent and tries again', function () {
    $failed = InboxMessage::factory()->outbound()->for($this->dm, 'conversation')->create([
        'social_account_id' => $this->account->id,
        'delivery_status' => DeliveryStatus::Failed,
        'delivery_error' => null,
        'body' => 'Did this go out?',
        'platform_created_at' => now()->subMinutes(5),
    ]);

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, '@inbox-dm-failed-reason', inViewport());
    $page->assertSeeIn('@inbox-dm-failed-reason', __('inbox.direct.failed', ['network' => 'Instagram']));
    waitForState($page, '@inbox-dm-retry', inViewport());
    $page->click('@inbox-dm-retry');
    waitForState($page, "[data-message-id=\"{$failed->id}\"]", "element.dataset.status === 'sending'");
    $page->assertNoJavaScriptErrors();

    Queue::assertPushed(SendInboxReply::class, fn (SendInboxReply $job): bool => $job->messageId === $failed->id);
});

test('the conversation link opens it on the network', function () {
    $this->dm->forceFill(['subject_url' => 'https://trypost.test/inbox'])->save();

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, '@inbox-dm-open', inViewport());
    $page->assertAttribute('@inbox-dm-open', 'href', 'https://trypost.test/inbox')
        ->assertAttribute('@inbox-dm-open', 'target', '_blank');
    $page->click('@inbox-dm-open');
    $page->assertPathIs(parse_url(route('app.inbox.index'), PHP_URL_PATH))->assertNoJavaScriptErrors();
});

test('the Messages tab listens before the first message arrives', function () {
    InboxConversation::query()->delete();

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, '@inbox-listening', inViewport());
    $page->assertSeeIn('@inbox-listening', __('inbox.direct.listening_title'))
        ->assertVisible('@inbox-listening-channels')
        ->assertNoJavaScriptErrors();
});

test('a messages channel that must be reconnected says its messages cannot show', function () {
    $this->account->forceFill(['status' => Status::TokenExpired])->saveQuietly();

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, "@inbox-reconnect-title-{$this->account->id}", inViewport());
    $page->assertSeeIn("@inbox-reconnect-title-{$this->account->id}", __('inbox.reconnect.title_messages', ['name' => '@brand']))
        ->assertMissing('@inbox-dm-input')
        ->assertNoJavaScriptErrors();
});

/**
 * @param  list<array{type: string, path: string}>  $media
 * @param  array<string, mixed>  $meta
 */
function storeDirectContent(SocialAccount $account, string $id, int $minutesAgo, ?string $body, array $media = [], array $meta = [], MessageDirection $direction = MessageDirection::Inbound, string $participant = 'igsid-1'): InboxMessage
{
    $message = UpsertInboxMessage::execute($account, inboxDirectIncoming($id, $direction, at: now()->subMinutes($minutesAgo)->toDateTimeString(), participant: $participant));
    $message->forceFill(['body' => $body, 'media' => $media, 'meta' => $meta])->save();

    return $message;
}

/**
 * Puts a 1x1 GIF on a disk the test server serves and returns its path,
 * so a stored inbox image loads in the page like it does from the bucket.
 */
function storeServedInboxImage(string $path): string
{
    config()->set('filesystems.disks.inbox_served_test', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/disks/inbox-served'),
        'url' => '/inbox-served-test',
        'throw' => false,
    ]);
    config()->set('filesystems.default', 'inbox_served_test');
    Route::get('inbox-served-test/{path}', fn (string $path) => Storage::response($path))->where('path', '.*');
    Storage::put($path, (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));

    return $path;
}

test('a shared reel with its preview opens the post, and the list names it', function () {
    $share = storeDirectContent($this->account, 'm-share', 5, null, meta: [
        'content' => ['type' => 'shared_post', 'url' => 'https://www.instagram.com/reel/abc/', 'title' => 'New drop is live', 'thumbnail_path' => storeServedInboxImage('inbox/share.gif'), 'thumbnail_type' => 'image'],
        'reactions' => [['emoji' => '❤', 'user_id' => 'igsid-1']],
    ]);
    $message = "[data-message-id=\"{$share->id}\"]";
    $card = "{$message} [data-testid=\"inbox-dm-shared-post\"]";
    $thumbnail = "{$card} [data-testid=\"inbox-dm-shared-post-thumbnail\"] img";
    $preview = "[data-testid=\"inbox-dm-card-{$this->dm->id}\"] [data-testid=\"inbox-dm-card-preview\"]";

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, $card, inViewport());
    waitForState($page, $thumbnail, 'element.complete && element.naturalWidth > 0');
    waitForText($page, $preview, 'Shared a reel');
    $page->assertAttribute("{$card} [data-testid=\"inbox-dm-shared-post-link\"]", 'href', 'https://www.instagram.com/reel/abc/')
        ->assertAttribute("{$card} [data-testid=\"inbox-dm-shared-post-link\"]", 'target', '_blank')
        ->assertAttribute($card, 'data-share-kind', 'reel')
        ->assertAttribute($thumbnail, 'src', Storage::url('inbox/share.gif'))
        ->assertVisible("{$card} [data-testid=\"inbox-dm-shared-reel-badge\"]")
        ->assertSeeIn($card, 'New drop is live')
        ->assertSeeIn("{$message} [data-testid=\"inbox-dm-reactions\"]", '❤')
        ->assertSeeIn($preview, 'Shared a reel')
        ->assertMissing("{$message} [data-testid=\"inbox-dm-unavailable\"]")
        ->assertNoJavaScriptErrors();
});

test('a share with only its link shows a link card that opens the post', function () {
    $share = storeDirectContent($this->account, 'm-link-only', 10, null, meta: ['content' => ['type' => 'shared_post', 'url' => 'https://www.instagram.com/reel/abc/']]);
    $card = "[data-message-id=\"{$share->id}\"] [data-testid=\"inbox-dm-shared-post\"]";

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, $card, inViewport());
    $page->assertVisible($card)
        ->assertSeeIn($card, 'Shared a reel')
        ->assertAttribute("{$card} [data-testid=\"inbox-dm-shared-post-link\"]", 'href', 'https://www.instagram.com/reel/abc/')
        ->assertMissing("[data-message-id=\"{$share->id}\"] [data-testid=\"inbox-dm-unavailable\"]")
        ->assertNoJavaScriptErrors();
});

test('a share without a link and unsupported content read as unavailable on Instagram', function () {
    $share = storeDirectContent($this->account, 'm-no-link', 10, null, meta: ['content' => ['type' => 'shared_post']]);
    $outbound = storeDirectContent($this->account, 'm-unsupported-out', 5, null, meta: ['content' => ['type' => 'unsupported']], direction: MessageDirection::Outbound);
    $preview = "[data-testid=\"inbox-dm-card-{$this->dm->id}\"] [data-testid=\"inbox-dm-card-preview\"]";

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForText($page, $preview, 'You: Message unavailable');

    foreach ([$share, $outbound] as $message) {
        $bubble = "[data-message-id=\"{$message->id}\"] [data-testid=\"inbox-dm-unavailable\"]";
        waitForState($page, $bubble, inViewport());
        $page->assertVisible($bubble)
            ->assertSeeIn($bubble, 'Message unavailable')
            ->assertSeeIn($bubble, 'This content may have been deleted by its owner or hidden by privacy settings.');
    }

    $page->assertPresent("[data-direction=\"outbound\"][data-message-id=\"{$outbound->id}\"] [data-testid=\"inbox-dm-unavailable\"]")
        ->assertMissing("[data-message-id=\"{$share->id}\"] [data-testid=\"inbox-dm-shared-post\"]")
        ->assertSeeIn($preview, 'You: Message unavailable')
        ->assertNoJavaScriptErrors();
});

test('a story reply shows its label, an image shows in its bubble', function () {
    $image = storeDirectContent($this->account, 'm-image', 10, null, media: [['type' => 'image', 'path' => storeServedInboxImage('inbox/image.gif')]]);
    $story = storeDirectContent($this->account, 'm-story', 5, null, meta: ['content' => ['type' => 'story_reply', 'url' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1']]);
    $imageElement = "[data-message-id=\"{$image->id}\"] [data-testid=\"inbox-dm-media\"] img";
    $label = "[data-message-id=\"{$story->id}\"] [data-testid=\"inbox-dm-story-reply\"]";
    $preview = "[data-testid=\"inbox-dm-card-{$this->dm->id}\"] [data-testid=\"inbox-dm-card-preview\"]";

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, $imageElement, 'element.complete && element.naturalWidth > 0');
    waitForState($page, $label, inViewport());
    waitForText($page, $preview, 'Replied to your story');
    $page->assertAttribute($imageElement, 'src', Storage::url('inbox/image.gif'))
        ->assertVisible($label)
        ->assertSeeIn($label, 'Replied to your story')
        ->assertSeeIn($preview, 'Replied to your story')
        ->assertMissing("[data-message-id=\"{$image->id}\"] [data-testid=\"inbox-dm-unavailable\"]")
        ->assertMissing("[data-message-id=\"{$story->id}\"] [data-testid=\"inbox-dm-unavailable\"]")
        ->assertNoJavaScriptErrors();
});

test('an image in a direct message opens in the media viewer', function () {
    $image = storeDirectContent($this->account, 'm-image-viewer', 10, null, media: [['type' => 'image', 'path' => storeServedInboxImage('inbox/viewer.gif')]]);
    $thumbnail = "[data-message-id=\"{$image->id}\"] [data-testid=\"inbox-dm-media\"]";

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, $thumbnail, inViewport());
    $page->click($thumbnail);
    waitForState($page, '@media-lightbox', inViewport());
    $page->assertVisible('@media-lightbox-image')
        ->assertAttribute('@media-lightbox-image', 'src', Storage::url('inbox/viewer.gif'));

    $page->click('@media-lightbox-close');
    waitForGone($page, '@media-lightbox');
    $page->assertMissing('@media-lightbox')->assertNoJavaScriptErrors();
});

test('a Messenger message Meta cannot show reads as expired', function () {
    $page = SocialAccount::factory()->facebook()->withInboxScopes()->create(['workspace_id' => $this->account->workspace_id, 'username' => 'brand-page', 'token_expires_at' => null]);
    $page->update(['scopes' => [...$page->scopes, 'pages_messaging']]);
    storeDirectContent($page, 'm_fb', 0, null, meta: ['content' => ['type' => 'unsupported']], participant: 'psid-1');
    $conversation = InboxConversation::query()->where('social_account_id', $page->id)->sole();
    $preview = "[data-testid=\"inbox-dm-card-{$conversation->id}\"] [data-testid=\"inbox-dm-card-preview\"]";

    $browser = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForText($browser, $preview, 'Message expired');
    $browser->assertSeeIn($preview, 'Message expired')
        ->click("@inbox-dm-card-{$conversation->id}");
    waitForText($browser, '@inbox-dm-unavailable', 'This message is no longer available.');
    waitForState($browser, '@inbox-dm-unavailable', inViewport());
    $browser->assertVisible('@inbox-dm-unavailable')
        ->assertSeeIn('@inbox-dm-unavailable', 'Message expired')
        ->assertSeeIn('@inbox-dm-unavailable', 'This message is no longer available.')
        ->assertNoJavaScriptErrors();
});

test('the open button names the network it opens the conversation on', function () {
    $this->dm->forceFill(['subject_url' => 'https://ig.me/m/ana.fan'])->save();

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, '@inbox-dm-open', inViewport());
    $page->assertAttribute('@inbox-dm-open', 'aria-label', 'Open on Instagram')
        ->assertAttribute('@inbox-dm-open', 'href', 'https://ig.me/m/ana.fan')
        ->assertNoJavaScriptErrors();
});

test('on desktop the conversation fills the pane with the header on top, the composer at the bottom and only the messages scrolling', function () {
    collect(range(2, 24))->each(fn (int $minute) => UpsertInboxMessage::execute($this->account, inboxDirectIncoming("m{$minute}", direction: $minute % 3 === 0 ? MessageDirection::Outbound : MessageDirection::Inbound, at: now()->subMinutes(60 - $minute)->toDateTimeString())));

    $page = visit(route('app.inbox.index', ['tab' => 'messages']))->resize(1280, 800);

    waitForState($page, '@inbox-dm-input', inViewport());
    waitForLayout($page, '@inbox-dm-pane', '@inbox-messages', 'Math.abs(a.right - b.right) <= 1 && Math.abs(a.bottom - b.bottom) <= 1');
    waitForLayout($page, '@inbox-dm-thread', '@inbox-dm-pane', 'Math.abs(a.left - b.left) <= 1 && Math.abs(a.right - b.right) <= 1');
    waitForLayout($page, '@inbox-dm-messages', '@inbox-dm-pane', 'Math.abs(a.right - b.right) <= 1');
    waitForLayout($page, '@inbox-dm-header', '@inbox-dm-pane', 'Math.abs(a.top - b.top) <= 1 && Math.abs(a.right - b.right) <= 1');
    waitForLayout($page, '@inbox-dm-composer-bar', '@inbox-dm-pane', 'Math.abs(a.bottom - b.bottom) <= 1 && Math.abs(a.right - b.right) <= 1 && a.bottom <= window.innerHeight');
    waitForLayout($page, '@inbox-dm-header', '@inbox-dm-messages', 'a.bottom <= b.top + 1');
    waitForLayout($page, '@inbox-dm-messages', '@inbox-dm-composer-bar', 'a.bottom <= b.top + 1');
    waitForCondition($page, 'document.scrollingElement.scrollHeight <= window.innerHeight');
    waitForState($page, '@app-layout-scroller', 'element.scrollHeight <= element.clientHeight');
    waitForState($page, '@inbox-dm-messages', 'element.scrollHeight > element.clientHeight && element.scrollHeight - element.scrollTop - element.clientHeight < 2');
    $page->assertNoJavaScriptErrors();
});

test('the conversation header names the person, the channel it came in on and labels its actions', function () {
    $this->dm->forceFill(['subject_url' => 'https://ig.me/m/ana.fan'])->save();

    $page = visit(route('app.inbox.index', ['tab' => 'messages']))->resize(1280, 800);

    waitForText($page, '@inbox-dm-participant', 'Ana Fan');
    waitForText($page, '@inbox-dm-via', 'via @brand');
    waitForText($page, '@inbox-dm-resolve', 'Resolve');
    waitForText($page, '@inbox-dm-open', 'Open on Instagram');
    waitForLayout($page, '@inbox-dm-open', '@inbox-dm-header', 'a.right <= b.right && a.top >= b.top && a.bottom <= b.bottom');
    waitForState($page, '@inbox-dm-window', "element.dataset.state === 'open'");
    waitForLayout($page, '@inbox-dm-window', '@inbox-dm-composer', 'a.bottom <= b.top + 1');
    $page->assertMissing('@inbox-dm-counter')->assertNoJavaScriptErrors();
});

test('the new messages pill sits above the first conversation and restarts the list at the top', function () {
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m-second', at: now()->subHours(2)->toDateTimeString(), participant: 'igsid-2'));

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));
    waitForState($page, "@inbox-dm-card-{$this->dm->id}", inViewport());

    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m-fresh', at: now()->toDateTimeString(), participant: 'igsid-3'));
    $fresh = InboxConversation::query()->where('platform_conversation_id', 'igsid-3')->sole();
    emitWorkspaceEvent($page, $this->account->workspace_id, 'inbox.updated', ['conversation_id' => $fresh->id]);

    waitForState($page, '@inbox-dm-new-pill', inViewport());
    waitForLayout($page, '@inbox-dm-new-pill', '#inbox-dm-items > li', 'a.bottom <= b.top');
    $page->assertSeeIn('@inbox-dm-new-pill', __('inbox.new_activity.messages'));

    $page->click('@inbox-dm-new-pill');
    waitForGone($page, '@inbox-dm-new-pill');
    waitFor($page, "@inbox-dm-card-{$fresh->id}");
    $page->assertMissing('@inbox-dm-new-pill')->assertNoJavaScriptErrors();
});

test('every message the person wrote shows their picture, never initials on some', function () {
    storeDirectContent($this->account, 'm-2', 40, 'Second one');
    storeDirectContent($this->account, 'm-3', 30, 'Third one');
    $this->dm->refresh()->forceFill(['participant_avatar_path' => storeServedInboxImage('inbox/ana.gif')])->save();
    InboxMessage::query()->where('platform_message_id', 'm-2')->update(['author_avatar_path' => 'inbox/missing.gif']);
    $avatars = '[data-testid="inbox-dm-message"][data-direction="inbound"] [data-slot="avatar"] img';

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForCondition($page, "document.querySelectorAll('[data-testid=\"inbox-dm-message\"][data-direction=\"inbound\"]').length === 3");
    waitForCondition($page, "[...document.querySelectorAll('{$avatars}')].filter((image) => image.complete && image.naturalWidth > 0).length === 3");
    $page->assertScript("[...document.querySelectorAll('{$avatars}')].every((image) => image.getAttribute('src') === ".json_encode(Storage::url('inbox/ana.gif')).')', true)
        ->assertNoJavaScriptErrors();
});

test('a message that is only a link reads as a shared link in the conversation list', function () {
    $preview = "[data-testid=\"inbox-dm-card-{$this->dm->id}\"] [data-testid=\"inbox-dm-card-preview\"]";
    storeDirectContent($this->account, 'm-link', 5, 'https://www.instagram.com/p/DeIGhGDSnmz/?stkn=abc');

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForText($page, $preview, __('inbox.direct.shared_link'));
    $page->assertSeeIn($preview, __('inbox.direct.shared_link'))->assertDontSeeIn($preview, 'https://');

    storeDirectContent($this->account, 'm-own-link', 1, ' https://trypost.it/blog ', direction: MessageDirection::Outbound);
    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForText($page, $preview, __('inbox.direct.you', ['text' => __('inbox.direct.shared_link')]));
    $page->assertDontSeeIn($preview, 'https://')->assertNoJavaScriptErrors();
});

test('a photo still being copied shows as loading, never as an expired message', function () {
    $photo = storeDirectContent($this->account, 'm-photo-pending', 5, null,
        media: [['type' => 'image']],
        meta: [InboxMediaSlots::PENDING => ['media:0:path' => 'https://lookaside.fbsbx.com/ig_messaging_cdn/?asset_id=1']]);
    $bubble = "[data-message-id=\"{$photo->id}\"]";

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, "{$bubble} [data-testid=\"inbox-dm-media-pending\"]", inViewport());
    $page->assertMissing("{$bubble} [data-testid=\"inbox-dm-unavailable\"]")->assertNoJavaScriptErrors();
});

test('a shared post that came without its link opens its picture in the lightbox', function () {
    $share = storeDirectContent($this->account, 'm-share-no-link', 5, null, meta: [
        'content' => ['type' => 'shared_post', 'title' => 'We are ❤️', 'thumbnail_path' => storeServedInboxImage('inbox/shared-no-link.gif'), 'thumbnail_type' => 'image'],
    ]);
    $card = "[data-message-id=\"{$share->id}\"] [data-testid=\"inbox-dm-shared-post\"]";

    $page = visit(route('app.inbox.index', ['tab' => 'messages']));

    waitForState($page, "{$card} [data-testid=\"inbox-dm-shared-post-thumbnail\"] img", 'element.complete && element.naturalWidth > 0');
    $page->assertMissing("{$card} [data-testid=\"inbox-dm-shared-post-link\"]")->click("{$card} [data-testid=\"inbox-dm-shared-post-thumbnail\"]");
    waitForState($page, '@media-lightbox-image', "element.getAttribute('src') === ".json_encode(Storage::url('inbox/shared-no-link.gif')));
    $page->assertSeeIn($card, 'We are ❤️')->assertNoJavaScriptErrors();
});

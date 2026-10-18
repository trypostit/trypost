<?php

declare(strict_types=1);

use App\Actions\Inbox\RecountInboxConversation;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Jobs\Inbox\SendInboxReply;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
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

test('the resolve button in the card footer resolves the comment and undoes it', function (int $width) {
    $page = visit(route('app.inbox.index'))->resize($width, 900);

    waitForState($page, '@inbox-resolve', inViewport());
    $page->assertSeeIn('@inbox-resolve', __('inbox.comment.resolve'))->click('@inbox-resolve');
    waitForState($page, '@inbox-resolve', "element.dataset.resolved === 'true' && !element.disabled");
    waitForState($page, '@inbox-resolve', inViewport());
    $page->assertSeeIn('@inbox-resolve', __('inbox.comment.resolved'));
    expect($this->comment->fresh()->resolved_at)->not->toBeNull();

    $page->click('@inbox-resolve');
    waitForState($page, '@inbox-resolve', "element.dataset.resolved === 'false' && !element.disabled");
    $page->assertSeeIn('@inbox-resolve', __('inbox.comment.resolve'))->assertNoJavaScriptErrors();
    expect($this->comment->fresh()->resolved_at)->toBeNull();
})->with(['desktop' => 1280, 'mobile' => 390]);

test('the card footer keeps its controls inside the card on a phone', function () {
    $page = visit(route('app.inbox.index'))->resize(390, 900);

    waitForState($page, '@inbox-comment-footer', inViewport());
    waitForLayout($page, '@inbox-comment-card', '@inbox-comment-footer', 'b.left >= a.left && b.right <= a.right && b.bottom <= a.bottom');
    waitForLayout($page, '@inbox-comment-card', '@inbox-comment-menu', 'b.right <= a.right');
    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)->assertNoJavaScriptErrors();
});

test('the reply pill sends on Enter, grows with the text and shows the counter only near the limit', function (int $width) {
    $page = visit(route('app.inbox.index'))->resize($width, 900);

    waitForState($page, '@inbox-reply-input', "element.value === '@fan.bsky.social '");
    waitForState($page, '@inbox-reply-composer', inViewport());
    $page->assertMissing('@inbox-reply-counter');

    $height = $page->script("document.querySelector('[data-testid=\"inbox-reply-input\"]').getBoundingClientRect().height");
    $page->fill('@inbox-reply-input', str_repeat('A long reply line. ', 12));
    waitForState($page, '@inbox-reply-input', "element.getBoundingClientRect().height > {$height}");
    $page->assertMissing('@inbox-reply-counter');

    $page->fill('@inbox-reply-input', str_repeat('a', 290));
    waitForState($page, '@inbox-reply-counter', inViewport());
    $page->assertSeeIn('@inbox-reply-counter', '290/300');

    $page->fill('@inbox-reply-input', '@fan.bsky.social thanks!');
    waitForGone($page, '@inbox-reply-counter');
    $page->keys('@inbox-reply-input', 'Enter');

    waitForState($page, '@inbox-outbound-message', "element.dataset.status === 'sending'");
    expect(InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->value('body'))->toBe('@fan.bsky.social thanks!');
    Queue::assertPushed(SendInboxReply::class);
    $page->assertNoJavaScriptErrors();
})->with(['desktop' => 1280, 'mobile' => 390]);

test('the emoji button inside the reply pill opens the picker', function (int $width) {
    $page = visit(route('app.inbox.index'))->resize($width, 900);

    waitForState($page, '@inbox-reply-emoji', inViewport());
    $page->click('@inbox-reply-emoji');
    waitForState($page, '[role="dialog"]', inViewport());
    $page->assertNoJavaScriptErrors();
})->with(['desktop' => 1280, 'mobile' => 390]);

test('try again in the card menu sends the failed reply again', function () {
    $failed = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create([
        'parent_id' => $this->comment->id,
        'root_id' => $this->comment->id,
        'delivery_status' => DeliveryStatus::Failed,
        'delivery_error' => 'reply_failed',
    ]);

    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-comment-menu', inViewport());
    $page->click('@inbox-comment-menu');
    waitForState($page, '@inbox-comment-retry', inViewport());
    $page->click('@inbox-comment-retry');
    waitForState($page, '@inbox-outbound-message', "element.dataset.status === 'sending'");
    $page->assertNoJavaScriptErrors();

    expect($failed->fresh()->delivery_status)->toBe(DeliveryStatus::Sending);
});

test('a post the inbox cannot link shows where it was published and opens on the network', function (int $width) {
    $page = visit(route('app.inbox.index'))->resize($width, 900);

    waitForState($page, '@inbox-subject-footer', inViewport());
    $page->assertAttribute('@inbox-subject-card', 'data-linked', 'false')
        ->assertSeeIn('@inbox-subject-published-via', __('posts.publish.published_via'))
        ->assertMissing('@inbox-subject-created-by')
        ->assertMissing('@inbox-subject-details')
        ->assertAttribute('@inbox-subject-link', 'href', $this->conversation->subject_url)
        ->assertAttribute('@inbox-subject-link', 'target', '_blank');
    waitForState($page, '@inbox-subject-link', inViewport());
    $page->assertNoJavaScriptErrors();
})->with(['desktop' => 1280, 'mobile' => 390]);

test('a post published through TryPost names its author, shows its media and opens its details', function (int $width) {
    $author = User::factory()->create(['name' => 'Paula Writer']);
    $post = Post::factory()->forAccount($this->conversation->socialAccount)->published()->create([
        'user_id' => $author->id,
        'content' => 'My launch post',
        'platform_post_id' => 'launch-1',
        'media' => [['id' => 'm1', 'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'type' => 'image', 'mime_type' => 'image/png']],
    ]);
    $this->conversation->forceFill(['post_id' => $post->id])->save();

    $page = visit(route('app.inbox.index'))->resize($width, 900);

    waitForState($page, '@inbox-subject-created-by', inViewport());
    $page->assertAttribute('@inbox-subject-card', 'data-linked', 'true')
        ->assertSeeIn('@inbox-subject-created-by', 'Paula Writer')
        ->assertVisible('@inbox-subject-post-media')
        ->assertMissing('@inbox-subject-link');

    waitForState($page, '@inbox-subject-details', inViewport());
    $page->click('@inbox-subject-details');
    waitForState($page, "@post-details-actions-{$post->id}", inViewport());
    $page->assertNoJavaScriptErrors();
})->with(['desktop' => 1280, 'mobile' => 390]);

test('the post media opens in the media viewer without opening the details', function () {
    $pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
    $post = Post::factory()->forAccount($this->conversation->socialAccount)->published()->create([
        'content' => 'My launch post',
        'media' => [['id' => 'm1', 'url' => $pixel, 'type' => 'image', 'mime_type' => 'image/png']],
    ]);
    $this->conversation->forceFill(['post_id' => $post->id])->save();

    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-subject-post-media', inViewport());
    $page->click('@inbox-subject-post-media');
    waitForState($page, '@media-lightbox', inViewport());
    $page->assertAttribute('@media-lightbox-image', 'src', $pixel)
        ->assertMissing("@post-details-actions-{$post->id}");

    $page->click('@media-lightbox-close');
    waitForGone($page, '@media-lightbox');
    $page->assertMissing('@media-lightbox')->assertNoJavaScriptErrors();
});

test('clicking a linked post card opens its details too', function () {
    $post = Post::factory()->forAccount($this->conversation->socialAccount)->imported()->create(['content' => 'My launch post']);
    $this->conversation->forceFill(['post_id' => $post->id])->save();

    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-subject-published-via', inViewport());
    $page->assertMissing('@inbox-subject-created-by');
    $page->hover('@inbox-subject-published-via');
    waitForText($page, '@inbox-tooltip', __('posts.publish.published_directly_from', ['network' => 'Bluesky']));
    waitForState($page, '@inbox-tooltip', inViewport());

    $page->click('@inbox-subject-open-details');
    waitForState($page, "@post-details-actions-{$post->id}", inViewport());
    $page->assertNoJavaScriptErrors();
});

test('a linked post in the list view opens its details', function (int $width) {
    $post = Post::factory()->forAccount($this->conversation->socialAccount)->published()->create(['content' => 'My launch post']);
    $this->conversation->forceFill(['post_id' => $post->id])->save();

    $page = visit(route('app.inbox.messages.index'))->resize($width, 900);

    waitForState($page, '@inbox-subject-card', inViewport());
    $page->assertAttribute('@inbox-subject-card', 'data-linked', 'true')->click('@inbox-subject-card');
    waitForState($page, "@post-details-actions-{$post->id}", inViewport());
    $page->assertNoJavaScriptErrors();
})->with(['desktop' => 1280, 'mobile' => 390]);

test('our replies say discreetly whether they were sent from TryPost or from the network', function () {
    $fromTryPost = InboxMessage::factory()->for($this->conversation, 'conversation')->create([
        'direction' => MessageDirection::Outbound,
        'origin' => 'trypost',
        'parent_id' => $this->comment->id,
        'root_id' => $this->comment->id,
        'delivery_status' => DeliveryStatus::Sent,
        'body' => 'Thanks from TryPost',
    ]);
    $fromNetwork = InboxMessage::factory()->for($this->conversation, 'conversation')->create([
        'direction' => MessageDirection::Outbound,
        'origin' => 'network',
        'parent_id' => $this->comment->id,
        'root_id' => $this->comment->id,
        'delivery_status' => DeliveryStatus::Sent,
        'body' => 'Thanks from the app',
    ]);

    $page = visit(route('app.inbox.index'));

    waitForText($page, "[data-message-id=\"{$fromTryPost->id}\"] [data-testid=\"inbox-message-origin\"]", 'via TryPost');
    waitForText($page, "[data-message-id=\"{$fromNetwork->id}\"] [data-testid=\"inbox-message-origin\"]", 'via Bluesky');
    $page->assertMissing("[data-message-id=\"{$this->comment->id}\"] [data-testid=\"inbox-message-origin\"]")
        ->assertDontSeeIn('@inbox-comment-footer', 'Comment on')
        ->assertNoJavaScriptErrors();
});

test('every comment card names the channel that received it', function () {
    $channel = $this->conversation->socialAccount;
    $channel->update(['username' => 'brand.bsky.social']);

    $page = visit(route('app.inbox.index'));

    waitForText($page, '@inbox-comment-channel', 'brand.bsky.social');
    $page->assertSeeIn('@inbox-comment-channel', 'brand.bsky.social')->assertNoJavaScriptErrors();
});

test('a comment the channel hid on the network shows the hidden icon with what it means', function () {
    $page = visit(route('app.inbox.index'));

    waitFor($page, '@inbox-message-author');
    $page->assertMissing('@inbox-message-hidden');

    $this->comment->forceFill(['hidden_at' => now()])->save();
    $page = visit(route('app.inbox.index'));

    waitForState($page, '@inbox-message-hidden', inViewport());
    $page->hover('@inbox-message-hidden');
    waitForText($page, '[role="tooltip"]', __('inbox.comment.hidden', ['network' => $this->conversation->socialAccount->platform->label()]));
    $page->assertNoJavaScriptErrors();
});

test('an instagram channel lists its posts as full cards, and a video without a still shows its first frame', function () {
    $instagram = SocialAccount::factory()->instagramFacebook()->withInboxScopes()->create(['workspace_id' => $this->conversation->workspace_id]);
    $post = Post::factory()->forAccount($instagram)->published()->create([
        'content' => 'Reel caption',
        'media' => [['id' => 'v1', 'url' => 'https://cdn.example.test/reel.mp4', 'type' => 'video', 'mime_type' => 'video/mp4']],
    ]);
    $reel = InboxConversation::factory()->create(['workspace_id' => $instagram->workspace_id, 'social_account_id' => $instagram->id, 'post_id' => $post->id]);
    InboxMessage::factory()->for($reel, 'conversation')->create(['body' => 'Nice reel']);
    RecountInboxConversation::execute($reel);
    $card = "[data-testid=\"inbox-post-card-{$reel->id}\"]";

    $page = visit(route('app.channels.inbox', $instagram));

    waitForText($page, $card, 'Reel caption');
    $page->assertVisible("{$card} [data-testid=\"inbox-post-card-video-frame\"]")
        ->assertAttribute("{$card} [data-testid=\"inbox-post-card-video-frame\"]", 'src', 'https://cdn.example.test/reel.mp4#t=0.1')
        ->assertNoJavaScriptErrors();
});

<?php

declare(strict_types=1);

use App\Enums\PostHog\TourEvent;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['trypost.tours.enabled' => true]);

    $this->user = User::factory()->create(['timezone' => 'UTC', 'locale' => Locale::DEFAULT]);
    $workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($this->user->account);
    $this->user = $this->user->fresh();
    connectAiTo($this->user);

    $this->actingAs($this->user);
});

/**
 * Whether the end of the views tour reached PostHog's queue with this outcome
 * and step.
 */
function tourTracked(User $user, TourEvent $event, int $step): bool
{
    return Queue::pushed(CaptureAccountEvent::class, fn (CaptureAccountEvent $job): bool => $job->event === $event
        && $job->distinctId === (string) $user->id
        && $job->properties['tour'] === 'publish.views'
        && $job->properties['step'] === $step
        && $job->properties['steps'] === 1)->isNotEmpty();
}

test('the views tour opens over a dark page once: "Got it" completes it on every page that has the view switch', function () {
    enablePostHog();
    Queue::fake([CaptureAccountEvent::class]);

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForOpen($page, '@tour-publish-views-views-content');
    waitFor($page, '@tour-backdrop');

    rememberElement($page, '@tour-backdrop', 'backdrop');
    waitForState($page, '@tour-publish-views-views-content', "Number(getComputedStyle(element.closest('[data-reka-popper-content-wrapper]')).zIndex) > Number(getComputedStyle(window.__remembered.backdrop).zIndex)");

    $page->assertSeeIn('@tour-publish-views-views-content', 'Switch views');
    $page->assertMissing('@tour-publish-views-views-tag');
    $page->assertMissing('@tour-publish-views-views-progress');

    $page->click('@tour-publish-views-views-next');
    waitForGone($page, '@tour-publish-views-views-content');
    waitForGone($page, '@tour-backdrop');

    expect(browserStorageItem($page, $this->user, 'tours'))->toBe(['publish.views' => ['status' => 'completed', 'step' => 1]]);
    waitForDatabase($page, fn (): bool => tourTracked($this->user, TourEvent::Completed, 1));

    $page->click('@schedule-view-calendar');
    waitForPath($page, route('app.calendar', ['view' => 'week'], absolute: false));
    waitFor($page, '@schedule-view-board');
    $page->assertMissing('@tour-publish-views-views-content');

    $page->assertNoJavaScriptErrors();
});

test('closing the tour with Escape skips it for good', function () {
    enablePostHog();
    Queue::fake([CaptureAccountEvent::class]);

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForOpen($page, '@tour-publish-views-views-content');

    $page->keys('@tour-publish-views-views-content', 'Escape');
    waitForGone($page, '@tour-backdrop');

    expect(browserStorageItem($page, $this->user, 'tours'))->toBe(['publish.views' => ['status' => 'skipped', 'step' => 1]]);
    waitForDatabase($page, fn (): bool => tourTracked($this->user, TourEvent::Skipped, 1));

    $page->navigate(route('app.posts.index'));
    waitFor($page, '@schedule-view-board');
    $page->assertMissing('@tour-publish-views-views-content');

    $page->assertNoJavaScriptErrors();
});

test('using the control the tour points at works and completes the tour', function () {
    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForOpen($page, '@tour-publish-views-views-content');

    $page->click('@schedule-view-calendar');
    waitForPath($page, route('app.calendar', ['view' => 'week'], absolute: false));
    waitForGone($page, '@tour-backdrop');

    expect(browserStorageItem($page, $this->user, 'tours'))->toBe(['publish.views' => ['status' => 'completed', 'step' => 1]]);

    $page->assertNoJavaScriptErrors();
});

test('a click elsewhere on the page leaves the tour open', function () {
    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForOpen($page, '@tour-publish-views-views-content');

    pointerDownOn($page, '@app-header');
    letPageRun($page, 500);

    $page->assertVisible('@tour-publish-views-views-content')
        ->assertVisible('@tour-backdrop');
    expect(browserStorageItem($page, $this->user, 'tours'))->toBeNull();

    $page->assertNoJavaScriptErrors();
});

test('the tour stays closed on a channel\'s Instagram grid, whose switch has a fourth view, and opens on the list of all channels', function () {
    $instagram = SocialAccount::factory()->create([
        'workspace_id' => $this->user->current_workspace_id,
        'platform' => Platform::Instagram,
    ]);

    $page = visit(route('app.channels.grid', $instagram))->resize(1280, 900);
    waitFor($page, '@schedule-view-board');
    $page->assertMissing('@tour-publish-views-views-content');

    $page->navigate(route('app.posts.index'));
    waitForOpen($page, '@tour-publish-views-views-content');

    $page->assertNoJavaScriptErrors();
});

test('the tour waits while a post opens in the composer, and opens once it closes', function () {
    $channel = SocialAccount::factory()->create([
        'workspace_id' => $this->user->current_workspace_id,
        'platform' => Platform::LinkedIn,
    ]);
    $post = Post::factory()->forAccount($channel, ContentType::LinkedInPost)->create([
        'user_id' => $this->user->id,
        'content' => 'Waiting for the composer',
    ]);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForOpen($page, '@post-composer-dialog');
    letPageRun($page, 500);
    $page->assertMissing('@tour-publish-views-views-content');

    $page->click('@composer-close');
    waitForGone($page, '@post-composer-dialog');
    waitForOpen($page, '@tour-publish-views-views-content');

    $page->assertNoJavaScriptErrors();
});

test('the tour waits while the user is asked where they found us, and opens once they answer', function () {
    config(['trypost.self_hosted' => false]);
    $this->user->forceFill(['referral_source' => null, 'created_at' => now()->subDays(2)])->save();

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitFor($page, '@referral-source-survey');
    letPageRun($page, 500);
    $page->assertMissing('@tour-publish-views-views-content');

    $page->click('@referral-source-option-google');
    $page->click('@referral-source-submit');
    waitForGone($page, '@referral-source-survey');
    waitForOpen($page, '@tour-publish-views-views-content');

    $page->assertNoJavaScriptErrors();
});

test('closing the tour in another tab closes it here at once', function () {
    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForOpen($page, '@tour-publish-views-views-content');

    setBrowserStorageItem($page, $this->user, 'tours', ['publish.views' => ['status' => 'completed', 'step' => 1]]);
    announceBrowserStorageFromAnotherTab($page, $this->user);

    waitForGone($page, '@tour-publish-views-views-content');
    waitForGone($page, '@tour-backdrop');

    $page->assertNoJavaScriptErrors();
});

test('tours stay closed when they are turned off', function () {
    config(['trypost.tours.enabled' => false]);

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitFor($page, '@schedule-view-board');
    $page->assertMissing('@tour-publish-views-views-content');

    $page->assertNoJavaScriptErrors();
});

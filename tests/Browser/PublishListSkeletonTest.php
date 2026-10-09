<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;

function waitForPublishListSkeletonCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForPublishListSkeletonTestId(mixed $page, string $testId): void
{
    waitForPublishListSkeletonCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0");
}

/**
 * Holds every Inertia partial reload that asks for `posts` until
 * `window.__releasePublishList()`.
 */
function holdPublishListReloads(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            const prototype = XMLHttpRequest.prototype;
            const { setRequestHeader, send } = prototype;
            window.__heldPublishList = [];
            prototype.setRequestHeader = function (name, value) {
                if (String(name).toLowerCase() === 'x-inertia-partial-data') this.__partialData = String(value);
                return setRequestHeader.call(this, name, value);
            };
            prototype.send = function (body) {
                if (! (this.__partialData ?? '').split(',').includes('posts')) return send.call(this, body);
                window.__heldPublishList.push(() => send.call(this, body));
            };
            window.__releasePublishList = () => window.__heldPublishList.splice(0).forEach((release) => release());
        })();
    JS);
}

function watchPublishListSkeleton(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.__publishSkeletonSeen = false;
            new MutationObserver(() => {
                if (document.querySelector('[data-testid^="publish-list-skeleton-"]')) window.__publishSkeletonSeen = true;
            }).observe(document.body, { childList: true, subtree: true });
        })();
    JS);
}

function publishListVisible(mixed $page, string $testId): bool
{
    return (bool) $page->script("document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0");
}

/**
 * @return array{0: User, 1: Workspace, 2: SocialAccount}
 */
function publishListSetup(): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $time = now()->utc()->addHours(6)->format('H:00');
    $schedule = PostingSchedule::empty();

    foreach (range(0, 6) as $day) {
        $schedule = $schedule->withTime($day, $time);
    }

    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => $schedule,
    ]);

    return [$user, $workspace, $channel];
}

function publishListPost(User $user, Workspace $workspace, SocialAccount $channel, string $tab): Post
{
    if ($tab === 'queue') {
        return CreatePosts::execute($workspace, $user, [
            'status' => 'scheduled',
            'queue' => 'next',
            'content' => 'Queued post',
            'media' => [],
            'label_ids' => [],
            'destinations' => [[
                'social_account_id' => $channel->id,
                'content_type' => ContentType::LinkedInPost->value,
                'meta' => [],
            ]],
        ])->first();
    }

    return Post::factory()->forAccount($channel, ContentType::LinkedInPost)->create([
        'user_id' => $user->id,
        ...match ($tab) {
            'sent' => ['status' => PostStatus::Published, 'publish_status' => PublishStatus::Published, 'published_at' => now()->subHour()],
            'approvals' => ['status' => PostStatus::PendingApproval, 'approval_requested_by' => $user->id, 'approval_requested_at' => now(), 'scheduled_at' => now()->addDay()],
            default => ['status' => PostStatus::Draft],
        },
    ]);
}

test('each tab shows the skeleton, never the empty state, until its cards arrive', function () {
    [$user, $workspace, $channel] = publishListSetup();
    $posts = collect(['queue', 'approvals', 'drafts', 'sent'])
        ->mapWithKeys(fn (string $tab): array => [$tab => publishListPost($user, $workspace, $channel, $tab)]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForPublishListSkeletonTestId($page, "post-card-{$posts['sent']->id}");
    holdPublishListReloads($page);

    foreach (['queue' => 'timeline', 'approvals' => 'list', 'drafts' => 'list', 'sent' => 'list'] as $tab => $variant) {
        $post = $posts[$tab];

        $page->click("@publish-tab-{$tab}");
        waitForPublishListSkeletonTestId($page, "publish-list-skeleton-{$tab}");

        expect(publishListVisible($page, "publish-list-skeleton-{$tab}"))->toBeTrue()
            ->and($page->script("document.querySelector('[data-testid=\"publish-list-skeleton-{$tab}\"]').dataset.variant"))->toBe($variant)
            ->and(publishListVisible($page, 'empty-state'))->toBeFalse()
            ->and(publishListVisible($page, "post-card-{$post->id}"))->toBeFalse()
            ->and(publishListVisible($page, 'publish-filters'))->toBeTrue()
            ->and(publishListVisible($page, "publish-tab-{$tab}"))->toBeTrue()
            ->and($page->script("document.querySelectorAll('[data-testid=\"publish-list-skeleton-metrics\"]').length > 0"))->toBe($tab === 'sent')
            ->and($page->script("document.querySelectorAll('[data-testid=\"publish-list-skeleton-slot\"]').length > 0"))->toBe($variant === 'timeline');

        $page->script('window.__releasePublishList()');
        waitForPublishListSkeletonTestId($page, "post-card-{$post->id}");

        $page->assertVisible("@post-card-{$post->id}")
            ->assertMissing("@publish-list-skeleton-{$tab}")
            ->assertMissing('@empty-state');
    }

    $page->assertNoJavaScriptErrors();
});

test('a tab without data shows its empty state and never the skeleton', function (string $scope) {
    [$user, , $channel] = publishListSetup();
    $channel->update(['posting_schedule' => PostingSchedule::empty()]);
    $url = fn (string $tab): string => $scope === 'channel'
        ? route('app.channels.publish', ['account' => $channel, 'tab' => $tab])
        : route('app.posts.index', ['tab' => $tab]);
    $this->actingAs($user);

    $page = visit($url('drafts'));
    waitForPublishListSkeletonTestId($page, 'publish-empty-new-post-drafts');
    watchPublishListSkeleton($page);

    foreach (['sent', 'queue', 'approvals', 'drafts'] as $tab) {
        $page->click("@publish-tab-{$tab}");
        waitForPublishListSkeletonTestId($page, "publish-empty-new-post-{$tab}");

        $page->assertVisible("@publish-empty-new-post-{$tab}")
            ->assertMissing("@publish-list-skeleton-{$tab}");
    }

    expect($page->script('window.__publishSkeletonSeen'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
})->with(['all', 'channel']);

test('the channel queue skeleton is a plain list while the posting times are hidden', function () {
    [$user, $workspace, $channel] = publishListSetup();
    $post = publishListPost($user, $workspace, $channel, 'queue');
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', ['account' => $channel, 'tab' => 'drafts']));
    waitForPublishListSkeletonTestId($page, 'empty-state');
    $page->script("window.localStorage.setItem('publish.showSlots', 'false')");
    $page->click('@publish-tab-sent');
    waitForPublishListSkeletonTestId($page, 'empty-state');
    holdPublishListReloads($page);
    $page->click('@publish-tab-queue');
    waitForPublishListSkeletonTestId($page, 'publish-list-skeleton-queue');

    expect($page->script("document.querySelector('[data-testid=\"publish-list-skeleton-queue\"]').dataset.variant"))->toBe('list')
        ->and(publishListVisible($page, 'empty-state'))->toBeFalse();

    $page->script('window.__releasePublishList()');
    waitForPublishListSkeletonTestId($page, "post-card-{$post->id}");
    $page->assertVisible("@post-card-{$post->id}")->assertNoJavaScriptErrors();
});

test('the deferred list keeps appending pages on scroll', function () {
    [$user, $workspace, $channel] = publishListSetup();
    $perPage = (int) config('app.pagination.default');

    foreach (range(1, $perPage + 3) as $index) {
        publishListPost($user, $workspace, $channel, 'drafts');
    }

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitForPublishListSkeletonCondition($page, "document.querySelectorAll('[data-testid^=\"post-card-\"][data-post-id]').length >= {$perPage}");

    expect($page->script("document.querySelectorAll('[data-testid^=\"post-card-\"][data-post-id]').length"))->toBe($perPage);

    $page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="posts-scroll"]');
            scroller.scrollTop = scroller.scrollHeight;
        })();
    JS);
    waitForPublishListSkeletonCondition($page, "document.querySelectorAll('[data-testid^=\"post-card-\"][data-post-id]').length > {$perPage}");

    expect($page->script("document.querySelectorAll('[data-testid^=\"post-card-\"][data-post-id]').length"))->toBe($perPage + 3);
    $page->assertMissing('@publish-list-skeleton-drafts')->assertNoJavaScriptErrors();
});

test('deleting a draft keeps the list on screen without the skeleton', function () {
    [$user, $workspace, $channel] = publishListSetup();
    $deleted = publishListPost($user, $workspace, $channel, 'drafts');
    $kept = publishListPost($user, $workspace, $channel, 'drafts');
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitForPublishListSkeletonTestId($page, "post-card-{$deleted->id}");
    watchPublishListSkeleton($page);

    $page->click("@post-card-menu-{$deleted->id}");
    waitForPublishListSkeletonTestId($page, "post-delete-{$deleted->id}");
    $page->click("@post-delete-{$deleted->id}");
    waitForPublishListSkeletonTestId($page, 'confirm-delete-action');
    $page->assertMissing('@confirm-delete-input')
        ->assertSeeIn('@confirm-delete-action', __('posts.edit.delete_modal.action'))
        ->click('@confirm-delete-action');
    waitForPublishListSkeletonCondition($page, "document.querySelector('[data-testid=\"post-card-{$deleted->id}\"]') === null");

    expect($page->script('window.__publishSkeletonSeen'))->toBeFalse()
        ->and(Post::query()->whereKey($deleted->id)->exists())->toBeFalse();

    $page->assertVisible("@post-card-{$kept->id}")->assertNoJavaScriptErrors();
});

<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Illuminate\Support\Facades\Queue;

function waitForCardActionsTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForCardActionsCondition(mixed $page, string $condition): void
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

/**
 * @return list<string>
 */
function cardActionsMenuItems(mixed $page, string $key): array
{
    return $page->script(<<<JS
        Array.from(document.querySelectorAll('[data-testid="post-card-menu-content-{$key}"] [role="menuitem"], [data-testid="post-card-menu-content-{$key}"] [role="separator"]'))
            .map((element) => element.getAttribute('role') === 'separator' ? '---' : element.dataset.testid.replace('-{$key}', ''))
    JS);
}

function openCardActionsMenu(mixed $page, string $key): void
{
    waitForCardActionsTestId($page, "post-card-menu-{$key}");
    $page->click("@post-card-menu-{$key}");
    waitForCardActionsTestId($page, "post-card-menu-content-{$key}");
}

/**
 * @return array{0: User, 1: Workspace, 2: SocialAccount}
 */
function cardActionsSetup(): array
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

/**
 * @param  array<string, mixed>  $overrides
 */
function cardActionsPost(User $user, Workspace $workspace, SocialAccount $channel, array $overrides = []): Post
{
    return CreatePosts::execute($workspace, $user, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Card actions post',
        'media' => [],
        'label_ids' => [],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
        ...$overrides,
    ])->first();
}

test('a queued card shows publish now, edit and the menu in order', function () {
    [$user, $workspace, $channel] = cardActionsSetup();
    $queued = cardActionsPost($user, $workspace, $channel);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForCardActionsTestId($page, "post-publish-now-{$queued->id}");

    $footer = $page->script(<<<JS
        Array.from(document.querySelector('[data-testid="post-publish-now-{$queued->id}"]').parentElement.children)
            .map((element) => element.dataset.testid)
    JS);

    expect($footer)->toBe([
        "post-publish-now-{$queued->id}",
        "post-edit-{$queued->id}",
        "post-card-menu-{$queued->id}",
    ])
        ->and($page->script("!!document.querySelector('[data-testid=\"post-publish-now-{$queued->id}\"] svg')"))->toBeTrue()
        ->and($page->script(<<<JS
            ['post-publish-now', 'post-edit', 'post-card-menu']
                .every((id) => document.querySelector(`[data-testid="\${id}-{$queued->id}"]`).classList.contains('border-border-strong'))
        JS))->toBeTrue()
        ->and($page->script(<<<JS
            ['post-edit', 'post-card-menu'].every((id) => {
                const box = document.querySelector(`[data-testid="\${id}-{$queued->id}"]`).getBoundingClientRect();
                return Math.round(box.width) === Math.round(box.height);
            })
        JS))->toBeTrue();

    $page->assertSeeIn("@post-publish-now-{$queued->id}", __('posts.publish.actions.publish_now'));

    openCardActionsMenu($page, $queued->id);

    expect(cardActionsMenuItems($page, $queued->id))->toBe([
        'post-move-drafts',
        'post-duplicate',
        'post-recurrence-open',
        'post-details-open',
        '---',
        'post-move-top',
        'post-move-up',
        'post-move-down',
        '---',
        'post-delete',
    ]);

    $page->assertSeeIn("@post-move-drafts-{$queued->id}", __('posts.publish.actions.move_to_drafts'))
        ->assertSeeIn("@post-duplicate-{$queued->id}", __('posts.publish.actions.duplicate'))
        ->assertSeeIn("@post-details-open-{$queued->id}", __('posts.show.title'))
        ->assertSeeIn("@post-delete-{$queued->id}", __('posts.publish.actions.delete'))
        ->assertMissing("@post-copy-id-{$queued->id}")
        ->assertSeeIn("@post-recurrence-open-{$queued->id}", __('posts.recurrence.make'))
        ->assertNoJavaScriptErrors();
});

test('publish now on a queued card sends the post', function () {
    Queue::fake([PublishPost::class]);
    [$user, $workspace, $channel] = cardActionsSetup();
    $queued = cardActionsPost($user, $workspace, $channel);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForCardActionsTestId($page, "post-publish-now-{$queued->id}");
    $page->click("@post-publish-now-{$queued->id}");

    for ($attempt = 0; $attempt < 50 && $queued->refresh()->status !== PostStatus::Publishing; $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }

    expect($queued->refresh()->status)->toBe(PostStatus::Publishing);
    $page->assertNoJavaScriptErrors();
});

test('move to drafts takes the card out of the queue and updates the tab counts', function () {
    [$user, $workspace, $channel] = cardActionsSetup();
    $queued = cardActionsPost($user, $workspace, $channel);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForCardActionsTestId($page, 'publish-tab-count-queue');
    $page->assertSeeIn('@publish-tab-count-queue', '1');

    openCardActionsMenu($page, $queued->id);
    $page->click("@post-move-drafts-{$queued->id}");
    waitForCardActionsCondition($page, "!document.querySelector('[data-testid=\"post-card-{$queued->id}\"]')");

    expect($queued->refresh()->status)->toBe(PostStatus::Draft);
    $page->assertMissing("@post-card-{$queued->id}")
        ->assertSeeIn('@publish-tab-count-drafts', '1')
        ->assertMissing('@post-schedule-toast')
        ->assertNoJavaScriptErrors();
    expect($page->script("document.querySelector('[data-testid=\"publish-tab-count-queue\"]')?.textContent.trim() ?? '0'"))->toBe('0');
});

test('duplicate creates a copy and opens it in the composer', function () {
    [$user, $workspace, $channel] = cardActionsSetup();
    $queued = cardActionsPost($user, $workspace, $channel);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openCardActionsMenu($page, $queued->id);
    $page->click("@post-duplicate-{$queued->id}");
    waitForCardActionsTestId($page, 'post-composer-dialog');

    $copy = Post::query()->where('workspace_id', $workspace->id)->whereKeyNot($queued->id)->first();

    expect($copy)->not->toBeNull()
        ->and($copy->content)->toBe($queued->content)
        ->and($page->script('new URLSearchParams(location.search).get("edit")'))->toBe($copy->id);
    $page->assertVisible('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('post details opens a read-only preview of the post', function () {
    [$user, $workspace, $channel] = cardActionsSetup();
    $queued = cardActionsPost($user, $workspace, $channel);
    $target = $queued->postPlatforms()->first();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openCardActionsMenu($page, $queued->id);
    $page->click("@post-details-open-{$queued->id}");
    waitForCardActionsTestId($page, "post-details-{$queued->id}");

    $page->assertSeeIn("@post-details-{$queued->id}", __('posts.show.title'))
        ->assertSeeIn("@post-details-status-{$queued->id}", __('posts.status.scheduled'))
        ->assertSeeIn("@post-details-text-{$queued->id}", 'Card actions post')
        ->assertSeeIn("@post-details-target-{$target->id}", $channel->display_label)
        ->assertNoJavaScriptErrors();
    expect($page->script("document.querySelector('[data-testid=\"post-details-time-{$queued->id}\"]').textContent.trim()"))
        ->not->toBe(__('posts.publish.unscheduled'));
});

test('delete confirms with a plain dialog before removing the post', function () {
    [$user, $workspace, $channel] = cardActionsSetup();
    $queued = cardActionsPost($user, $workspace, $channel);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openCardActionsMenu($page, $queued->id);
    $page->click("@post-delete-{$queued->id}");
    waitForCardActionsTestId($page, 'confirm-delete-action');

    $page->assertMissing('@confirm-delete-input')
        ->assertSeeIn('@confirm-delete-action', __('posts.edit.delete_modal.action'))
        ->click('@confirm-delete-action');
    waitForCardActionsCondition($page, "!document.querySelector('[data-testid=\"post-card-{$queued->id}\"]')");

    expect(Post::query()->whereKey($queued->id)->exists())->toBeFalse();
    $page->assertMissing("@post-card-{$queued->id}")
        ->assertNoJavaScriptErrors();
});

test('draft and sent menus only offer the actions valid for their status', function () {
    [$user, $workspace, $channel] = cardActionsSetup();
    $draft = cardActionsPost($user, $workspace, $channel, ['status' => 'draft', 'queue' => null]);
    $published = Post::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'published_at' => now()->subHour(),
    ]);
    PostPlatform::factory()->published()->create([
        'post_id' => $published->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
        'enabled' => true,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    openCardActionsMenu($page, $draft->id);

    expect(cardActionsMenuItems($page, $draft->id))->toBe([
        'post-publish-now',
        'post-duplicate',
        'post-details-open',
        '---',
        'post-delete',
    ]);
    $page->assertVisible("@post-add-to-queue-{$draft->id}")
        ->assertMissing("@post-copy-id-{$draft->id}")
        ->assertMissing("@post-move-drafts-{$draft->id}")
        ->assertNoJavaScriptErrors();

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    openCardActionsMenu($page, $published->id);

    expect(cardActionsMenuItems($page, $published->id))->toBe([
        'post-duplicate',
        'post-details-open',
    ]);
    $page->assertMissing("@post-publish-now-{$published->id}")
        ->assertMissing("@post-copy-id-{$published->id}")
        ->assertMissing("@post-move-drafts-{$published->id}")
        ->assertMissing("@post-edit-{$published->id}")
        ->assertNoJavaScriptErrors();
});

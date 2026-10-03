<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

function waitForDetailsGroupTestId(mixed $page, string $testId): void
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

/**
 * @return array{0: User, 1: Collection<int, Post>}
 */
function detailsGroupSetup(int $channels): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $accounts = SocialAccount::factory()->linkedin()->count($channels)->sequence(
        fn ($sequence) => ['position' => $sequence->index, 'display_name' => "Channel {$sequence->index}"],
    )->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);

    $posts = CreatePosts::execute($workspace, $user, [
        'status' => 'scheduled',
        'queue' => null,
        'scheduled_at' => CarbonImmutable::now('UTC')->addDays(2)->setTime(9, 0)->toIso8601String(),
        'content' => 'Grouped details post',
        'media' => [],
        'label_ids' => [],
        'destinations' => $accounts->map(fn (SocialAccount $account): array => [
            'social_account_id' => $account->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ])->all(),
    ]);

    return [$user, $posts];
}

function openDetailsFromCard(mixed $page, string $key): void
{
    waitForDetailsGroupTestId($page, "post-card-menu-{$key}");
    $page->click("@post-card-menu-{$key}");
    waitForDetailsGroupTestId($page, "post-details-open-{$key}");
    $page->click("@post-details-open-{$key}");
    waitForDetailsGroupTestId($page, "post-details-{$key}");
}

test('post details lists the posts created together and switches between them', function () {
    [$user, $posts] = detailsGroupSetup(3);
    [$first, $second, $draft] = $posts->all();
    $draft->update(['status' => PostStatus::Draft, 'schedule_mode' => null, 'scheduled_at' => null]);
    $second->update(['scheduled_at' => $second->scheduled_at->addHour()]);
    $secondTarget = $second->postPlatforms()->first();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openDetailsFromCard($page, $first->id);
    waitForDetailsGroupTestId($page, "post-details-rail-{$first->id}");

    $page->assertSeeIn("@post-details-rail-{$first->id}", __('posts.group.channels', ['count' => 3]));

    $rail = $page->script(<<<'JS'
        Array.from(document.querySelectorAll('[data-testid^="post-details-rail-item-"]'))
            .map((element) => element.dataset.testid.replace('post-details-rail-item-', ''))
    JS);
    expect($rail)->toBe([$first->id, $second->id, $draft->id])
        ->and($page->script("document.querySelector('[data-testid=\"post-details-rail-status-{$draft->id}\"]').dataset.status"))->toBe('draft')
        ->and($page->script("document.querySelector('[data-testid=\"post-details-rail-status-{$second->id}\"]').textContent.trim()"))->toContain('10:00')
        ->and($page->script("document.querySelector('[data-testid=\"post-details-rail-item-{$first->id}\"]').getAttribute('aria-current')"))->toBe('true');

    $page->click("@post-details-rail-item-{$second->id}");
    waitForDetailsGroupTestId($page, "post-details-target-{$secondTarget->id}");

    $page->assertSeeIn("@post-details-text-{$second->id}", 'Grouped details post')
        ->assertSeeIn("@post-details-status-{$first->id}", __('posts.status.scheduled'));
    expect($page->script("document.querySelector('[data-testid=\"post-details-rail-item-{$second->id}\"]').getAttribute('aria-current')"))->toBe('true')
        ->and($page->script("document.querySelector('[data-testid=\"post-details-time-{$first->id}\"]').textContent"))->toContain('10:00');

    $page->click("@post-details-rail-item-{$draft->id}");
    waitForDetailsGroupTestId($page, "post-details-edit-{$draft->id}");
    $page->assertSeeIn("@post-details-status-{$first->id}", __('posts.status.draft'))
        ->assertMissing("@post-details-publish-now-{$draft->id}");

    $page->click("@post-details-rail-collapse-{$first->id}");
    waitForDetailsGroupTestId($page, "post-details-rail-expand-{$first->id}");
    $page->assertMissing("@post-details-rail-{$first->id}");

    $page->click("@post-details-rail-expand-{$first->id}");
    waitForDetailsGroupTestId($page, "post-details-rail-{$first->id}");
    $page->assertVisible("@post-details-rail-{$first->id}")
        ->assertNoJavaScriptErrors();
});

test('post details of a post created alone has no rail', function () {
    [$user, $posts] = detailsGroupSetup(1);
    $post = $posts->first();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openDetailsFromCard($page, $post->id);

    $page->assertSeeIn("@post-details-{$post->id}", 'Grouped details post')
        ->assertMissing("@post-details-rail-{$post->id}")
        ->assertMissing("@post-details-rail-loading-{$post->id}")
        ->assertMissing("@post-details-rail-expand-{$post->id}")
        ->assertNoJavaScriptErrors();
});

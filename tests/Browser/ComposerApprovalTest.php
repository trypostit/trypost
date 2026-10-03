<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\User\DefaultPostAction;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;

function waitForComposerApprovalTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                const sheet = document.querySelector('[data-testid="post-composer-dialog"]');
                if (sheet?.getAttribute('data-state') === 'open'
                    && sheet.getAnimations().every((animation) => animation.playState !== 'running')
                    && document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForComposerApprovalClosed(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
}

/**
 * @return array{0: Workspace, 1: SocialAccount, 2: User}
 */
function composerApprovalSetup(DefaultPostAction $defaultAction = DefaultPostAction::Next): array
{
    $owner = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id, 'account_id' => $owner->account_id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($owner->account);

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

    $requester = workspaceMember($workspace, 'approval', ['timezone' => 'UTC', 'default_post_action' => $defaultAction]);

    return [$workspace, $channel, $requester];
}

function composerApprovalSubmitText(mixed $page): string
{
    return $page->script('document.querySelector("[data-testid=composer-submit]").textContent.trim()');
}

test('a requester never sees publish now and requests approval for the queue', function () {
    [$workspace, $channel, $requester] = composerApprovalSetup();
    $this->actingAs($requester);

    $page = visit(route('app.posts.create'));
    waitForComposerApprovalTestId($page, 'composer-add-account');
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$channel->id}")
        ->fill("@composer-caption-{$channel->id}", 'Please approve me');
    waitForComposerApprovalTestId($page, 'composer-submit');

    expect(composerApprovalSubmitText($page))->toBe('Save and request approval');

    $page->assertVisible('@composer-save-draft')
        ->assertVisible('@composer-create-another')
        ->click('@composer-schedule-trigger')
        ->assertVisible('@composer-schedule-next')
        ->assertVisible('@composer-schedule-top')
        ->assertVisible('@composer-schedule-custom')
        ->assertMissing('@composer-schedule-now')
        ->click('@composer-schedule-top')
        ->click('@composer-submit');
    waitForComposerApprovalClosed($page);

    $post = Post::query()->where('workspace_id', $workspace->id)->sole();

    expect($post->status)->toBe(PostStatus::PendingApproval)
        ->and($post->approval_queue_position)->toBe(QueuePosition::Top);
    $page->assertNoJavaScriptErrors();
});

test('a requester whose default is publish now starts on the queue', function () {
    [, $channel, $requester] = composerApprovalSetup(DefaultPostAction::Now);
    $this->actingAs($requester);

    $page = visit(route('app.posts.create'));
    waitForComposerApprovalTestId($page, 'composer-add-account');
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$channel->id}")
        ->fill("@composer-caption-{$channel->id}", 'Default now');
    waitForComposerApprovalTestId($page, 'composer-submit');

    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('next');
    $page->assertNoJavaScriptErrors();
});

test('editing a pending queue request starts on its stored position', function () {
    [$workspace, $channel, $requester] = composerApprovalSetup();
    $post = CreatePosts::execute($workspace, $requester, [
        'status' => 'scheduled',
        'queue' => 'top',
        'content' => 'Pending on top',
        'media' => [],
        'label_ids' => [],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();
    $this->actingAs($requester);

    $page = visit(route('app.posts.edit', $post));
    waitForComposerApprovalTestId($page, 'composer-submit');

    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('top')
        ->and(composerApprovalSubmitText($page))->toBe('Save and request approval');
    $page->assertNoJavaScriptErrors();
});

test('owners and admins keep publish now and their default action', function (string $who) {
    [$workspace, $channel] = composerApprovalSetup();
    $user = $who === 'owner'
        ? $workspace->owner
        : workspaceMember($workspace, 'admin', ['timezone' => 'UTC']);
    $user->update(['default_post_action' => DefaultPostAction::Now]);
    $this->actingAs($user->fresh());

    $page = visit(route('app.posts.create'));
    waitForComposerApprovalTestId($page, 'composer-add-account');
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$channel->id}")
        ->fill("@composer-caption-{$channel->id}", 'Straight out');
    waitForComposerApprovalTestId($page, 'composer-submit');

    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('now')
        ->and(composerApprovalSubmitText($page))->not->toBe('Save and request approval');

    $page->click('@composer-schedule-trigger')
        ->assertVisible('@composer-schedule-now')
        ->assertVisible('@composer-schedule-next')
        ->assertNoJavaScriptErrors();
})->with(['owner', 'admin']);

test('editing a pending custom time request starts on custom with its date', function () {
    [$workspace, $channel, $requester] = composerApprovalSetup();
    $at = now()->utc()->addDays(2)->setTime(15, 0);
    $post = CreatePosts::execute($workspace, $requester, [
        'status' => 'scheduled',
        'scheduled_at' => $at->toIso8601String(),
        'content' => 'Pending at a set time',
        'media' => [],
        'label_ids' => [],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();
    $this->actingAs($requester);

    expect($post->status)->toBe(PostStatus::PendingApproval);

    $page = visit(route('app.posts.edit', $post));
    waitForComposerApprovalTestId($page, 'composer-submit');

    $day = $at->year === now()->year ? $at->format('M j') : $at->format('M j, Y');

    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('custom')
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-trigger-icon]").dataset.icon'))->toBe('pin')
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-trigger]").textContent.trim()'))->toBe("{$day}, {$at->format('g:i A')}")
        ->and(composerApprovalSubmitText($page))->toBe('Save and request approval');
    $page->assertNoJavaScriptErrors();
});

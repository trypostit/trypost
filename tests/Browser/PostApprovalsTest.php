<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

function waitForApprovalsTestId(mixed $page, string $testId): void
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

function waitForApprovalsGone(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (!document.querySelector('[data-testid="{$testId}"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * Labels of the card footer actions that wrap onto a second line or spill
 * past the card, for every card on the page.
 *
 * @return list<string>
 */
function approvalsFooterProblems(mixed $page): array
{
    return $page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid^="post-actions-"]')].flatMap((actions) => {
            const card = actions.closest('[data-testid^="post-card-"]').getBoundingClientRect();
            const problems = [];
            if (actions.getBoundingClientRect().right > card.right + 1) problems.push(`overflow: ${actions.textContent.trim()}`);
            actions.querySelectorAll('button, a').forEach((control) => {
                const walker = document.createTreeWalker(control, NodeFilter.SHOW_TEXT);
                while (walker.nextNode()) {
                    if (!walker.currentNode.textContent.trim()) continue;
                    const range = document.createRange();
                    range.selectNodeContents(walker.currentNode);
                    const tops = new Set([...range.getClientRects()].filter((rect) => rect.width > 0).map((rect) => Math.round(rect.top)));
                    if (tops.size > 1) problems.push(`wrap: ${control.textContent.trim()}`);
                }
            });
            return problems;
        })
    JS);
}

/**
 * @return array{0: User, 1: Workspace, 2: SocialAccount, 3: User}
 */
function approvalsBrowserSetup(): array
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

    return [$owner->fresh(), $workspace, $channel, workspaceMember($workspace, 'approval', ['timezone' => 'UTC'])];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function approvalsBrowserRequest(Workspace $workspace, User $author, SocialAccount $channel, array $overrides = []): Post
{
    return CreatePosts::execute($workspace, $author, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Waiting for a green light',
        'media' => [],
        'label_ids' => [],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
        ...$overrides,
    ])->sole();
}

test('an approver adds a queue request to the queue and rejects another', function () {
    [$owner, $workspace, $channel, $requester] = approvalsBrowserSetup();
    $queued = approvalsBrowserRequest($workspace, $requester, $channel);
    $timed = approvalsBrowserRequest($workspace, $requester, $channel, [
        'queue' => null,
        'scheduled_at' => now()->addDay()->startOfHour()->toIso8601String(),
    ]);
    $this->actingAs($owner);

    $page = visit(route('app.posts.index', ['tab' => 'approvals']));
    waitForApprovalsTestId($page, "post-approve-{$queued->id}");

    $page->assertSeeIn('@publish-tab-count-approvals', '2')
        ->assertVisible("@post-approval-badge-{$queued->id}")
        ->assertSeeIn("@post-approve-{$queued->id}", 'Add to queue')
        ->assertSeeIn("@post-approve-{$timed->id}", 'Schedule')
        ->click("@post-approve-{$queued->id}");
    waitForApprovalsGone($page, "post-card-{$queued->id}");

    $page->click("@post-reject-{$timed->id}");
    waitForApprovalsGone($page, "post-card-{$timed->id}");

    expect($queued->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($queued->fresh()->approved_by)->toBe($owner->id)
        ->and($timed->fresh()->status)->toBe(PostStatus::Draft);

    $page->assertSeeIn('@publish-tab-count-approvals', '0')
        ->assertNoJavaScriptErrors();
});

test('a request whose time has passed asks for a new time or publish now', function () {
    Queue::fake([PublishPost::class]);
    [$owner, $workspace, $channel, $requester] = approvalsBrowserSetup();
    $late = Post::factory()->pendingApproval()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $requester->id,
        'content' => 'Too late',
        'scheduled_at' => now()->subHour(),
    ]);
    PostPlatform::factory()->create(['post_id' => $late->id, 'social_account_id' => $channel->id, 'platform' => $channel->platform]);
    $this->actingAs($owner);

    $page = visit(route('app.posts.index', ['tab' => 'approvals']));
    waitForApprovalsTestId($page, "post-time-passed-{$late->id}");

    $page->assertSeeIn("@post-approve-{$late->id}", 'Approve')
        ->click("@post-approve-{$late->id}");
    waitForApprovalsTestId($page, "post-approve-publish-now-{$late->id}");

    $page->assertVisible("@post-approve-picker-{$late->id}")
        ->click("@post-approve-publish-now-{$late->id}");
    waitForApprovalsGone($page, "post-card-{$late->id}");

    expect($late->fresh()->status)->toBe(PostStatus::Publishing);
    $page->assertNoJavaScriptErrors();
});

test('a requester sees only their own requests and can revert them', function () {
    [, $workspace, $channel, $requester] = approvalsBrowserSetup();
    $own = approvalsBrowserRequest($workspace, $requester, $channel);
    $others = approvalsBrowserRequest($workspace, workspaceMember($workspace, 'approval'), $channel);
    $this->actingAs($requester);

    $page = visit(route('app.posts.index', ['tab' => 'approvals']));
    waitForApprovalsTestId($page, "post-revert-{$own->id}");

    $page->assertSeeIn('@publish-tab-count-approvals', '1')
        ->assertMissing("@post-card-{$others->id}")
        ->assertMissing("@post-approve-{$own->id}")
        ->assertMissing("@post-reject-{$own->id}")
        ->click("@post-revert-{$own->id}");
    waitForApprovalsGone($page, "post-card-{$own->id}");

    expect($own->fresh()->status)->toBe(PostStatus::Draft);
    $page->assertNoJavaScriptErrors();
});

test('a requester adding a draft to the queue is told it awaits approval', function () {
    [, $workspace, $channel, $requester] = approvalsBrowserSetup();
    $draft = approvalsBrowserRequest($workspace, $requester, $channel, ['status' => 'draft', 'queue' => null]);
    $this->actingAs($requester);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitForApprovalsTestId($page, "post-add-to-queue-{$draft->id}");

    $page->click("@post-add-to-queue-{$draft->id}");
    waitForApprovalsTestId($page, 'post-schedule-toast');

    $page->assertSeeIn('@post-schedule-toast', 'Approval requested')
        ->assertDontSee('Added to queue')
        ->assertNoJavaScriptErrors();
    expect($draft->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('the approval card footer does not wrap or overflow in any language', function () {
    [$owner, $workspace, $channel, $requester] = approvalsBrowserSetup();
    $queued = approvalsBrowserRequest($workspace, $requester, $channel);
    approvalsBrowserRequest($workspace, $requester, $channel, [
        'queue' => null,
        'scheduled_at' => now()->addDay()->startOfHour()->toIso8601String(),
    ]);

    $problems = [];

    foreach ([$owner, $requester] as $user) {
        foreach (Locale::cases() as $locale) {
            $user->update(['locale' => $locale]);
            $this->actingAs($user->fresh());

            $page = visit(route('app.posts.index', ['tab' => 'approvals']))->resize(1440, 900);
            waitForApprovalsTestId($page, "post-actions-{$queued->id}");

            foreach (approvalsFooterProblems($page) as $problem) {
                $problems[] = "{$user->name} {$locale->value} desktop {$problem}";
            }

            $page->resize(390, 844);
            waitForApprovalsTestId($page, "post-actions-{$queued->id}");

            foreach (approvalsFooterProblems($page) as $problem) {
                $problems[] = "{$user->name} {$locale->value} phone {$problem}";
            }
        }
    }

    expect($problems)->toBe([]);
});

test('a new time for a late request is typed in the channel zone', function () {
    [$owner, $workspace, $channel, $requester] = approvalsBrowserSetup();
    $owner->update(['timezone' => 'Asia/Tokyo', 'time_format' => TimeFormat::TwentyFourHour]);
    $channel->update(['timezone' => 'America/Sao_Paulo']);
    $late = Post::factory()->pendingApproval()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $requester->id,
        'content' => 'Too late',
        'scheduled_at' => now()->subHour(),
    ]);
    PostPlatform::factory()->create(['post_id' => $late->id, 'social_account_id' => $channel->id, 'platform' => $channel->platform]);
    $this->actingAs($owner);

    $page = visit(route('app.posts.index', ['tab' => 'approvals']));
    waitForApprovalsTestId($page, "post-approve-{$late->id}");
    $page->click("@post-approve-{$late->id}");
    waitForApprovalsTestId($page, 'composer-schedule-timezone');

    expect(trim((string) $page->script('document.querySelector("[data-testid=composer-schedule-timezone]").textContent')))->toBe('America/Sao Paulo');

    $query = '[...document.querySelectorAll("[data-testid^=composer-schedule-day-]")].find((day) => !day.hasAttribute("data-disabled") && !day.hasAttribute("data-outside-view") && !day.hasAttribute("data-zone-today"))?.dataset.testid.replace("composer-schedule-day-", "") ?? null';
    $day = $page->script($query);

    if ($day === null) {
        $page->click('@composer-schedule-calendar-next');
        $day = $page->script($query);
    }

    $page->click("@composer-schedule-day-{$day}")
        ->fill('@composer-schedule-time-input', '1000')
        ->click('@composer-schedule-done');
    waitForApprovalsGone($page, "post-card-{$late->id}");

    expect($late->fresh()->scheduled_at->toIso8601String())
        ->toBe(CarbonImmutable::parse("{$day} 10:00", 'America/Sao_Paulo')->utc()->toIso8601String());
    $page->assertNoJavaScriptErrors();
});

<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\RecurrenceFrequency;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

function waitForRecurrenceTestId(mixed $page, string $testId): void
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

function waitForRecurrenceCondition(mixed $page, string $condition): void
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

function recurrenceText(mixed $page, string $testId): string
{
    return $page->script("document.querySelector('[data-testid=\"{$testId}\"]')?.textContent.replace(/\\s+/g, ' ').trim() ?? ''");
}

/**
 * @return array{0: User, 1: Post, 2: CarbonImmutable}
 */
function recurrenceSetup(): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    $scheduledAt = CarbonImmutable::now('UTC')->addDays(3)->setTime(9, 0);

    $post = CreatePosts::execute($workspace, $user, [
        'status' => 'scheduled',
        'queue' => null,
        'scheduled_at' => $scheduledAt->toIso8601String(),
        'content' => 'Recurring card post',
        'media' => [],
        'label_ids' => [],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->first();

    return [$user, $post, $scheduledAt];
}

function openRecurrenceDialog(mixed $page, string $key): void
{
    waitForRecurrenceTestId($page, "post-card-menu-{$key}");
    $page->click("@post-card-menu-{$key}");
    waitForRecurrenceTestId($page, "post-recurrence-open-{$key}");
    $page->click("@post-recurrence-open-{$key}");
    waitForRecurrenceTestId($page, "post-recurrence-dialog-{$key}");
}

test('a scheduled post is made recurring from its card menu', function () {
    [$user, $post, $scheduledAt] = recurrenceSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForRecurrenceTestId($page, "post-card-{$post->id}");
    $page->assertMissing("@post-recurrence-banner-{$post->id}")
        ->assertMissing("@post-recurring-{$post->id}");

    openRecurrenceDialog($page, $post->id);

    $page->assertSeeIn("@post-recurrence-dialog-{$post->id}", __('posts.recurrence.title'));

    $time = $scheduledAt->format('g:i A');
    expect(recurrenceText($page, "post-recurrence-summary-{$post->id}"))
        ->toBe("This post will be shared every {$scheduledAt->format('l')} at {$time}, until {$scheduledAt->addWeek()->format('M j, Y')}.");

    $page->select("@post-recurrence-frequency-{$post->id}", 'month')
        ->fill("@post-recurrence-times-{$post->id}", '3');
    $monthly = "This post will be shared every month on the {$scheduledAt->format('jS')} at {$time}, until {$scheduledAt->addMonthsNoOverflow(3)->format('M j, Y')}.";
    waitForRecurrenceCondition($page, "document.querySelector('[data-testid=\"post-recurrence-summary-{$post->id}\"]')?.textContent.includes('month')");

    expect(recurrenceText($page, "post-recurrence-summary-{$post->id}"))->toBe($monthly);

    $page->fill("@post-recurrence-interval-{$post->id}", '2')
        ->select("@post-recurrence-frequency-{$post->id}", 'day');
    waitForRecurrenceCondition($page, "document.querySelector('[data-testid=\"post-recurrence-summary-{$post->id}\"]')?.textContent.includes('days')");

    expect(recurrenceText($page, "post-recurrence-summary-{$post->id}"))
        ->toBe("This post will be shared every 2 days at {$time}, until {$scheduledAt->addDays(6)->format('M j, Y')}.");

    $page->select("@post-recurrence-frequency-{$post->id}", 'week')
        ->fill("@post-recurrence-interval-{$post->id}", '1');
    $page->click("@post-recurrence-save-{$post->id}");
    waitForRecurrenceTestId($page, "post-recurrence-banner-{$post->id}");

    expect($post->refresh()->recurrence_frequency)->toBe(RecurrenceFrequency::Week)
        ->and($post->recurrence_interval)->toBe(1)
        ->and($post->recurrence_remaining)->toBe(3)
        ->and(recurrenceText($page, "post-recurrence-banner-{$post->id}"))
        ->toBe("This post will be shared every {$scheduledAt->format('l')} at {$time}, until {$scheduledAt->addWeeks(3)->format('M j, Y')} (3 posts left).");

    $page->assertSeeIn("@post-recurring-{$post->id}", __('posts.recurrence.marker'))
        ->assertMissing("@post-schedule-mode-{$post->id}")
        ->assertMissing("@post-recurrence-dialog-{$post->id}")
        ->assertNoJavaScriptErrors();
});

test('a recurrence is edited and stopped', function () {
    [$user, $post] = recurrenceSetup();
    $post->update(['recurrence_interval' => 1, 'recurrence_frequency' => RecurrenceFrequency::Day, 'recurrence_remaining' => 2]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForRecurrenceTestId($page, "post-recurrence-banner-{$post->id}");
    $page->assertSeeIn("@post-recurrence-banner-{$post->id}", '(2 posts left)');

    $page->click("@post-card-menu-{$post->id}");
    waitForRecurrenceTestId($page, "post-recurrence-open-{$post->id}");
    $page->assertSeeIn("@post-recurrence-open-{$post->id}", __('posts.recurrence.edit'));
    $page->click("@post-recurrence-open-{$post->id}");
    waitForRecurrenceTestId($page, "post-recurrence-dialog-{$post->id}");

    expect($page->script("document.querySelector('[data-testid=\"post-recurrence-frequency-{$post->id}\"]').value"))->toBe('day')
        ->and($page->script("document.querySelector('[data-testid=\"post-recurrence-times-{$post->id}\"]').value"))->toBe('2');

    $buttons = $page->script(<<<JS
        Array.from(document.querySelector('[data-testid="post-recurrence-stop-{$post->id}"]').parentElement.children)
            .map((element) => element.dataset.testid ?? 'cancel')
    JS);
    expect($buttons)->toBe(['cancel', "post-recurrence-stop-{$post->id}", "post-recurrence-save-{$post->id}"]);

    $page->fill("@post-recurrence-times-{$post->id}", '5');
    $page->click("@post-recurrence-save-{$post->id}");
    waitForRecurrenceCondition($page, "document.querySelector('[data-testid=\"post-recurrence-banner-{$post->id}\"]')?.textContent.includes('5 posts left')");

    expect($post->refresh()->recurrence_remaining)->toBe(5);

    openRecurrenceDialog($page, $post->id);
    $page->click("@post-recurrence-stop-{$post->id}");
    waitForRecurrenceCondition($page, "!document.querySelector('[data-testid=\"post-recurrence-banner-{$post->id}\"]')");

    expect($post->refresh()->isRecurring())->toBeFalse();
    $page->assertMissing("@post-recurrence-banner-{$post->id}")
        ->assertMissing("@post-recurring-{$post->id}")
        ->assertNoJavaScriptErrors();
});

test('the banner of a later occurrence counts from the series origin', function () {
    [$user, $post] = recurrenceSetup();
    $post->update([
        'scheduled_at' => CarbonImmutable::parse('2027-02-28 09:00', 'UTC'),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Month,
        'recurrence_remaining' => 2,
        'recurrence_origin_at' => CarbonImmutable::parse('2027-01-31 09:00', 'UTC'),
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForRecurrenceTestId($page, "post-recurrence-banner-{$post->id}");

    expect(recurrenceText($page, "post-recurrence-banner-{$post->id}"))
        ->toBe('This post will be shared every month on the 31st at 9:00 AM, until Apr 30, 2027 (2 posts left).');
    $page->assertNoJavaScriptErrors();
});

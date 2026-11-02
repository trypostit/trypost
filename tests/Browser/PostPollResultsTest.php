<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Database\Factories\AnalyticsPublicationFactory;

/**
 * @param  array<string, mixed>|null  $poll
 * @return array{0: User, 1: SocialAccount, 2: Post}
 */
function pollResultsSetup(?array $poll, bool $withMetrics = true, string $theme = 'light'): array
{
    $user = User::factory()->create(['timezone' => 'UTC', 'theme' => $theme]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    connectAiTo($user);

    $account = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account, ContentType::XPoll)->published()->create([
        'user_id' => $user->id,
        'scheduled_at' => now()->subHour(),
        'published_at' => now()->subHour(),
    ]);
    $publication = AnalyticsPublication::factory()->when(filled($poll), fn (AnalyticsPublicationFactory $factory): AnalyticsPublicationFactory => $factory->withPoll($poll))->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_id' => $post->id,
        'platform' => Platform::X,
        'network' => Platform::X->network(),
        'remote_id' => $post->platform_post_id,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'metrics' => $withMetrics ? [
            'impressions' => ['value' => 900, 'unit' => 'count', 'availability' => 'available'],
            'reactions' => ['value' => 40, 'unit' => 'count', 'availability' => 'available'],
        ] : [],
    ]);

    return [$user, $account, $post];
}

/**
 * @return array<string, mixed>
 */
function refundPoll(string $status = 'open'): array
{
    return [
        'options' => [['label' => '✅ Refund', 'votes' => 1], ['label' => '❌ No refund', 'votes' => 2]],
        'voting_status' => $status,
        'end_datetime' => now()->addHours(5)->toIso8601String(),
        'duration_minutes' => 1440,
    ];
}

test('the sent card shows each choice with its votes and percentage', function () {
    [$user, $account, $post] = pollResultsSetup(refundPoll());
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-option-0", '✅ Refund')
        ->assertSeeIn("@post-poll-{$post->id}-option-0", '33.3%')
        ->assertSeeIn("@post-poll-{$post->id}-option-0", '1 vote')
        ->assertSeeIn("@post-poll-{$post->id}-option-1", '2 votes')
        ->assertSeeIn("@post-poll-{$post->id}-option-1", '66.7%')
        ->assertSeeIn("@post-poll-{$post->id}-summary", '3 votes')
        ->assertAttribute("@post-poll-{$post->id}-option-1", 'data-leading', 'true')
        ->assertAttribute("@post-poll-{$post->id}-option-0", 'data-leading', 'false')
        ->assertNoJavaScriptErrors();
});

test('the summary says where voting stands', function (Closure $overrides, string $shown, array $hidden) {
    [$user, $account, $post] = pollResultsSetup([...refundPoll(), ...$overrides()]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-summary", $shown);

    foreach ($hidden as $text) {
        $page->assertDontSeeIn("@post-poll-{$post->id}-summary", $text);
    }

    $page->assertNoJavaScriptErrors();
})->with([
    'closed: final results' => [fn (): array => ['voting_status' => 'closed'], 'Final results', ['Voting ended']],
    'open, end ahead: when it ends' => [fn (): array => [], 'Ends', ['Voting ended', 'Final results']],
    'open, end passed: voting ended' => [fn (): array => ['end_datetime' => now()->subHours(2)->toIso8601String()], 'Voting ended', ['Final results', 'Ends']],
    'unknown state, end passed: never final' => [fn (): array => ['voting_status' => null, 'end_datetime' => now()->subHours(2)->toIso8601String()], 'Voting ended', ['Final results']],
]);

test('a post without a poll shows no results', function () {
    [$user, $account, $post] = pollResultsSetup(null);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-metrics-{$post->id}");

    $page->assertMissing("@post-poll-{$post->id}")->assertNoJavaScriptErrors();
});

test('the post details show the poll too', function () {
    [$user, $account, $post] = pollResultsSetup(refundPoll());
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-card-menu-{$post->id}");
    $page->click("@post-card-menu-{$post->id}");
    waitFor($page, "@post-details-open-{$post->id}");
    $page->click("@post-details-open-{$post->id}");
    waitFor($page, '@post-details-poll');

    $page->assertSeeIn('@post-details-poll', '66.7%')->assertNoJavaScriptErrors();
});

test('a poll with no votes shows 0% and no leader', function () {
    [$user, $account, $post] = pollResultsSetup([
        ...refundPoll(),
        'options' => [['label' => 'Yes', 'votes' => 0], ['label' => 'No', 'votes' => 0]],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-option-0", '0%')
        ->assertSeeIn("@post-poll-{$post->id}-option-1", '0%')
        ->assertSeeIn("@post-poll-{$post->id}-summary", '0 votes')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelectorAll('[data-testid^=\"post-poll-{$post->id}-option-\"][data-leading=\"true\"]').length"))->toBe(0);
});

test('tied choices are both marked as leading', function () {
    [$user, $account, $post] = pollResultsSetup([
        ...refundPoll(),
        'options' => [['label' => 'Yes', 'votes' => 2], ['label' => 'No', 'votes' => 2], ['label' => 'Maybe', 'votes' => 1]],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertAttribute("@post-poll-{$post->id}-option-0", 'data-leading', 'true')
        ->assertAttribute("@post-poll-{$post->id}-option-1", 'data-leading', 'true')
        ->assertAttribute("@post-poll-{$post->id}-option-2", 'data-leading', 'false')
        ->assertNoJavaScriptErrors();
});

test('the post details show the poll of a post without other metrics', function () {
    [$user, $account, $post] = pollResultsSetup(refundPoll(), withMetrics: false);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");
    $page->click("@post-card-menu-{$post->id}");
    waitFor($page, "@post-details-open-{$post->id}");
    $page->click("@post-details-open-{$post->id}");
    waitFor($page, '@post-details-poll');

    $page->assertSeeIn('@post-details-poll', '66.7%')
        ->assertMissing("@post-details-metrics-{$post->id}")
        ->assertNoJavaScriptErrors();
});

test('a poll without a known state or end shows only the votes', function () {
    [$user, $account, $post] = pollResultsSetup([...refundPoll(), 'voting_status' => null, 'end_datetime' => null]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    expect($page->script("document.querySelector('[data-testid=\"post-poll-{$post->id}-summary\"]').textContent.replace(/\\s+/g, ' ').trim()"))->toBe('3 votes');
    $page->assertNoJavaScriptErrors();
});

test('the leading choice stays readable in both themes', function (string $theme) {
    [$user, $account, $post] = pollResultsSetup([
        ...refundPoll(),
        'options' => [['label' => 'Yes', 'votes' => 9], ['label' => 'No', 'votes' => 1]],
    ], theme: $theme);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}-option-0");

    $contrast = $page->script(<<<JS
        (() => {
            const parse = (color) => {
                const [r, g, b, a = 1] = color.match(/[\\d.]+/g).map(Number);

                return { r, g, b, a };
            };
            const over = (top, bottom) => ({
                r: top.r * top.a + bottom.r * (1 - top.a),
                g: top.g * top.a + bottom.g * (1 - top.a),
                b: top.b * top.a + bottom.b * (1 - top.a),
                a: 1,
            });
            const luminance = ({ r, g, b }) => {
                const [lr, lg, lb] = [r, g, b].map((channel) => {
                    const value = channel / 255;

                    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
                });

                return 0.2126 * lr + 0.7152 * lg + 0.0722 * lb;
            };
            const row = document.querySelector('[data-testid="post-poll-{$post->id}-option-0"]');
            const [bar, label] = row.children;
            const background = over(parse(getComputedStyle(bar).backgroundColor), parse(getComputedStyle(row).backgroundColor));
            const [light, dark] = [luminance(parse(getComputedStyle(label).color)), luminance(background)].sort((a, b) => b - a);

            return (light + 0.05) / (dark + 0.05);
        })()
        JS);

    expect($contrast)->toBeGreaterThanOrEqual(4.5);
    expect($page->script("document.documentElement.classList.contains('dark')"))->toBe($theme === 'dark');
})->with(['light', 'dark']);

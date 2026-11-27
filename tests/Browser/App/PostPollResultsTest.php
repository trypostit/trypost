<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Database\Factories\AnalyticsPublicationFactory;
use Illuminate\Support\Str;

/**
 * A subscribed admin in a workspace of their own, and a channel of `$network` there.
 *
 * @return array{0: User, 1: SocialAccount}
 */
function pollResultsAccount(string $network, string $theme = 'light'): array
{
    $user = User::factory()->create(['timezone' => 'UTC', 'theme' => $theme]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    connectAiTo($user);

    return [$user, SocialAccount::factory()->{$network}()->create(['workspace_id' => $workspace->id])];
}

/**
 * @param  array<string, mixed>|null  $poll
 * @return array{0: User, 1: SocialAccount, 2: Post}
 */
function pollResultsSetup(?array $poll, bool $withMetrics = true, string $theme = 'light', ContentType $type = ContentType::XPoll): array
{
    [$user, $account] = pollResultsAccount($type->platform()->value, $theme);
    $post = Post::factory()->forAccount($account, $type)->published()->create([
        'user_id' => $user->id,
        'scheduled_at' => now()->subHour(),
        'published_at' => now()->subHour(),
    ]);
    $publication = AnalyticsPublication::factory()->when(filled($poll), fn (AnalyticsPublicationFactory $factory): AnalyticsPublicationFactory => $factory->withPoll($poll))->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_id' => $post->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
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

test('where voting stands is read from the instant, whatever zone the browser is in', function (string $browserZone, Closure $endsAt, string $shown, string $hidden) {
    [$user, $account, $post] = pollResultsSetup([...refundPoll(), 'end_datetime' => $endsAt()]);
    $user->update(['timezone' => 'UTC']);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']))->withTimezone($browserZone);
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-summary", $shown)
        ->assertDontSeeIn("@post-poll-{$post->id}-summary", $hidden)
        ->assertNoJavaScriptErrors();
})->with([
    'still open, browser behind the user' => ['America/Sao_Paulo', fn (): string => now()->addMinutes(30)->toIso8601String(), 'Ends', 'Voting ended'],
    'just ended, browser ahead of the user' => ['Asia/Tokyo', fn (): string => now()->subMinutes(30)->toIso8601String(), 'Voting ended', 'Ends'],
]);

test('a poll whose publication x has not read yet shows its choices with no votes and when it ends', function () {
    [$user, $account] = pollResultsAccount('x');
    $post = Post::factory()->forAccount($account, ContentType::XPoll)->published()->create([
        'user_id' => $user->id,
        'scheduled_at' => now()->subMinutes(5),
        'published_at' => now()->subMinutes(5),
        'meta' => [
            'poll' => ['options' => ['Mac', 'Linux'], 'duration_minutes' => 1440],
            'thread_replies' => [['text' => 'And at work?', 'media' => [], 'poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]]],
        ],
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_id' => $post->id,
        'platform' => Platform::X,
        'network' => Platform::X->network(),
        'remote_id' => $post->platform_post_id,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => now()->toDateString(),
        'collected_at' => now(),
    ]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-option-0", 'Mac')
        ->assertSeeIn("@post-poll-{$post->id}-option-0-votes", '0 votes')
        ->assertSeeIn("@post-poll-{$post->id}-option-1", 'Linux')
        ->assertSeeIn("@post-poll-{$post->id}-summary", 'Ends')
        ->assertSeeIn("@post-poll-{$post->id}-reply-0-option-0", 'Yes')
        ->assertSeeIn("@post-poll-{$post->id}-reply-0-summary", 'Ends')
        ->assertNoJavaScriptErrors();
});

test('a poll still in the queue shows its choices with no votes and no end yet', function () {
    [$user, $account] = pollResultsAccount('x');
    $post = Post::factory()->forAccount($account, ContentType::XPoll)->create([
        'user_id' => $user->id,
        'status' => Status::Scheduled,
        'scheduled_at' => now()->addDay(),
        'meta' => ['poll' => ['options' => ['Mac', 'Linux'], 'duration_minutes' => 1440]],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $account));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-option-0", 'Mac')
        ->assertSeeIn("@post-poll-{$post->id}-option-0-votes", '0 votes')
        ->assertSeeIn("@post-poll-{$post->id}-option-1", 'Linux')
        ->assertDontSeeIn("@post-poll-{$post->id}-summary", 'Ends')
        ->assertNoJavaScriptErrors();
});

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
    $page->assertNoJavaScriptErrors();
})->with(['light', 'dark']);

test('the polls of thread replies show under the post on the sent card and in the details, each naming its post', function () {
    [$user, $account, $post] = pollResultsSetup(refundPoll());
    $publication = AnalyticsPublication::query()->where('post_id', $post->id)->sole();
    $publication->forceFill(['provider_metadata' => [...$publication->provider_metadata, 'thread_polls' => [
        ['reply' => 1, ...refundPoll(), 'options' => [['label' => 'Tabs', 'votes' => 3], ['label' => 'Spaces', 'votes' => 1]]],
    ]]])->save();
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}-reply-1");

    $page->assertSeeIn("@post-poll-{$post->id}-reply-1-caption", __('posts.poll_results.thread_post', ['number' => '3']))
        ->assertSeeIn("@post-poll-{$post->id}-reply-1-option-0", 'Tabs')
        ->assertSeeIn("@post-poll-{$post->id}-reply-1-option-0", '75%')
        ->assertSeeIn("@post-poll-{$post->id}-option-1", '66.7%')
        ->assertMissing("@post-poll-{$post->id}-caption");

    $page->click("@post-card-menu-{$post->id}");
    waitFor($page, "@post-details-open-{$post->id}");
    $page->click("@post-details-open-{$post->id}");
    waitFor($page, '@post-details-poll-reply-1');

    $page->assertSeeIn('@post-details-poll-reply-1-caption', __('posts.poll_results.thread_post', ['number' => '3']))
        ->assertSeeIn('@post-details-poll-reply-1', '25%')
        ->assertNoJavaScriptErrors();
});

test('a mastodon or threads poll still in the queue shows its choices with no votes, like an x poll', function (string $network, ContentType $type, array $poll) {
    [$user, $account] = pollResultsAccount($network);
    $post = Post::factory()->forAccount($account, $type)->create([
        'user_id' => $user->id,
        'status' => Status::Scheduled,
        'scheduled_at' => now()->addDay(),
        'meta' => ['poll' => $poll],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $account));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-option-0", 'Mac')
        ->assertSeeIn("@post-poll-{$post->id}-option-0-votes", '0 votes')
        ->assertSeeIn("@post-poll-{$post->id}-option-1", 'Linux')
        ->assertDontSeeIn("@post-poll-{$post->id}-summary", 'Ends')
        ->assertNoJavaScriptErrors();
})->with([
    'mastodon' => ['mastodon', ContentType::MastodonPoll, ['options' => ['Mac', 'Linux'], 'duration_minutes' => 1440]],
    'threads' => ['threads', ContentType::ThreadsPoll, ['options' => ['Mac', 'Linux']]],
]);

test('a sent mastodon or threads poll shows the votes its network returned, like an x poll', function (ContentType $type) {
    [$user, $account, $post] = pollResultsSetup(refundPoll(), type: $type);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-option-0", '1 vote')
        ->assertSeeIn("@post-poll-{$post->id}-option-1", '2 votes')
        ->assertSeeIn("@post-poll-{$post->id}-summary", '3 votes')
        ->assertNoJavaScriptErrors();
})->with([
    'mastodon' => [ContentType::MastodonPoll],
    'threads' => [ContentType::ThreadsPoll],
]);

test('a sent mastodon multiple choice poll shows each share of the people who voted', function () {
    [$user, $account, $post] = pollResultsSetup([
        'options' => [['label' => 'Mac', 'votes' => 8], ['label' => 'Linux', 'votes' => 6], ['label' => 'Windows', 'votes' => 6]],
        'voting_status' => 'closed',
        'end_datetime' => now()->subHour()->toIso8601String(),
        'duration_minutes' => null,
        'multiple' => true,
        'voters' => 10,
        'totals_hidden' => false,
    ], type: ContentType::MastodonPoll);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-option-0-votes", '80%')
        ->assertSeeIn("@post-poll-{$post->id}-option-1-votes", '60%')
        ->assertSeeIn("@post-poll-{$post->id}-option-2-votes", '60%')
        ->assertSeeIn("@post-poll-{$post->id}-summary", trans_choice('posts.poll_results.voters', 10, ['count' => 10]))
        ->assertDontSeeIn("@post-poll-{$post->id}-summary", '20 votes')
        ->assertNoJavaScriptErrors();
});

test('a sent mastodon poll that hides its votes says so and shows no votes per choice', function () {
    [$user, $account, $post] = pollResultsSetup([
        'options' => [['label' => 'Mac', 'votes' => 0], ['label' => 'Linux', 'votes' => 0]],
        'voting_status' => 'open',
        'end_datetime' => now()->addHours(5)->toIso8601String(),
        'duration_minutes' => null,
        'multiple' => false,
        'voters' => null,
        'totals_hidden' => true,
    ], type: ContentType::MastodonPoll);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-summary", __('posts.poll_results.hidden'))
        ->assertSeeIn("@post-poll-{$post->id}-option-0", 'Mac')
        ->assertMissing("@post-poll-{$post->id}-option-0-votes")
        ->assertMissing("@post-poll-{$post->id}-option-1-votes")
        ->assertNoJavaScriptErrors();
});

test('a sent mastodon poll that hides its votes still says where voting stands', function (Closure $overrides, string $shown, array $hidden) {
    [$user, $account, $post] = pollResultsSetup([
        'options' => [['label' => 'Mac', 'votes' => 0], ['label' => 'Linux', 'votes' => 0]],
        'duration_minutes' => null,
        'multiple' => false,
        'voters' => null,
        'totals_hidden' => true,
        ...$overrides(),
    ], type: ContentType::MastodonPoll);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $summary = Str::squish((string) $page->text("@post-poll-{$post->id}-summary"));

    expect($summary)->toStartWith(__('posts.poll_results.hidden')." · {$shown}");

    foreach ($hidden as $text) {
        expect($summary)->not->toContain($text);
    }

    $page->assertMissing("@post-poll-{$post->id}-option-0-votes")->assertNoJavaScriptErrors();
})->with([
    'closed: final results' => [fn (): array => ['voting_status' => 'closed', 'end_datetime' => now()->subHour()->toIso8601String()], 'Final results', ['Voting ended']],
    'open, end ahead: when it ends' => [fn (): array => ['voting_status' => 'open', 'end_datetime' => now()->addHours(5)->toIso8601String()], 'Ends ', ['Final results', 'Voting ended']],
]);

test('a published threads poll threads has not read yet shows its choices with no votes and no end', function () {
    [$user, $account] = pollResultsAccount('threads');
    $post = Post::factory()->forAccount($account, ContentType::ThreadsPoll)->published()->create([
        'user_id' => $user->id,
        'scheduled_at' => now()->subMinutes(5),
        'published_at' => now()->subMinutes(5),
        'meta' => ['poll' => ['options' => ['Mac', 'Linux']]],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-option-0", 'Mac')
        ->assertSeeIn("@post-poll-{$post->id}-option-0-votes", '0 votes')
        ->assertSeeIn("@post-poll-{$post->id}-option-1", 'Linux')
        ->assertSeeIn("@post-poll-{$post->id}-summary", '0 votes')
        ->assertDontSeeIn("@post-poll-{$post->id}-summary", 'Ends')
        ->assertNoJavaScriptErrors();
});

test('a published mastodon poll that hides its votes says so before its first read', function () {
    [$user, $account] = pollResultsAccount('mastodon');
    $post = Post::factory()->forAccount($account, ContentType::MastodonPoll)->published()->create([
        'user_id' => $user->id,
        'scheduled_at' => now()->subMinutes(5),
        'published_at' => now()->subMinutes(5),
        'meta' => ['poll' => ['options' => ['Mac', 'Linux'], 'duration_minutes' => 1440, 'hide_totals' => true]],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-summary", __('posts.poll_results.hidden'))
        ->assertSeeIn("@post-poll-{$post->id}-option-0", 'Mac')
        ->assertMissing("@post-poll-{$post->id}-option-0-votes")
        ->assertNoJavaScriptErrors();
});

test('a mastodon reply poll the last read did not return shows its choices with no votes next to the one it did', function () {
    [$user, $account, $post] = pollResultsSetup(null, type: ContentType::MastodonPost);
    $post->update(['meta' => ['thread_replies' => [
        ['text' => 'Which one?', 'media' => [], 'poll' => ['options' => ['Mac', 'Linux'], 'duration_minutes' => 1440]],
        ['text' => 'And at work?', 'media' => [], 'poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 1440]],
    ]]]);
    $publication = AnalyticsPublication::query()->where('post_id', $post->id)->sole();
    $publication->forceFill(['provider_metadata' => [...($publication->provider_metadata ?? []), 'thread_polls' => [
        ['reply' => 1, ...refundPoll(), 'options' => [['label' => 'Yes', 'votes' => 3], ['label' => 'No', 'votes' => 1]]],
    ]]])->save();
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}-reply-1");
    waitFor($page, "@post-poll-{$post->id}-reply-0");

    $page->assertSeeIn("@post-poll-{$post->id}-reply-0-option-0", 'Mac')
        ->assertSeeIn("@post-poll-{$post->id}-reply-0-option-0-votes", '0 votes')
        ->assertSeeIn("@post-poll-{$post->id}-reply-1-option-0", 'Yes')
        ->assertSeeIn("@post-poll-{$post->id}-reply-1-option-0-votes", '3 votes')
        ->assertSeeIn("@post-poll-{$post->id}-reply-1-option-0-votes", '75%')
        ->assertNoJavaScriptErrors();
});

test('a multiple choice poll that counts no voters but has votes shows 0% for every choice', function () {
    [$user, $account, $post] = pollResultsSetup([
        'options' => [['label' => 'Mac', 'votes' => 2], ['label' => 'Linux', 'votes' => 1]],
        'voting_status' => 'open',
        'end_datetime' => now()->addHours(5)->toIso8601String(),
        'duration_minutes' => null,
        'multiple' => true,
        'voters' => 0,
        'totals_hidden' => false,
    ], type: ContentType::MastodonPoll);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-option-0-votes", '0%')
        ->assertSeeIn("@post-poll-{$post->id}-option-1-votes", '0%')
        ->assertSeeIn("@post-poll-{$post->id}-summary", trans_choice('posts.poll_results.voters', 0, ['count' => 0]))
        ->assertDontSeeIn("@post-poll-{$post->id}", 'NaN')
        ->assertDontSeeIn("@post-poll-{$post->id}", 'Infinity')
        ->assertNoJavaScriptErrors();
});

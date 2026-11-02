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

/**
 * @param  array<string, mixed>|null  $poll
 * @return array{0: User, 1: SocialAccount, 2: Post}
 */
function pollResultsSetup(?array $poll): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
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
    $factory = AnalyticsPublication::factory();

    if ($poll !== null) {
        $factory = $factory->withPoll($poll);
    }

    $publication = $factory->create([
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
        'metrics' => [
            'impressions' => ['value' => 900, 'unit' => 'count', 'availability' => 'available'],
            'reactions' => ['value' => 40, 'unit' => 'count', 'availability' => 'available'],
        ],
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
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelector('[data-testid=\"post-poll-{$post->id}-option-1\"]').dataset.leading"))->toBe('true')
        ->and($page->script("document.querySelector('[data-testid=\"post-poll-{$post->id}-option-0\"]').dataset.leading"))->toBe('false');
});

test('a closed poll says final results', function () {
    [$user, $account, $post] = pollResultsSetup(refundPoll('closed'));
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-summary", 'Final results')
        ->assertDontSeeIn("@post-poll-{$post->id}-summary", 'Voting ended')
        ->assertNoJavaScriptErrors();
});

test('an open poll whose end is ahead says when it ends', function () {
    [$user, $account, $post] = pollResultsSetup(refundPoll());
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-summary", 'Ends')
        ->assertDontSeeIn("@post-poll-{$post->id}-summary", 'Voting ended')
        ->assertDontSeeIn("@post-poll-{$post->id}-summary", 'Final results')
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

test('an open poll whose end has passed says voting ended, not final results', function () {
    [$user, $account, $post] = pollResultsSetup([...refundPoll(), 'end_datetime' => now()->subHours(2)->toIso8601String()]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', [$account, 'tab' => 'sent']));
    waitFor($page, "@post-poll-{$post->id}");

    $page->assertSeeIn("@post-poll-{$post->id}-summary", 'Voting ended')
        ->assertDontSeeIn("@post-poll-{$post->id}-summary", 'Final results')
        ->assertDontSeeIn("@post-poll-{$post->id}-summary", 'Ends')
        ->assertNoJavaScriptErrors();
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

    expect($page->script("document.querySelector('[data-testid=\"post-poll-{$post->id}-option-0\"]').dataset.leading"))->toBe('true')
        ->and($page->script("document.querySelector('[data-testid=\"post-poll-{$post->id}-option-1\"]').dataset.leading"))->toBe('true')
        ->and($page->script("document.querySelector('[data-testid=\"post-poll-{$post->id}-option-2\"]').dataset.leading"))->toBe('false');
});

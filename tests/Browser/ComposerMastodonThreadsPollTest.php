<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\MastodonPoll;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $attributes
 */
function seedNetworkPollPost(string $network, ContentType $contentType, array $attributes = []): Post
{
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    connectAiTo($user);

    $account = SocialAccount::factory()->{$network}()->create([
        'workspace_id' => $workspace->id,
        'username' => 'trypostit',
        'token_expires_at' => now()->addDays(20),
    ]);

    $post = Post::factory()->forAccount($account, $contentType)->create([
        'user_id' => $user->id,
        'content' => 'Tabs or spaces?',
        ...$attributes,
    ]);

    test()->actingAs($user);

    return $post;
}

function openNetworkPollComposer(Post $post, string $pollType): mixed
{
    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, "@composer-type-{$post->social_account_id}-{$pollType}");

    return $page;
}

test('a mastodon poll schedules with its length, multiple choice and hidden votes', function () {
    $post = seedNetworkPollPost('mastodon', ContentType::MastodonPost, ['status' => Status::Scheduled, 'scheduled_at' => now()->addDay()]);
    $id = $post->social_account_id;
    $page = openNetworkPollComposer($post, 'mastodon_poll');

    $page->click("@composer-type-{$id}-mastodon_poll");
    waitFor($page, '@mastodon-poll-choice-0');

    $page->assertVisible('@mastodon-poll-days')
        ->assertMissing("@composer-{$id}-media-source-menu")
        ->fill('@mastodon-poll-choice-0', 'Tabs')
        ->fill('@mastodon-poll-choice-1', Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH + 5));
    waitForText($page, '@mastodon-poll-choice-1-count', MastodonPoll::OPTION_MAX_LENGTH.' / '.MastodonPoll::OPTION_MAX_LENGTH);
    waitForText($page, '@mastodon-preview-poll', 'Tabs');

    $page->click('@mastodon-poll-multiple')
        ->click('@mastodon-poll-hide-totals');
    waitForState($page, '@mastodon-poll-hide-totals', "element.getAttribute('aria-checked') === 'true'");
    $page->click('@composer-submit');
    waitForGone($page, '@post-composer-dialog');

    $post->refresh();
    expect($post->content_type)->toBe(ContentType::MastodonPoll)
        ->and($post->status)->toBe(Status::Scheduled)
        ->and($post->meta['poll'])->toEqual([
            'options' => ['Tabs', Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH)],
            'duration_minutes' => MastodonPoll::DEFAULT_DURATION,
            'multiple' => true,
            'hide_totals' => true,
        ]);
    $page->assertNoJavaScriptErrors();
});

test('removing a mastodon poll returns to a mastodon post', function () {
    $post = seedNetworkPollPost('mastodon', ContentType::MastodonPoll, ['meta' => ['poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60]]]);
    $id = $post->social_account_id;
    $page = openNetworkPollComposer($post, 'mastodon_poll');
    waitFor($page, '@mastodon-poll-choice-0');

    $page->click('@mastodon-poll-remove');
    waitForGone($page, '@mastodon-poll-choice-0');
    $page->assertAttribute("@composer-type-{$id}-mastodon_post", 'aria-checked', 'true')
        ->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect($post->fresh()->content_type)->toBe(ContentType::MastodonPost)
        ->and($post->fresh()->meta)->not->toHaveKey('poll');
    $page->assertNoJavaScriptErrors();
});

test('a mastodon thread reply turns into a poll and saves it on the reply', function () {
    $post = seedNetworkPollPost('mastodon', ContentType::MastodonPost);
    $id = $post->social_account_id;
    $page = openNetworkPollComposer($post, 'mastodon_poll');

    waitFor($page, '@thread-start');
    $page->click('@thread-start');
    waitFor($page, '@thread-reply-0');
    $page->fill('@thread-reply-0', 'Which one?');
    waitFor($page, "@composer-{$id}-poll");
    $page->click("@composer-{$id}-poll");
    waitFor($page, '@thread-reply-0-poll-choice-0');

    $page->fill('@thread-reply-0-poll-choice-0', 'Yes')
        ->fill('@thread-reply-0-poll-choice-1', 'No');
    waitForText($page, '@preview-thread-reply-0 @mastodon-preview-poll', 'No');
    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect($post->fresh()->meta['thread_replies'])->toEqual([
        ['text' => 'Which one?', 'media' => [], 'poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => MastodonPoll::DEFAULT_DURATION, 'multiple' => false, 'hide_totals' => false]],
    ]);
    $page->assertNoJavaScriptErrors();
});

test('a threads poll has choices only, no length, and schedules', function () {
    $post = seedNetworkPollPost('threads', ContentType::ThreadsPost, ['status' => Status::Scheduled, 'scheduled_at' => now()->addDay()]);
    $id = $post->social_account_id;
    $page = openNetworkPollComposer($post, 'threads_poll');

    $page->click("@composer-type-{$id}-threads_poll");
    waitFor($page, '@threads-poll-choice-0');

    $page->assertMissing('@threads-poll-days')
        ->assertMissing('@threads-poll-multiple')
        ->assertMissing('@threads-poll-hide-totals')
        ->assertMissing("@composer-{$id}-media-source-menu")
        ->fill('@threads-poll-choice-0', 'Tabs')
        ->fill('@threads-poll-choice-1', 'Spaces');
    waitForText($page, '@threads-preview-poll', 'Spaces');
    $page->click('@composer-submit');
    waitForGone($page, '@post-composer-dialog');

    $post->refresh();
    expect($post->content_type)->toBe(ContentType::ThreadsPoll)
        ->and($post->status)->toBe(Status::Scheduled)
        ->and($post->meta['poll'])->toEqual(['options' => ['Tabs', 'Spaces']]);
    $page->assertNoJavaScriptErrors();
});

test('a mastodon poll stays behind the content warning in the preview', function () {
    $post = seedNetworkPollPost('mastodon', ContentType::MastodonPoll, ['meta' => ['poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60]]]);
    $page = openNetworkPollComposer($post, 'mastodon_poll');
    waitForText($page, '@mastodon-preview-poll', 'Spaces');

    $post->update(['meta' => ['poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60], 'spoiler_text' => 'Code style']]);
    $page = openNetworkPollComposer($post, 'mastodon_poll');
    waitFor($page, '@mastodon-preview-content-warning');

    $page->assertMissing('@mastodon-preview-poll')
        ->assertNoJavaScriptErrors();
});

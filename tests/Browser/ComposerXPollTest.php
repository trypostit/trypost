<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $attributes
 */
function seedXPollPost(ContentType $contentType = ContentType::XPost, array $attributes = []): Post
{
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    connectAiTo($user);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
        'username' => 'trypostit',
        'access_token' => 'x-token',
        'token_expires_at' => now()->addDays(20),
    ]);

    $post = Post::factory()->forAccount($account, $contentType)->create([
        'user_id' => $user->id,
        'content' => 'Which network brings you the most customers?',
        ...$attributes,
    ]);

    test()->actingAs($user);

    return $post;
}

function openXPollComposer(Post $post): mixed
{
    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, "@composer-type-{$post->social_account_id}-x_poll");

    return $page;
}

function pickXPoll(mixed $page, Post $post): void
{
    $page->click("@composer-type-{$post->social_account_id}-x_poll");
    waitFor($page, '@x-poll-choice-0');
}

test('picking poll shows two choices and turns media off', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $page = openXPollComposer($post);

    $page->assertVisible("@composer-{$id}-media-source-menu");
    pickXPoll($page, $post);

    $page->assertVisible('@x-poll-choice-1')
        ->assertMissing('@x-poll-choice-2')
        ->assertMissing("@composer-{$id}-dropzone")
        ->assertMissing("@composer-{$id}-media-source-menu")
        ->assertVisible("@composer-{$id}-media-source-disabled")
        ->hover("@composer-{$id}-media-source-disabled");
    waitForText($page, '[data-slot="tooltip-content"]', __('posts.form.x.poll.attachments'));
    $page->assertNoJavaScriptErrors();
});

test('adds choices up to four and removes one', function () {
    $post = seedXPollPost();
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    $page->assertMissing('@x-poll-remove-choice-0')
        ->assertMissing('@x-poll-remove-choice-1')
        ->click('@x-poll-add-choice');
    waitFor($page, '@x-poll-choice-2');
    $page->click('@x-poll-add-choice');
    waitFor($page, '@x-poll-choice-3');
    $page->assertMissing('@x-poll-add-choice')
        ->assertVisible('@x-poll-remove-choice-2')
        ->click('@x-poll-remove-choice-3');
    waitForGone($page, '@x-poll-choice-3');

    $page->assertVisible('@x-poll-choice-2')
        ->assertVisible('@x-poll-add-choice')
        ->fill('@x-poll-choice-0', 'Yes');
    waitForText($page, '@x-poll-choice-0-count', '3 / 25');
    $page->assertNoJavaScriptErrors();
});

test('a new choice takes focus after one was removed', function () {
    $post = seedXPollPost();
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    $page->click('@x-poll-add-choice');
    waitFor($page, '@x-poll-choice-2');
    $page->fill('@x-poll-choice-2', 'Maybe')
        ->click('@x-poll-add-choice');
    waitFor($page, '@x-poll-choice-3');
    $page->click('@x-poll-remove-choice-2');
    waitForGone($page, '@x-poll-choice-3');
    $page->click('@x-poll-add-choice');
    waitFor($page, '@x-poll-choice-3');

    waitForCondition($page, "document.activeElement?.dataset.testid === 'x-poll-choice-3'");
    expect($page->script('document.activeElement?.dataset.testid'))->toBe('x-poll-choice-3')
        ->and($page->script('document.activeElement.value'))->toBe('')
        ->and($page->script("document.querySelector('[data-testid=\"x-poll-choice-2\"]').value"))->toBe('');
    $page->assertNoJavaScriptErrors();
});

test('duplicate choices show the error under the choices', function () {
    $post = seedXPollPost(ContentType::XPost, ['status' => Status::Scheduled, 'scheduled_at' => now()->addDay()]);
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    $page->fill('@x-poll-choice-0', 'Yes')
        ->fill('@x-poll-choice-1', 'yes')
        ->click('@composer-submit');
    waitForText($page, '@x-poll-options-error', __('posts.form.x.poll.options_distinct'));

    $page->assertSeeIn('@x-poll-options-error', __('posts.form.x.poll.options_distinct'))
        ->assertVisible('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('seven days zeroes hours and minutes', function () {
    $post = seedXPollPost(ContentType::XPoll, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 1520]]]);
    $page = openXPollComposer($post);
    waitFor($page, '@x-poll-days');

    $page->assertSeeIn('@x-poll-days', '1')
        ->assertSeeIn('@x-poll-hours', '1')
        ->assertSeeIn('@x-poll-minutes', '20')
        ->click('@x-poll-days');
    waitFor($page, '@x-poll-days-7');
    $page->click('@x-poll-days-7');
    waitForText($page, '@x-poll-days', '7');

    $page->assertSeeIn('@x-poll-hours', '0')
        ->assertSeeIn('@x-poll-minutes', '0')
        ->assertNoJavaScriptErrors();

    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect(data_get($post->fresh()->meta, 'poll.duration_minutes'))->toBe(10080);
});

test('remove poll returns to post', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    $page->assertMissing("@composer-{$id}-dropzone")
        ->click('@x-poll-remove');
    waitForGone($page, '@x-poll-choice-0');

    $page->assertAttribute("@composer-type-{$id}-x_post", 'aria-checked', 'true')
        ->assertPresent("@composer-{$id}-dropzone")
        ->assertVisible("@composer-{$id}-media-source-menu")
        ->assertNoJavaScriptErrors();

    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect($post->fresh()->content_type)->toBe(ContentType::XPost)
        ->and($post->fresh()->meta)->toEqual([]);
});

test('poll is disabled while the card has media', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $image = Media::factory()->ownedByPost($post)->create(['mime_type' => 'image/png']);
    $post->update(['media' => [MediaItem::fromMedia($image)->toArray()]]);
    $page = openXPollComposer($post);

    expect($page->script("document.querySelector('[data-testid=\"composer-type-{$id}-x_poll\"]').disabled"))->toBeTrue();

    $page->hover("@composer-type-{$id}-x_poll-label");
    waitFor($page, "@composer-type-{$id}-x_poll-reason");

    $page->assertSeeIn("@composer-type-{$id}-x_poll-reason", __('posts.form.x.poll.attachments'))
        ->assertNoJavaScriptErrors();
});

test('the preview shows the choices and length', function () {
    $post = seedXPollPost();
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    $page->fill('@x-poll-choice-0', 'Yes')
        ->fill('@x-poll-choice-1', 'No');
    waitForText($page, '@x-preview-poll', 'No');

    $page->assertSeeIn('@x-preview-poll', 'Yes')
        ->assertSeeIn('@x-preview-poll', '1 day left')
        ->assertNoJavaScriptErrors();
});

test('schedules a poll', function () {
    $post = seedXPollPost(ContentType::XPost, ['status' => Status::Scheduled, 'scheduled_at' => now()->addDay()]);
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    $page->fill('@x-poll-choice-0', ' Yes ')
        ->fill('@x-poll-choice-1', 'No')
        ->click('@composer-submit');
    waitForGone($page, '@post-composer-dialog');

    $post->refresh();
    expect($post->content_type)->toBe(ContentType::XPoll)
        ->and($post->status)->toBe(Status::Scheduled)
        ->and($post->meta['poll'])->toEqual(['options' => ['Yes', 'No'], 'duration_minutes' => 1440]);
    $page->assertNoJavaScriptErrors();
});

test('a reopened poll draft shows two choices', function () {
    $post = seedXPollPost();
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');
    expect($post->fresh()->content_type)->toBe(ContentType::XPoll);

    $page = openXPollComposer($post);
    waitFor($page, '@x-poll-choice-0');

    $page->assertVisible('@x-poll-choice-1')
        ->assertMissing('@x-poll-choice-2')
        ->assertNoJavaScriptErrors();
});

test('editing a choice is an unsaved change', function () {
    $post = seedXPollPost(ContentType::XPoll, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 1440]]]);
    $page = openXPollComposer($post);
    waitFor($page, '@x-poll-choice-0');

    $page->click('@composer-close');
    waitForGone($page, '@post-composer-dialog');
    $page->assertMissing('@unsaved-changes-dialog');

    $page = openXPollComposer($post);
    waitFor($page, '@x-poll-choice-0');
    $page->click('@x-poll-choice-1')
        ->fill('@x-poll-choice-1', 'Nope')
        ->click('@composer-close');
    waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@unsaved-changes-dialog');

    $page->assertVisible('@unsaved-changes-dialog')->assertNoJavaScriptErrors();
});

test('picking poll then post again leaves no unsaved change', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    $page->click("@composer-type-{$id}-x_post");
    waitForGone($page, '@x-poll-choice-0');

    $page->click('@composer-close');
    waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@unsaved-changes-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('switching to post and back to poll brings the typed poll back', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    $page->fill('@x-poll-choice-0', 'Yes')
        ->fill('@x-poll-choice-1', 'No')
        ->click('@x-poll-add-choice');
    waitFor($page, '@x-poll-choice-2');
    $page->fill('@x-poll-choice-2', 'Maybe')
        ->click('@x-poll-days');
    waitFor($page, '@x-poll-days-3');
    $page->click('@x-poll-days-3');
    waitForText($page, '@x-poll-days', '3');

    $page->click("@composer-type-{$id}-x_post");
    waitForGone($page, '@x-poll-choice-0');
    pickXPoll($page, $post);
    waitFor($page, '@x-poll-choice-2');

    expect($page->script('[0, 1, 2].map((index) => document.querySelector(`[data-testid="x-poll-choice-${index}"]`).value)'))->toBe(['Yes', 'No', 'Maybe']);
    $page->assertSeeIn('@x-poll-days', '3')->assertNoJavaScriptErrors();

    $page->click("@composer-type-{$id}-x_post");
    waitForGone($page, '@x-poll-choice-0');
    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect($post->fresh()->content_type)->toBe(ContentType::XPost)
        ->and($post->fresh()->meta)->not->toHaveKey('poll');
});

test('a saved poll switched to post and back has no unsaved change', function () {
    $post = seedXPollPost(ContentType::XPoll, ['meta' => ['poll' => ['options' => ['Yes', 'No', 'Maybe'], 'duration_minutes' => 4320]]]);
    $id = $post->social_account_id;
    $page = openXPollComposer($post);
    waitFor($page, '@x-poll-choice-2');

    $page->click("@composer-type-{$id}-x_post");
    waitForGone($page, '@x-poll-choice-0');
    pickXPoll($page, $post);
    waitFor($page, '@x-poll-choice-2');

    $page->click('@composer-close');
    waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@unsaved-changes-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('a choice counter does not count the spaces around it', function () {
    $post = seedXPollPost();
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    $page->fill('@x-poll-choice-0', Str::repeat('a', 25).' ');
    waitForText($page, '@x-poll-choice-0-count', '25 / 25');

    expect($page->script("document.querySelector('[data-testid=\"x-poll-choice-0-count\"]').classList.contains('text-destructive-text')"))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('a thread reply under a poll can still take media while the poll root cannot', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    waitFor($page, '@thread-start');
    $page->click('@thread-start');
    waitFor($page, '@thread-reply-0');
    waitFor($page, "@composer-{$id}-media-source-menu");

    $page->assertMissing("@composer-{$id}-media-source-disabled")
        ->assertPresent("@composer-{$id}-reply-0-dropzone");

    $page->click("@composer-{$id}-caption-collapsed");
    waitFor($page, "@composer-{$id}-media-source-disabled");

    $page->assertMissing("@composer-{$id}-media-source-menu")
        ->assertMissing("@composer-{$id}-dropzone")
        ->assertNoJavaScriptErrors();
});

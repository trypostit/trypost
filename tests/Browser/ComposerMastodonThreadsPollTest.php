<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Social\MastodonPoll;
use Illuminate\Support\Str;

test('a mastodon poll schedules with its length, multiple choice and hidden votes', function () {
    $post = seedPollComposerPost(ContentType::MastodonPost, ['status' => Status::Scheduled, 'scheduled_at' => now()->addDay()]);
    $id = $post->social_account_id;
    $page = openPollComposer($post);

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
    $post = seedPollComposerPost(ContentType::MastodonPoll, ['meta' => ['poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60]]]);
    $id = $post->social_account_id;
    $page = openPollComposer($post);
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
    $post = seedPollComposerPost(ContentType::MastodonPost);
    $id = $post->social_account_id;
    $page = openPollComposer($post);

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
        ['text' => 'Which one?', 'media' => [], 'poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => MastodonPoll::DEFAULT_DURATION]],
    ]);
    $page->assertNoJavaScriptErrors();
});

test('a threads poll has choices only, no length, and schedules', function () {
    $post = seedPollComposerPost(ContentType::ThreadsPost, ['status' => Status::Scheduled, 'scheduled_at' => now()->addDay()]);
    $id = $post->social_account_id;
    $page = openPollComposer($post);

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
    $post = seedPollComposerPost(ContentType::MastodonPoll, ['meta' => ['poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60]]]);
    $page = openPollComposer($post);
    waitForText($page, '@mastodon-preview-poll', 'Spaces');

    $post->update(['meta' => ['poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60], 'spoiler_text' => 'Code style']]);
    $page = openPollComposer($post);
    waitFor($page, '@mastodon-preview-content-warning');

    $page->assertMissing('@mastodon-preview-poll')
        ->assertNoJavaScriptErrors();
});

test('a reopened poll closes without an unsaved change', function (ContentType $type, array $poll, string $choice) {
    $post = seedPollComposerPost($type, ['meta' => ['poll' => $poll]]);
    $page = openPollComposer($post);
    waitFor($page, "@{$choice}");

    $page->click('@composer-close');
    waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@unsaved-changes-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
})->with([
    'mastodon with multiple choice and hidden votes' => [ContentType::MastodonPoll, ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60, 'multiple' => true, 'hide_totals' => true], 'mastodon-poll-choice-1'],
    'threads' => [ContentType::ThreadsPoll, ['options' => ['Tabs', 'Spaces']], 'threads-poll-choice-1'],
]);

test('a stored poll edited back to what it was closes without an unsaved change', function (array $poll) {
    $post = seedPollComposerPost(ContentType::MastodonPoll, ['meta' => ['poll' => $poll]]);
    $page = openPollComposer($post);
    waitFor($page, '@mastodon-poll-choice-1');

    $page->click('@mastodon-poll-choice-1')
        ->fill('@mastodon-poll-choice-1', 'Spacesx');
    waitForState($page, '@mastodon-poll-choice-1', "element.value === 'Spacesx'");
    $page->fill('@mastodon-poll-choice-1', 'Spaces');
    waitForState($page, '@mastodon-poll-choice-1', "element.value === 'Spaces'");

    $page->click('@composer-close');
    waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@unsaved-changes-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
})->with([
    'saved without the switches (API or MCP)' => [['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60]],
    'keys in the order MySQL returns them' => [['options' => ['Tabs', 'Spaces'], 'multiple' => true, 'hide_totals' => false, 'duration_minutes' => 60]],
]);

test('a saved mastodon poll switched to post and back brings the poll back with no unsaved change', function () {
    $post = seedPollComposerPost(ContentType::MastodonPoll, ['meta' => ['poll' => ['options' => ['Tabs', 'Spaces', 'Both'], 'duration_minutes' => 4320, 'multiple' => true, 'hide_totals' => true]]]);
    $id = $post->social_account_id;
    $page = openPollComposer($post);
    waitFor($page, '@mastodon-poll-choice-2');

    $page->click("@composer-type-{$id}-mastodon_post");
    waitForGone($page, '@mastodon-poll-choice-0');
    $page->click("@composer-type-{$id}-mastodon_poll");
    waitFor($page, '@mastodon-poll-choice-2');

    expect(elementsMap($page, '@mastodon-poll-choice-0, @mastodon-poll-choice-1, @mastodon-poll-choice-2', 'element.value'))->toBe(['Tabs', 'Spaces', 'Both']);
    $page->assertSeeIn('@mastodon-poll-days', '3')
        ->assertAttribute('@mastodon-poll-multiple', 'aria-checked', 'true')
        ->assertAttribute('@mastodon-poll-hide-totals', 'aria-checked', 'true')
        ->click('@composer-close');
    waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@unsaved-changes-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('a saved threads poll switched to a ghost post and back brings the poll back with no unsaved change', function () {
    $post = seedPollComposerPost(ContentType::ThreadsPoll, ['meta' => ['poll' => ['options' => ['Tabs', 'Spaces', 'Both']]]]);
    $id = $post->social_account_id;
    $page = openPollComposer($post);
    waitFor($page, '@threads-poll-choice-2');

    $page->click("@composer-type-{$id}-threads_ghost_post");
    waitForGone($page, '@threads-poll-choice-0');
    $page->click("@composer-type-{$id}-threads_poll");
    waitFor($page, '@threads-poll-choice-2');

    expect(elementsMap($page, '@threads-poll-choice-0, @threads-poll-choice-1, @threads-poll-choice-2', 'element.value'))->toBe(['Tabs', 'Spaces', 'Both']);
    $page->click('@composer-close');
    waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@unsaved-changes-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('a saved draft with a poll on a mastodon thread reply closes without an unsaved change', function () {
    $post = seedPollComposerPost(ContentType::MastodonPost, ['meta' => ['thread_replies' => [
        ['text' => 'Tabs or spaces?', 'media' => [], 'poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 60, 'multiple' => true, 'hide_totals' => false]],
    ]]]);
    $page = openPollComposer($post);
    waitFor($page, '@thread-reply-collapsed-0');

    $page->click('@composer-close');
    waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@unsaved-changes-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('a poll picked while its network was the only one turns back into its post when another network joins, so shared media saves', function (ContentType $postType, ContentType $pollType) {
    $post = seedPollComposerPost($postType);
    $account = $post->socialAccount;
    $linkedIn = SocialAccount::factory()->linkedin()->create(['workspace_id' => $post->workspace_id]);
    $prefix = $postType->platform()->value;
    $png = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));

    $page = visit(route('app.posts.create'))->resize(1280, 900);
    waitForOpen($page, '@post-composer-dialog');
    $page->click('@composer-add-account');
    waitFor($page, "@composer-account-option-{$account->id}");
    $page->click("@composer-account-option-{$account->id}")
        ->click('@composer-header');
    waitForGone($page, "@composer-account-option-{$account->id}");
    waitFor($page, "@composer-type-{$account->id}-{$pollType->value}");
    $page->click("@composer-type-{$account->id}-{$pollType->value}");
    waitFor($page, "@{$prefix}-poll-choice-1");
    $page->fill("@{$prefix}-poll-choice-0", 'Tabs')
        ->fill("@{$prefix}-poll-choice-1", 'Spaces');

    $page->click('@composer-add-account');
    waitFor($page, "@composer-account-option-{$linkedIn->id}");
    $page->click("@composer-account-option-{$linkedIn->id}")
        ->click('@composer-header');
    waitForGone($page, "@composer-account-option-{$linkedIn->id}");
    waitForState($page, '@composer-file-input', 'true');
    $page->fill('@composer-base-content', 'Tabs or spaces?');
    $page->script(<<<JS
        (() => {
            const input = document.querySelector('[data-testid="composer-file-input"]');
            const transfer = new DataTransfer();
            transfer.items.add(new File([Uint8Array.from(atob('{$png}'), (character) => character.charCodeAt(0))], 'shared.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })();
    JS);
    waitFor($page, '@composer-media-item');
    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    $posts = Post::query()->where('workspace_id', $post->workspace_id)->whereKeyNot($post->id)->get()->keyBy('social_account_id');
    expect($posts)->toHaveCount(2)
        ->and($posts[$account->id]->content_type)->toBe($postType)
        ->and($posts[$account->id]->meta)->not->toHaveKey('poll')
        ->and($posts[$account->id]->media)->toHaveCount(1)
        ->and($posts[$linkedIn->id]->media)->toHaveCount(1);
    $page->assertNoJavaScriptErrors();
})->with([
    'mastodon' => [ContentType::MastodonPost, ContentType::MastodonPoll],
    'threads' => [ContentType::ThreadsPost, ContentType::ThreadsPoll],
]);

test('thirty days locks the hours and minutes of a mastodon poll', function () {
    $post = seedPollComposerPost(ContentType::MastodonPoll, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 1520]]]);
    $page = openPollComposer($post);
    waitFor($page, '@mastodon-poll-days');

    $page->assertSeeIn('@mastodon-poll-days', '1')
        ->assertSeeIn('@mastodon-poll-hours', '1')
        ->assertSeeIn('@mastodon-poll-minutes', '20')
        ->click('@mastodon-poll-days');
    waitFor($page, '@mastodon-poll-days-30');
    $page->click('@mastodon-poll-days-30');
    waitForState($page, '@mastodon-poll-hours', 'element.disabled');

    expect(elementsMap($page, '@mastodon-poll-days, @mastodon-poll-hours, @mastodon-poll-minutes', 'element.disabled'))->toBe([false, true, true]);
    $page->assertSeeIn('@mastodon-poll-hours', '0')
        ->assertSeeIn('@mastodon-poll-minutes', '0')
        ->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect(data_get($post->fresh()->meta, 'poll.duration_minutes'))->toBe(MastodonPoll::MAX_DURATION);
    $page->assertNoJavaScriptErrors();
});

test('the mastodon preview shows a multiple choice poll with its choices and length', function () {
    $post = seedPollComposerPost(ContentType::MastodonPoll, ['meta' => ['poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 43200, 'multiple' => true]]]);
    $page = openPollComposer($post);
    waitForText($page, '@mastodon-preview-poll', '30 days left');

    $page->assertSeeIn('@mastodon-preview-poll', 'Tabs')
        ->assertSeeIn('@mastodon-preview-poll', 'Spaces')
        ->assertNoJavaScriptErrors();
});

test('a mastodon thread reply with media cannot turn into a poll and says why', function () {
    $post = seedPollComposerPost(ContentType::MastodonPost);
    $id = $post->social_account_id;
    $image = Media::factory()->ownedByPost($post)->create(['mime_type' => 'image/png']);
    $post->update(['meta' => ['thread_replies' => [['text' => 'With a photo', 'media' => [MediaItem::fromMedia($image)->toArray()]]]]]);
    $page = openPollComposer($post);

    waitFor($page, '@thread-reply-collapsed-0');
    $page->click('@thread-reply-collapsed-0');
    waitFor($page, "@composer-{$id}-poll-disabled");

    $page->assertMissing("@composer-{$id}-poll")
        ->assertAttribute("@composer-{$id}-poll-disabled", 'aria-label', __('posts.form.poll.attachments'))
        ->assertNoJavaScriptErrors();
});

test('removing a mastodon thread reply poll gives the reply its media back', function () {
    $post = seedPollComposerPost(ContentType::MastodonPost);
    $id = $post->social_account_id;
    $page = openPollComposer($post);

    waitFor($page, '@thread-start');
    $page->click('@thread-start');
    waitFor($page, "@composer-{$id}-poll");
    $page->click("@composer-{$id}-poll");
    waitFor($page, '@thread-reply-0-poll-remove');
    $page->click('@thread-reply-0-poll-remove');
    waitFor($page, "@composer-{$id}-media-source-menu");

    $page->assertMissing('@thread-reply-0-poll')
        ->assertPresent("@composer-{$id}-poll")
        ->assertMissing("@composer-{$id}-media-source-disabled")
        ->assertNoJavaScriptErrors();
});

test('a server error on the poll shows on the mastodon card when mastodon is not the first channel', function () {
    $post = seedPollComposerPost(ContentType::MastodonPost);
    $mastodon = $post->socialAccount;
    $linkedIn = SocialAccount::factory()->linkedin()->create(['workspace_id' => $post->workspace_id]);

    $page = visit(route('app.posts.create'))->resize(1280, 900);
    waitForOpen($page, '@post-composer-dialog');
    $page->click('@composer-add-account');
    waitFor($page, "@composer-account-option-{$linkedIn->id}");
    $page->click("@composer-account-option-{$linkedIn->id}");
    waitFor($page, "@composer-account-option-{$mastodon->id}");
    $page->click("@composer-account-option-{$mastodon->id}");
    waitFor($page, '@composer-base-content');
    $page->fill('@composer-base-content', 'Tabs or spaces?')
        ->click('@composer-next');
    waitFor($page, "@composer-account-{$mastodon->id}");
    $page->click("@composer-account-{$mastodon->id}");
    waitFor($page, "@composer-type-{$mastodon->id}-mastodon_poll");
    $page->click("@composer-type-{$mastodon->id}-mastodon_poll");
    waitFor($page, '@mastodon-poll-choice-1');

    $page->fill('@mastodon-poll-choice-0', 'Yes')
        ->fill('@mastodon-poll-choice-1', 'yes')
        ->click('@composer-save-draft');
    waitForText($page, '@mastodon-poll-options-error', __('posts.form.poll.options_distinct'));

    $page->assertSeeIn('@mastodon-poll-options-error', __('posts.form.poll.options_distinct'))
        ->assertVisible('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
    expect(Post::query()->where('workspace_id', $post->workspace_id)->count())->toBe(1);
});

test('a mastodon poll choice the server refuses as too long is marked on that choice only', function () {
    $post = seedPollComposerPost(ContentType::MastodonPoll, ['meta' => ['poll' => ['options' => [Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH + 1), 'Spaces'], 'duration_minutes' => 60]]]);
    $page = openPollComposer($post);
    waitFor($page, '@mastodon-poll-choice-1');

    $page->click('@composer-save-draft');
    waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@mastodon-poll-choice-0-error');
    waitForText($page, '@mastodon-poll-choice-0-error', __('posts.form.poll.choice_max', ['max' => MastodonPoll::OPTION_MAX_LENGTH]));

    $page->assertAttribute('@mastodon-poll-choice-0', 'aria-invalid', 'true')
        ->assertAttributeMissing('@mastodon-poll-choice-1', 'aria-invalid')
        ->assertMissing('@mastodon-poll-choice-1-error')
        ->assertVisible('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
    expect(data_get($post->fresh()->meta, 'poll.options.0'))->toBe(Str::repeat('a', MastodonPoll::OPTION_MAX_LENGTH + 1));
});

test('a mastodon poll length the server refuses shows the range under the length', function () {
    $post = seedPollComposerPost(ContentType::MastodonPoll, ['meta' => ['poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 50000]]]);
    $page = openPollComposer($post);
    waitFor($page, '@mastodon-poll-days');

    $page->click('@composer-save-draft');
    waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@mastodon-poll-duration-error');
    waitForText($page, '@mastodon-poll-duration-error', MastodonPoll::durationRangeMessage());

    $page->assertVisible('@post-composer-dialog')->assertNoJavaScriptErrors();
    expect(data_get($post->fresh()->meta, 'poll.duration_minutes'))->toBe(50000);
});

test('a thread reply on a network without polls has no poll button', function (ContentType $type) {
    $post = seedPollComposerPost($type);
    $id = $post->social_account_id;

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, '@thread-start');
    $page->click('@thread-start');
    waitFor($page, '@thread-reply-0');
    $page->fill('@thread-reply-0', 'One more thing');
    waitFor($page, "@composer-{$id}-toolbar");

    $page->assertMissing("@composer-{$id}-poll")
        ->assertMissing("@composer-{$id}-poll-disabled")
        ->assertNoJavaScriptErrors();
})->with([
    'bluesky reply' => [ContentType::BlueskyPost],
    'linkedin comment' => [ContentType::LinkedInPost],
]);

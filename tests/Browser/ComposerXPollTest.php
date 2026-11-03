<?php

declare(strict_types=1);

use App\Ai\Agents\PostWritingAssistant;
use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\XPoll;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, mixed>  $accountMeta
 * @param  array<string, mixed>  $userAttributes
 */
function seedXPollPost(ContentType $contentType = ContentType::XPost, array $attributes = [], array $accountMeta = [], array $userAttributes = []): Post
{
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->create($userAttributes);
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
        'meta' => $accountMeta,
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
    $page->assertSeeIn('[data-slot="tooltip-content"]', __('posts.form.x.poll.attachments'))
        ->assertNoJavaScriptErrors();
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

test('a saved poll with other settings switched to post and back has no unsaved change', function () {
    $post = seedXPollPost(ContentType::XPoll, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 1440], 'is_ai_generated' => true]]);
    $id = $post->social_account_id;
    expect(array_keys($post->fresh()->meta))->toBe(['poll', 'is_ai_generated']);
    $page = openXPollComposer($post);
    waitFor($page, '@x-poll-choice-1');

    $page->click("@composer-type-{$id}-x_post");
    waitForGone($page, '@x-poll-choice-0');
    pickXPoll($page, $post);

    $page->click('@composer-close');
    waitForGoneOrShown($page, gone: '@post-composer-dialog', shown: '@unsaved-changes-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

/**
 * Opens a new post's composer on the X account of `$post` and LinkedIn, past
 * step 1, with the X card open.
 *
 * @return array{0: mixed, 1: SocialAccount, 2: SocialAccount}
 */
function openCustomizedXPollComposer(Post $post): array
{
    $x = $post->socialAccount;
    $linkedIn = SocialAccount::factory()->linkedin()->create(['workspace_id' => $post->workspace_id]);

    $page = visit(route('app.posts.create'))->resize(1280, 900);
    waitForState($page, '@post-composer-dialog', "element.getAttribute('data-state') === 'open' && element.getAnimations().every((animation) => animation.playState !== 'running')");
    $page->click('@composer-add-account');
    waitFor($page, "@composer-account-option-{$linkedIn->id}");
    $page->click("@composer-account-option-{$linkedIn->id}");
    waitFor($page, "@composer-account-option-{$x->id}");
    $page->click("@composer-account-option-{$x->id}");
    waitFor($page, '@composer-base-content');
    $page->fill('@composer-base-content', 'Tabs or spaces?')
        ->click('@composer-next');
    waitFor($page, "@composer-account-{$x->id}");
    $page->click("@composer-account-{$x->id}");
    waitFor($page, "@composer-type-{$x->id}-x_poll");

    return [$page, $x, $linkedIn];
}

test('going back to step 1 forgets the poll, so picking poll again starts empty', function () {
    $post = seedXPollPost();
    [$page, $x] = openCustomizedXPollComposer($post);
    $page->click("@composer-type-{$x->id}-x_poll");
    waitFor($page, '@x-poll-choice-1');
    $page->fill('@x-poll-choice-0', 'Tabs')
        ->fill('@x-poll-choice-1', 'Spaces')
        ->click("@composer-type-{$x->id}-x_post");
    waitForGone($page, '@x-poll');

    $page->click('@composer-back');
    waitFor($page, '@composer-back-confirm-go');
    $page->click('@composer-back-confirm-go');
    waitFor($page, '@composer-next');
    $page->click('@composer-next');
    waitFor($page, "@composer-account-{$x->id}");
    $page->click("@composer-account-{$x->id}");
    waitFor($page, "@composer-type-{$x->id}-x_poll");
    $page->click("@composer-type-{$x->id}-x_poll");
    waitFor($page, '@x-poll-choice-1');

    $page->assertValue('@x-poll-choice-0', '')
        ->assertValue('@x-poll-choice-1', '')
        ->assertNoJavaScriptErrors();
});

test('a poll picked while x was the only network turns back into a post when another network joins, so shared media saves', function () {
    $post = seedXPollPost();
    $x = $post->socialAccount;
    $linkedIn = SocialAccount::factory()->linkedin()->create(['workspace_id' => $post->workspace_id]);
    $png = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));

    $page = visit(route('app.posts.create'))->resize(1280, 900);
    waitForState($page, '@post-composer-dialog', "element.getAttribute('data-state') === 'open' && element.getAnimations().every((animation) => animation.playState !== 'running')");
    $page->click('@composer-add-account');
    waitFor($page, "@composer-account-option-{$x->id}");
    $page->click("@composer-account-option-{$x->id}")
        ->click('@composer-header');
    waitForGone($page, "@composer-account-option-{$x->id}");
    waitFor($page, "@composer-type-{$x->id}-x_poll");
    $page->click("@composer-type-{$x->id}-x_poll");
    waitFor($page, '@x-poll-choice-1');
    $page->fill('@x-poll-choice-0', 'Tabs')
        ->fill('@x-poll-choice-1', 'Spaces');

    $page->click('@composer-add-account');
    waitFor($page, "@composer-account-option-{$linkedIn->id}");
    $page->click("@composer-account-option-{$linkedIn->id}")
        ->click('@composer-header');
    waitForGone($page, "@composer-account-option-{$linkedIn->id}");
    waitFor($page, '@composer-file-input');
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
        ->and($posts[$x->id]->content_type)->toBe(ContentType::XPost)
        ->and($posts[$x->id]->meta)->not->toHaveKey('poll')
        ->and($posts[$x->id]->media)->toHaveCount(1)
        ->and($posts[$linkedIn->id]->media)->toHaveCount(1);
    $page->assertNoJavaScriptErrors();
});

test('poll is disabled while a file is still uploading to the card', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $png = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $uploadUrl = route('app.media.store-chunked', absolute: false);
    $page = openXPollComposer($post);
    waitFor($page, "@composer-{$id}-file-input");

    $page->script(<<<JS
        (() => {
            const { open, send } = XMLHttpRequest.prototype;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.__url = url;
                return open.call(this, method, url, ...rest);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (String(this.__url ?? '').includes('{$uploadUrl}')) return;
                return send.call(this, body);
            };
            const input = document.querySelector('[data-testid="composer-{$id}-file-input"]');
            const transfer = new DataTransfer();
            transfer.items.add(new File([Uint8Array.from(atob('{$png}'), (character) => character.charCodeAt(0))], 'held.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })();
    JS);
    waitFor($page, "@composer-{$id}-upload-item");

    $page->assertMissing("@composer-{$id}-media-item");
    expect($page->script("document.querySelector('[data-testid=\"composer-type-{$id}-x_poll\"]').disabled"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
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

test('in a thread the poll shows on the root and hides while a reply is edited', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    waitFor($page, '@thread-start');
    $page->click('@thread-start');
    waitForGone($page, '@x-poll');
    $page->assertMissing('@x-poll-choice-0');

    $page->click("@composer-{$id}-caption-collapsed");
    waitFor($page, '@x-poll-choice-0');
    $page->fill('@x-poll-choice-0', 'Tabs')
        ->assertValue('@x-poll-choice-0', 'Tabs')
        ->click('@thread-reply-collapsed-0');
    waitForGone($page, '@x-poll');

    $page->assertMissing('@x-poll-choice-0')->assertNoJavaScriptErrors();
});

test('a poll question on a long post account counts against 280', function () {
    $post = seedXPollPost(ContentType::XPoll, [
        'content' => Str::repeat('a', 290),
        'meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 1440]],
    ], ['x_subscription_type' => 'Premium']);
    $id = $post->social_account_id;
    $page = openXPollComposer($post);
    waitForText($page, "@composer-char-count-{$id}", '-10');

    $page->assertSeeIn("@composer-char-count-{$id}", '-10')
        ->hover('@composer-submit');
    waitForText($page, '@composer-blocked-tooltip', 'max 280');

    $page->assertSeeIn('@composer-blocked-tooltip', __('posts.form.content_exceeds_platform', ['platform' => 'X', 'over' => '10', 'limit' => '280']))
        ->assertNoJavaScriptErrors();
});

test('a server error on the poll shows on the x card when x is not the first channel', function (array $choices, string $errorTestId, string $message) {
    $post = seedXPollPost();
    $x = $post->socialAccount;
    $linkedIn = SocialAccount::factory()->linkedin()->create(['workspace_id' => $post->workspace_id]);

    $page = visit(route('app.posts.create'))->resize(1280, 900);
    waitForState($page, '@post-composer-dialog', "element.getAttribute('data-state') === 'open' && element.getAnimations().every((animation) => animation.playState !== 'running')");
    $page->click('@composer-add-account');
    waitFor($page, "@composer-account-option-{$linkedIn->id}");
    $page->click("@composer-account-option-{$linkedIn->id}");
    waitFor($page, "@composer-account-option-{$x->id}");
    $page->click("@composer-account-option-{$x->id}");
    waitFor($page, '@composer-base-content');
    $page->fill('@composer-base-content', 'Tabs or spaces?')
        ->click('@composer-next');
    waitFor($page, "@composer-account-{$x->id}");
    $page->click("@composer-account-{$x->id}");
    waitFor($page, "@composer-type-{$x->id}-x_poll");
    $page->click("@composer-type-{$x->id}-x_poll");
    waitFor($page, '@x-poll-choice-1');

    $page->fill('@x-poll-choice-0', $choices[0])
        ->fill('@x-poll-choice-1', $choices[1])
        ->click('@composer-save-draft');
    waitForText($page, "@{$errorTestId}", $message);

    $page->assertSeeIn("@{$errorTestId}", $message)
        ->assertVisible('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
    expect(Post::query()->where('workspace_id', $post->workspace_id)->count())->toBe(1);
})->with([
    'repeated choices' => fn () => [['Yes', 'yes'], 'x-poll-options-error', __('posts.form.x.poll.options_distinct')],
    'a choice over 25 characters' => fn () => [[Str::repeat('a', 26), 'No'], 'x-poll-choice-0-error', __('posts.form.x.poll.choice_max', ['max' => 25])],
]);

test('the ai-generated switch survives a switch from poll to post', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    waitFor($page, '@x-ai-generated');
    $page->click('@x-ai-generated');
    waitForState($page, '@x-ai-generated', "element.getAttribute('aria-checked') === 'true'");
    $page->click("@composer-type-{$id}-x_post");
    waitForGone($page, '@x-poll');

    $page->assertAttribute('@x-ai-generated', 'aria-checked', 'true')
        ->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect($post->fresh())
        ->content_type->toBe(ContentType::XPost)
        ->meta->toEqual(['is_ai_generated' => true]);
    $page->assertNoJavaScriptErrors();
});

test('removing the poll forgets it, so picking poll again starts empty', function () {
    $post = seedXPollPost();
    $page = openXPollComposer($post);
    pickXPoll($page, $post);

    $page->fill('@x-poll-choice-0', 'Yes')
        ->fill('@x-poll-choice-1', 'No')
        ->click('@x-poll-days');
    waitFor($page, '@x-poll-days-3');
    $page->click('@x-poll-days-3');
    waitForText($page, '@x-poll-days', '3');

    $page->click('@x-poll-remove');
    waitForGone($page, '@x-poll');
    pickXPoll($page, $post);

    $page->assertValue('@x-poll-choice-0', '')
        ->assertValue('@x-poll-choice-1', '')
        ->assertSeeIn('@x-poll-days', '1')
        ->assertSeeIn('@x-poll-hours', '0')
        ->assertSeeIn('@x-poll-minutes', '0')
        ->assertNoJavaScriptErrors();
});

test('a length of zero saves the shortest poll and seven days locks hours and minutes', function () {
    $post = seedXPollPost(ContentType::XPoll, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 1440]]]);
    $page = openXPollComposer($post);
    waitFor($page, '@x-poll-days');

    $page->click('@x-poll-days');
    waitFor($page, '@x-poll-days-0');
    $page->click('@x-poll-days-0');
    waitForText($page, '@x-poll-minutes', '5');

    $page->assertSeeIn('@x-poll-days', '0')
        ->assertSeeIn('@x-poll-hours', '0')
        ->assertSeeIn('@x-poll-minutes', '5')
        ->click('@x-poll-days');
    waitFor($page, '@x-poll-days-7');
    $page->click('@x-poll-days-7');
    waitForState($page, '@x-poll-hours', 'element.disabled');

    expect($page->script("['days', 'hours', 'minutes'].map((part) => document.querySelector(`[data-testid=\"x-poll-\${part}\"]`).disabled)"))->toBe([false, true, true]);

    $page->click('@x-poll-days');
    waitFor($page, '@x-poll-days-0');
    $page->click('@x-poll-days-0');
    waitForState($page, '@x-poll-hours', '! element.disabled');
    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect(data_get($post->fresh()->meta, 'poll.duration_minutes'))->toBe(5);
    $page->assertNoJavaScriptErrors();
});

test('on a phone the poll editor fits the screen', function () {
    $post = seedXPollPost(ContentType::XPoll, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 1440]]]);
    $page = visit(route('app.posts.edit', $post))->resize(390, 844);
    waitFor($page, '@x-poll-minutes');

    expect($page->script("(() => { const poll = document.querySelector('[data-testid=\"x-poll\"]'); return poll.scrollWidth <= poll.clientWidth; })()"))->toBeTrue()
        ->and($page->script("['days', 'hours', 'minutes'].every((part) => { const box = document.querySelector(`[data-testid=\"x-poll-\${part}\"]`).getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth; })"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the length selects are labelled and a choice is added with the keyboard', function () {
    $post = seedXPollPost(ContentType::XPoll, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 1440]]]);
    $page = openXPollComposer($post);
    waitFor($page, '@x-poll-add-choice');

    expect($page->script("['days', 'hours', 'minutes'].map((part) => document.querySelector(`[data-testid=\"x-poll-\${part}\"]`).labels[0]?.textContent.trim())"))
        ->toBe([__('posts.form.x.poll.days'), __('posts.form.x.poll.hours'), __('posts.form.x.poll.minutes')]);

    $page->keys('@x-poll-add-choice', 'Enter');
    waitFor($page, '@x-poll-choice-2');

    $page->assertVisible('@x-poll-choice-2')->assertNoJavaScriptErrors();
});

test('the writing assistant writes a poll question for 280 characters on a long post account', function () {
    config()->set('trypost.self_hosted', true);
    PostWritingAssistant::fake(['Tabs or spaces?']);
    $post = seedXPollPost(ContentType::XPoll, ['meta' => ['poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 1440]]], ['x_subscription_type' => 'Premium']);
    $page = openXPollComposer($post);

    $page->click('@composer-ai-assistant');
    waitFor($page, '@writing-assistant-channel');
    $page->assertSeeIn('@writing-assistant-channel', '280')
        ->click('@writing-assistant-mode-generate')
        ->fill('@writing-assistant-prompt', 'ask our followers about tabs or spaces')
        ->click('@writing-assistant-generate');
    waitForText($page, '@writing-assistant-suggestion', 'Tabs or spaces?');

    $page->assertSeeIn('@writing-assistant-suggestion', 'Tabs or spaces?')->assertNoJavaScriptErrors();
    PostWritingAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => Str::contains($prompt->agent->instructions(), 'Hard limit: '.XPoll::QUESTION_MAX_LENGTH.' characters'));
});

test('a link in a poll question shows no link card in the preview', function () {
    Http::fake(['https://93.184.216.34/*' => Http::response('<meta property="og:title" content="Article card">')]);
    $post = seedXPollPost(ContentType::XPost, ['content' => 'Read https://93.184.216.34/article']);
    $page = openXPollComposer($post);
    waitForText($page, '@link-card-title', 'Article card');
    $page->assertPresent('@link-card');

    pickXPoll($page, $post);
    waitForGone($page, '@link-card');

    $page->assertMissing('@link-card')
        ->assertVisible('@x-preview-poll')
        ->assertNoJavaScriptErrors();
});

test('the preview shows the poll length in its largest unit and the user language', function (int $minutes, string $locale, string $expected) {
    $post = seedXPollPost(ContentType::XPoll, ['meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => $minutes]]], userAttributes: ['locale' => $locale]);
    $page = openXPollComposer($post);
    waitForText($page, '@x-preview-poll', $expected);

    $page->assertSeeIn('@x-preview-poll', $expected)->assertNoJavaScriptErrors();
})->with([
    'hours' => [120, 'en', '2 hours left'],
    'minutes' => [30, 'en', '30 minutes left'],
    'days in portuguese' => [1440, 'pt-BR', 'termina em 1 dia'],
]);

test('a thread reply turns into a poll from its toolbar, turns its media off and saves the poll on the reply', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $page = openXPollComposer($post);

    waitFor($page, '@thread-start');
    $page->click('@thread-start');
    waitFor($page, '@thread-reply-0');
    $page->fill('@thread-reply-0', 'Tabs or spaces?');
    waitFor($page, "@composer-{$id}-poll");
    $page->click("@composer-{$id}-poll");
    waitFor($page, '@thread-reply-0-poll-choice-0');

    $page->fill('@thread-reply-0-poll-choice-0', 'Tabs')
        ->fill('@thread-reply-0-poll-choice-1', 'Spaces');
    waitFor($page, "@composer-{$id}-media-source-disabled");
    waitForText($page, '@preview-thread-reply-0 [data-testid="x-preview-poll"]', 'Spaces');

    $page->assertMissing("@composer-{$id}-poll")
        ->assertMissing('@x-poll')
        ->assertAttribute("@composer-{$id}-media-source-disabled", 'aria-label', __('posts.form.x.poll.attachments'))
        ->assertSeeIn('[data-testid="preview-thread-reply-0"] [data-testid="x-preview-poll"]', 'Tabs');

    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect($post->fresh()->meta['thread_replies'])->toEqual([
        ['text' => 'Tabs or spaces?', 'media' => [], 'poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => XPoll::DEFAULT_DURATION]],
    ])->and($post->fresh()->content_type)->toBe(ContentType::XPost);
    $page->assertNoJavaScriptErrors();
});

test('removing a thread reply poll gives the reply its media back', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $page = openXPollComposer($post);

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

test('a thread reply with media cannot turn into a poll and says why', function () {
    $post = seedXPollPost();
    $id = $post->social_account_id;
    $image = Media::factory()->ownedByPost($post)->create(['mime_type' => 'image/png']);
    $post->update(['meta' => ['thread_replies' => [['text' => 'With a photo', 'media' => [MediaItem::fromMedia($image)->toArray()]]]]]);
    $page = openXPollComposer($post);

    waitFor($page, '@thread-reply-collapsed-0');
    $page->click('@thread-reply-collapsed-0');
    waitFor($page, "@composer-{$id}-poll-disabled");

    $page->assertMissing("@composer-{$id}-poll")
        ->assertAttribute("@composer-{$id}-poll-disabled", 'aria-label', __('posts.form.x.poll.attachments'))
        ->assertNoJavaScriptErrors();
});

test('a thread reply poll question on a long post account counts against 280', function () {
    $post = seedXPollPost(attributes: ['meta' => ['thread_replies' => [
        ['text' => Str::repeat('a', 290), 'poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 1440]],
    ]]], accountMeta: ['x_subscription_type' => 'Premium']);
    $id = $post->social_account_id;
    $page = openXPollComposer($post);

    waitFor($page, '@thread-reply-collapsed-0');
    $page->click('@thread-reply-collapsed-0');
    waitForText($page, "@composer-char-count-{$id}", '-10');

    $page->assertSeeIn("@composer-char-count-{$id}", '-10')
        ->assertValue('@thread-reply-0-poll-choice-1', 'No')
        ->assertNoJavaScriptErrors();
});

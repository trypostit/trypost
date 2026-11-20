<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

function linkedInCommentPost(string $factoryState, ContentType $contentType): Post
{
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    connectAiTo($user);

    $account = SocialAccount::factory()->{$factoryState}()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account, $contentType)->create([
        'user_id' => $user->id,
        'content' => 'Our launch is live',
    ]);

    test()->actingAs($user);

    return $post;
}

test('a linkedin comment takes no media and the media button says why', function () {
    $post = linkedInCommentPost('linkedinPage', ContentType::LinkedInPagePost);
    $id = $post->social_account_id;

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, '@thread-start');
    $page->click('@thread-start');
    waitFor($page, '@thread-reply-0');
    waitFor($page, "@composer-{$id}-media-source-disabled");

    $page->assertMissing("@composer-{$id}-reply-0-dropzone")
        ->assertMissing("@composer-{$id}-media-source-menu")
        ->hover("@composer-{$id}-media-source-disabled");
    waitForText($page, '[data-slot="tooltip-content"]', __('posts.form.thread.comment_attachments'));
    $page->assertNoJavaScriptErrors();
});

test('a linkedin post takes one comment that shows under the post in the preview and is kept after saving', function (string $factoryState, ContentType $contentType) {
    $post = linkedInCommentPost($factoryState, $contentType);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, '@thread-start');
    $page->assertSeeIn('@thread-start', __('posts.form.thread.add_comment'))
        ->click('@thread-start');
    waitFor($page, '@thread-reply-0');

    $page->fill('@thread-reply-0', 'Read the full story at example.com');
    waitForText($page, '@preview-comment-0', 'Read the full story at example.com');
    $page->assertMissing('@thread-add-reply')
        ->assertMissing('@thread-start')
        ->assertMissing('@preview-thread-reply-0')
        ->assertNoJavaScriptErrors();

    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect($post->fresh()->meta['thread_replies'])->toEqual([['text' => 'Read the full story at example.com', 'media' => []]]);
})->with([
    'profile' => ['linkedin', ContentType::LinkedInPost],
    'page' => ['linkedinPage', ContentType::LinkedInPagePost],
]);

test('shift enter breaks the line in a linkedin caption and comment instead of opening a thread', function () {
    $post = linkedInCommentPost('linkedin', ContentType::LinkedInPost);
    $caption = "@composer-caption-{$post->social_account_id}";

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, $caption);

    $page->keys($caption, 'End')->keys($caption, 'Shift+Enter');
    waitForState($page, $caption, "element.value.includes('\\n')");

    $page->assertMissing('@thread-reply-0')
        ->assertPresent('@thread-start')
        ->click('@thread-start');
    waitFor($page, '@thread-reply-0');

    $page->fill('@thread-reply-0', 'First line')
        ->keys('@thread-reply-0', 'Shift+Enter');
    waitForState($page, '@thread-reply-0', "element.value === 'First line\\n'");

    $page->assertMissing('@thread-reply-1')->assertNoJavaScriptErrors();
});

test('a linkedin comment over 3000 characters blocks publishing with a comment error', function () {
    $post = linkedInCommentPost('linkedin', ContentType::LinkedInPost);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, '@thread-start');
    $page->click('@thread-start');
    waitFor($page, '@thread-reply-0');

    $page->fill('@thread-reply-0', str_repeat('a', 3001))
        ->hover('@composer-submit');
    waitForText($page, '[data-slot="tooltip-content"]', __('posts.form.thread.comment_too_long', ['limit' => 3000, 'over' => 1]));

    $page->assertNoJavaScriptErrors();
});

test('a linkedin comment speaks of a comment and removing it brings the add button back', function () {
    $post = linkedInCommentPost('linkedin', ContentType::LinkedInPost);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, '@thread-start');
    $page->click('@thread-start');
    waitFor($page, '@thread-reply-0');

    $page->assertAttribute('@thread-reply-0', 'placeholder', __('posts.form.thread.comment_placeholder'))
        ->assertAttribute('@thread-reply-0', 'aria-label', __('posts.form.thread.comment_label'))
        ->assertAttribute('@thread-reply-remove-0', 'aria-label', __('posts.form.thread.remove_comment'))
        ->click('@thread-reply-remove-0');
    waitForGone($page, '@thread-reply-0');
    waitFor($page, '@thread-start');

    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect($post->fresh()->meta)->not->toHaveKey('thread_replies');
    $page->assertNoJavaScriptErrors();
});

test('a linkedin post opened with a saved comment shows it as a comment and keeps it on save', function () {
    $post = linkedInCommentPost('linkedin', ContentType::LinkedInPost);
    $post->update(['meta' => ['thread_replies' => [['text' => 'Saved comment', 'media' => []]]]]);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, '@preview-comment-0');

    $page->assertSeeIn('@preview-comment-0', 'Saved comment')
        ->assertMissing('@preview-thread-reply-0')
        ->assertMissing('@thread-start')
        ->assertNoJavaScriptErrors();

    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect($post->fresh()->meta['thread_replies'])->toEqual([['text' => 'Saved comment', 'media' => []]]);
});

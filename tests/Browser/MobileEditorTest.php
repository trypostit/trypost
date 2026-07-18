<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\User;
use App\Models\Workspace;

/**
 * Seed an authenticated user whose current workspace holds a fresh draft post,
 * and act as them. Returns the post so each test can drive its editor.
 */
function seedMobileEditorPost(array $postAttributes = []): Post
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->create(array_merge([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'hello from mobile',
    ], $postAttributes));

    test()->actingAs($user);

    return $post;
}

/**
 * Poll browser-side until the testid element has laid out (width/height > 0).
 * These Pest browser assertions do not auto-wait, so settle async UI first.
 */
function waitForTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

test('the schedule/publish action bar is reachable on a phone', function () {
    $post = seedMobileEditorPost();

    $page = visit(route('app.posts.edit', $post))->resize(375, 812);

    waitForTestId($page, 'editor-action-bar');
    $page->assertVisible('@editor-action-bar');
});

test('the mobile switcher reveals the channels panel', function () {
    $post = seedMobileEditorPost();

    $page = visit(route('app.posts.edit', $post))->resize(375, 812);

    $page->assertVisible('@editor-mobile-nav')
        ->click('@editor-nav-channels');

    waitForTestId($page, 'channels-panel');
    $page->assertVisible('@channels-panel');
});

test('media tile actions are visible on a phone without hover', function () {
    $post = seedMobileEditorPost([
        'media' => [[
            'id' => 'm1',
            'type' => 'image',
            'mime_type' => 'image/png',
            'path' => 'uploads/x.png',
            'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ]],
    ]);

    $page = visit(route('app.posts.edit', $post))->resize(375, 812);

    waitForTestId($page, 'media-remove');
    $page->assertVisible('@media-remove');
});

test('comment actions are visible on a phone without hover', function () {
    $post = seedMobileEditorPost();
    PostComment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $post->user_id,
        'body' => 'a comment on the go',
    ]);

    $page = visit(route('app.posts.edit', $post))->resize(375, 812);

    $page->click('@editor-nav-comments');

    waitForTestId($page, 'comment-reply');
    $page->assertVisible('@comment-reply');
});

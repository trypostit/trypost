<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\PostNote;
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
    $workspace->members()->attach($user->id, membershipPivot('member'));
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

test('post notes are accessible on a phone after the post exists', function () {
    $post = seedMobileEditorPost();
    PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $post->user_id,
        'body' => 'a note on the go',
    ]);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']))->resize(375, 812);

    $page->click("@post-notes-trigger-{$post->id}");
    waitForTestId($page, 'note-actions');
    $page->assertVisible('@note-actions');
});

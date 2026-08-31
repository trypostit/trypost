<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

/**
 * The editor counts characters and renders the X preview client-side, mirroring
 * `ContentSanitizer` in TypeScript. These drive the real editor so that mirror is
 * covered by something other than a promise to keep it in step.
 */
function seedXDefusingPost(string $content): Post
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $account = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => $content,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::X,
        'content_type' => ContentType::XPost,
        'enabled' => true,
    ]);

    test()->actingAs($user);

    return $post;
}

function waitForXDefusingTestId(mixed $page, string $testId): void
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

test('the x preview shows the link as it will be published', function () {
    config()->set('trypost.platforms.x.defuse_links', true);

    $post = seedXDefusingPost('New post: https://acme.com/blog');

    $page = visit(route('app.posts.edit', $post))->resize(375, 812);
    waitForXDefusingTestId($page, 'editor-nav-preview');
    $page->click('@editor-nav-preview');
    waitForXDefusingTestId($page, 'x-preview-content');

    $page->assertSee('New post: acme(.)com/blog')
        ->assertDontSee('https://acme.com/blog')
        ->assertNoJavaScriptErrors();
});

test('the x preview leaves the link alone when defusing is disabled', function () {
    config()->set('trypost.platforms.x.defuse_links', false);

    $post = seedXDefusingPost('New post: https://acme.com/blog');

    $page = visit(route('app.posts.edit', $post))->resize(375, 812);
    waitForXDefusingTestId($page, 'editor-nav-preview');
    $page->click('@editor-nav-preview');
    waitForXDefusingTestId($page, 'x-preview-content');

    $page->assertSee('New post: https://acme.com/blog')
        ->assertNoJavaScriptErrors();
});

test('the character counter counts the defused length for x', function () {
    config()->set('trypost.platforms.x.defuse_links', true);

    // 31 raw characters; defused to 25, since the scheme goes and one dot grows.
    $post = seedXDefusingPost('New post: https://acme.com/blog');

    $page = visit(route('app.posts.edit', $post));
    waitForXDefusingTestId($page, 'content-counter-x');

    $page->assertSee('25/280')
        ->assertNoJavaScriptErrors();
});

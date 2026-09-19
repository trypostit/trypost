<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

/**
 * Wait for a data-testid element to mount and lay out. Pest browser assertions
 * do not auto-wait on SPA paint.
 */
function waitForPostShowTestId(mixed $page, string $testId): void
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

test('the post page tags the format only where the platform offered a choice', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    subscribeAccount($user->account);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Published,
        'content' => 'Published everywhere',
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id])->id,
        'platform' => Platform::Facebook,
        'content_type' => ContentType::FacebookReel,
        'status' => PostPlatformStatus::Published,
        'enabled' => true,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn])->id,
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
        'status' => PostPlatformStatus::Published,
        'enabled' => true,
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.show', $post));

    waitForPostShowTestId($page, 'content-type-facebook_reel');

    $page->assertVisible('@content-type-facebook_reel')
        ->assertMissing('@content-type-linkedin_post')
        ->assertNoJavaScriptErrors();
});

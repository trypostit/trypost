<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

/**
 * The composer counts characters and renders the X preview client-side, mirroring
 * `ContentSanitizer` in TypeScript. These drive the real composer so that mirror is
 * covered by something other than a promise to keep it in step.
 *
 * @return array{0: Post, 1: SocialAccount}
 */
function seedXDefusingPost(string $content): array
{
    Http::fake(['https://acme.com/*' => Http::response('<meta property="og:title" content="Acme">')]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

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

    return [$post, $account];
}

function waitForXDefusingTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                const dialog = document.querySelector('[data-testid="post-composer-dialog"]');
                if (dialog?.getAttribute('data-state') === 'open'
                    && dialog.getAnimations().every((animation) => animation.playState !== 'running')
                    && document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('the x preview shows the link as it will be published', function () {
    config()->set('trypost.platforms.x.defuse_links', true);

    [$post] = seedXDefusingPost('New post: https://acme.com/blog');

    $page = visit(route('app.posts.edit', $post));
    waitForXDefusingTestId($page, 'x-preview-content');

    $page->assertSeeIn('@x-preview-content', 'New post: acme(.)com/blog')
        ->assertDontSeeIn('@x-preview-content', 'https://acme.com/blog')
        ->assertNoJavaScriptErrors();
});

test('the x preview leaves the link alone when defusing is disabled', function () {
    config()->set('trypost.platforms.x.defuse_links', false);

    [$post] = seedXDefusingPost('New post: https://acme.com/blog');

    $page = visit(route('app.posts.edit', $post));
    waitForXDefusingTestId($page, 'x-preview-content');

    $page->assertSeeIn('@x-preview-content', 'New post: https://acme.com/blog')
        ->assertNoJavaScriptErrors();
});

test('the character counter counts the defused length for x', function () {
    config()->set('trypost.platforms.x.defuse_links', true);

    [$post, $account] = seedXDefusingPost('New post: https://acme.com/blog');

    $page = visit(route('app.posts.edit', $post));
    waitForXDefusingTestId($page, "composer-char-count-{$account->id}");

    $page->assertSeeIn("@composer-char-count-{$account->id}", '255')
        ->assertNoJavaScriptErrors();
});

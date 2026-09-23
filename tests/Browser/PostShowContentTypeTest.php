<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Vite;

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

test('the post page loads metrics that arrive after publication without a manual refresh', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->published()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $destination = PostPlatform::factory()->instagram()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_platform_id' => $destination->id,
        'platform' => Platform::Instagram,
    ]);

    $this->actingAs($user);
    Vite::useHotFile(storage_path('framework/testing/post-show-metrics-no-hot'));
    $page = visit(route('app.posts.show', $post));
    $page->assertSee('Metrics have not been collected yet.');

    AnalyticsPublicationDailySnapshot::factory()->create([
        'analytics_publication_id' => $publication->id,
        'reactions_count' => 77,
        'metrics' => [
            'reactions' => ['value' => 77, 'unit' => 'count', 'availability' => 'available'],
        ],
    ]);

    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                if (document.body.innerText.includes('77')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertSee('77')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

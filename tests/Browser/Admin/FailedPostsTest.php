<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;

function adminBrowserFailedPost(array $attributes = []): Post
{
    $owner = workspaceOwner(['name' => 'Owner'], ['name' => 'Acme']);
    $channel = SocialAccount::factory()->create(['workspace_id' => $owner->current_workspace_id, 'platform' => Platform::X]);

    return Post::factory()->forAccount($channel)->failed()->create(['user_id' => $owner->id, ...$attributes]);
}

test('an admin reads a failed post and retries it', function () {
    Queue::fake();
    $this->actingAs(admin());
    $post = adminBrowserFailedPost(['content' => '<p>Launch day</p>']);

    $page = visit(route('admin.posts.failed.index'));

    waitFor($page, "@admin-failed-post-{$post->id}");
    $page->click("@admin-view-post-{$post->id}");
    waitForOpen($page, '@admin-failed-post-dialog');
    $page->assertSeeIn('@admin-failed-post-content', 'Launch day');
    $page->keys('@admin-failed-post-dialog', 'Escape');
    waitForGone($page, '@admin-failed-post-dialog');

    $page->click("@admin-retry-post-{$post->id}");
    waitForGone($page, "@admin-failed-post-{$post->id}");

    Queue::assertPushed(PublishToSocialPlatform::class, 1);
    expect($post->fresh()->status)->toBe(Status::Publishing);
    $page->assertNoJavaScriptErrors();
});

test('the error categories filter the failed posts', function () {
    $this->actingAs(admin());
    $limited = adminBrowserFailedPost(['error_context' => ['category' => 'rate_limit']]);
    $other = adminBrowserFailedPost(['error_context' => ['category' => 'server_error']]);

    $page = visit(route('admin.posts.failed.index'));

    waitFor($page, "@admin-failed-post-{$other->id}");
    $page->click('@admin-failed-category-rate_limit');
    waitForGone($page, "@admin-failed-post-{$other->id}");
    waitFor($page, "@admin-failed-post-{$limited->id}");
    waitForCondition($page, "location.search.includes('category=rate_limit')");

    $page->click('@admin-failed-category-all');
    waitFor($page, "@admin-failed-post-{$other->id}");
    $page->assertNoJavaScriptErrors();
});

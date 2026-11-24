<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

test('an instagram post published without its first comment says so on its card, with what to do in a popover', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    connectAiTo($user);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);

    $sentPost = fn (int $hoursAgo): Post => Post::factory()->forAccount($account, ContentType::InstagramFeed)->published()->create([
        'user_id' => $user->id,
        'meta' => ['thread_replies' => [['text' => 'Link in bio', 'media' => []]]],
        'published_at' => now()->subHours($hoursAgo),
    ]);
    $skipped = $sentPost(1);
    $skipped->recordInstagramCommentSkipped();
    $commented = $sentPost(2);

    $this->actingAs($user);
    $page = visit(route('app.posts.index', ['tab' => 'sent']));

    waitFor($page, "@post-comment-warning-{$skipped->id}");
    waitFor($page, "@post-card-{$commented->id}");

    $page->assertSeeIn("@post-comment-warning-{$skipped->id}", __('posts.publish.instagram_comment_warning.badge'))
        ->assertMissing("@post-comment-warning-{$commented->id}");

    $page->click("@post-comment-warning-{$skipped->id}");
    waitForOpen($page, "@post-comment-warning-popover-{$skipped->id}");

    $page->assertSeeIn("@post-comment-warning-popover-{$skipped->id}", __('posts.publish.instagram_comment_warning.title'))
        ->assertSeeIn("@post-comment-warning-popover-{$skipped->id}", __('posts.publish.instagram_comment_warning.description'))
        ->assertNoJavaScriptErrors();
});

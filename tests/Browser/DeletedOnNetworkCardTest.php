<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

test('a post deleted on the network shows as deleted and offers no link to it', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    connectAiTo($user);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);

    $sentPost = fn (PublishStatus $publishStatus, int $hoursAgo): Post => Post::factory()->forAccount($account)->published()->create([
        'user_id' => $user->id,
        'publish_status' => $publishStatus,
        'platform_url' => 'https://www.instagram.com/p/abc/',
        'published_at' => now()->subHours($hoursAgo),
    ]);
    $deleted = $sentPost(PublishStatus::Deleted, 1);
    $live = $sentPost(PublishStatus::Published, 2);

    $this->actingAs($user);
    $page = visit(route('app.posts.index', ['tab' => 'sent']));

    waitFor($page, "@post-deleted-on-network-{$deleted->id}");
    waitFor($page, "@post-view-{$live->id}");

    $page->assertVisible("@post-deleted-on-network-hint-{$deleted->id}")
        ->assertMissing("@post-view-{$deleted->id}")
        ->assertMissing("@post-deleted-on-network-{$live->id}");

    $page->click("@post-card-menu-{$deleted->id}");
    waitFor($page, "@post-card-menu-content-{$deleted->id}");

    $page->assertVisible("@post-delete-{$deleted->id}")
        ->assertMissing("@post-share-link-{$deleted->id}");

    $page->click("@post-details-open-{$deleted->id}");
    waitFor($page, "@post-details-{$deleted->id}");

    $page->assertMissing("@post-details-view-{$deleted->id}")
        ->assertNoJavaScriptErrors();
});

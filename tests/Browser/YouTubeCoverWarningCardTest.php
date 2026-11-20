<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\YouTube\ThumbnailWarning;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

test('a Short whose cover YouTube refused shows it under its time, with the reason in a popover', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    connectAiTo($user);
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);

    $sentShort = fn (int $hoursAgo): Post => Post::factory()->forAccount($account, ContentType::YouTubeShort)->published()->create([
        'user_id' => $user->id,
        'platform_url' => 'https://www.youtube.com/shorts/abc',
        'published_at' => now()->subHours($hoursAgo),
    ]);
    $refused = $sentShort(1);
    $refused->recordYouTubeThumbnailWarning(ThumbnailWarning::Forbidden);
    $applied = $sentShort(2);

    $this->actingAs($user);
    $page = visit(route('app.posts.index', ['tab' => 'sent']));

    waitFor($page, "@post-cover-warning-{$refused->id}");
    waitFor($page, "@post-card-{$applied->id}");

    $page->assertSeeIn("@post-cover-warning-{$refused->id}", __('posts.publish.youtube_thumbnail_warning.badge'))
        ->assertMissing("@post-cover-warning-{$applied->id}");

    $page->click("@post-cover-warning-{$refused->id}");
    waitForOpen($page, "@post-cover-warning-popover-{$refused->id}");

    $page->assertSeeIn("@post-cover-warning-popover-{$refused->id}", __('posts.publish.youtube_thumbnail_warning.title'))
        ->assertSeeIn("@post-cover-warning-reason-{$refused->id}", __('posts.publish.youtube_thumbnail_warning.forbidden', ['url' => 'youtube.com/verify']))
        ->assertAttribute("@post-cover-warning-verify-{$refused->id}", 'href', 'https://www.youtube.com/verify')
        ->assertAttribute("@post-cover-warning-verify-{$refused->id}", 'target', '_blank')
        ->assertNoJavaScriptErrors();
});

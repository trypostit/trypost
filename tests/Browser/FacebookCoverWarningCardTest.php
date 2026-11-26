<?php

declare(strict_types=1);

use App\Enums\Facebook\CoverFailure;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\FacebookCover;
use Illuminate\Support\Facades\Queue;

test('a reel whose cover Facebook did not take shows it under its time, with what to do in a popover', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    connectAiTo($user);
    $account = SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);

    $sentReel = fn (int $hoursAgo): Post => Post::factory()->forAccount($account, ContentType::FacebookReel)->published()->create([
        'user_id' => $user->id,
        'platform_url' => 'https://www.facebook.com/reel/abc',
        'published_at' => now()->subHours($hoursAgo),
    ]);
    $refused = $sentReel(1);
    FacebookCover::recordWarning($refused, FacebookCover::failure(CoverFailure::Refused));
    $applied = $sentReel(2);

    $this->actingAs($user);
    $page = visit(route('app.posts.index', ['tab' => 'sent']));

    waitFor($page, "@post-facebook-cover-warning-{$refused->id}");
    waitFor($page, "@post-card-{$applied->id}");

    $page->assertSeeIn("@post-facebook-cover-warning-{$refused->id}", __('posts.publish.facebook_cover_warning.badge'))
        ->assertMissing("@post-facebook-cover-warning-{$applied->id}");

    $page->click("@post-facebook-cover-warning-{$refused->id}");
    waitForOpen($page, "@post-facebook-cover-warning-popover-{$refused->id}");

    $page->assertSeeIn("@post-facebook-cover-warning-popover-{$refused->id}", __('posts.publish.facebook_cover_warning.title'))
        ->assertSeeIn("@post-facebook-cover-warning-popover-{$refused->id}", __('posts.publish.facebook_cover_warning.description'))
        ->assertNoJavaScriptErrors();
});

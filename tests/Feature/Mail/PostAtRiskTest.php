<?php

declare(strict_types=1);

use App\Mail\PostAtRisk;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;

test('renders subject and body listing the at-risk account and its post times', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->setTime(14, 30),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    $mailable = new PostAtRisk($workspace, [$postPlatform->id], 1);

    $mailable->assertHasSubject('1 post is at risk in Acme Co');
    $mailable->assertSeeInHtml('Posts May Fail to Publish');
    $mailable->assertSeeInHtml('Acme Co');
    $mailable->assertSeeInHtml('1 post scheduled: 14:30 UTC');
});

test('renders plural subject and postsLabel when multiple posts are at risk', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);

    $postPlatformIds = collect([
        ['time' => [14, 30]],
        ['time' => [15, 0]],
    ])->map(function (array $data) use ($workspace, $account) {
        $post = Post::factory()->scheduled()->create([
            'workspace_id' => $workspace->id,
            'scheduled_at' => now()->setTime(...$data['time']),
        ]);

        return PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
        ])->id;
    })->all();

    $mailable = new PostAtRisk($workspace, $postPlatformIds, 2);

    $mailable->assertHasSubject('2 posts are at risk in Acme Co');
    $mailable->assertSeeInHtml('2 posts scheduled: 14:30, 15:00 UTC');
});

test('groups post_platforms by account when rehydrating for send', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $threadsAccount = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $facebookAccount = SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id]);

    $threadsPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->setTime(9, 0),
    ]);
    $threadsPostPlatform = PostPlatform::factory()->create([
        'post_id' => $threadsPost->id,
        'social_account_id' => $threadsAccount->id,
        'platform' => $threadsAccount->platform,
    ]);

    $facebookPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->setTime(10, 0),
    ]);
    $facebookPostPlatform = PostPlatform::factory()->create([
        'post_id' => $facebookPost->id,
        'social_account_id' => $facebookAccount->id,
        'platform' => $facebookAccount->platform,
    ]);

    $mailable = new PostAtRisk($workspace, [$threadsPostPlatform->id, $facebookPostPlatform->id], 2);

    $mailable->assertHasSubject('2 posts are at risk in Acme Co');
    $mailable->assertSeeInHtml('1 post scheduled: 09:00 UTC');
    $mailable->assertSeeInHtml('1 post scheduled: 10:00 UTC');
});

test('only carries the workspace, post_platform IDs, and count on the queue payload, not full model graphs', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'access_token' => 'super-secret-token-value',
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->setTime(14, 30),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    $mailable = new PostAtRisk($workspace, [$postPlatform->id], 1);

    $serialized = serialize($mailable);

    expect($serialized)->not->toContain('super-secret-token-value')
        ->and($serialized)->not->toContain(SocialAccount::class)
        ->and($serialized)->not->toContain(PostPlatform::class)
        ->and(strlen($serialized))->toBeLessThan(2000);
});

test('footer links to notification preferences instead of an unsubscribe link', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->setTime(14, 30),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    $mailable = new PostAtRisk($workspace, [$postPlatform->id], 1);

    $mailable->assertSeeInHtml('Manage notifications');
    $mailable->assertSeeInHtml(route('app.notifications.preferences'));
    $mailable->assertDontSeeInHtml('Unsubscribe');
});

test('subject and previewText stay locked to the dispatch-time count even if a row disappears before send', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->setTime(14, 30),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    $mailable = new PostAtRisk($workspace, [$postPlatform->id], 1);

    // Simulate the row disappearing between when this mailable was
    // dispatched and when it's actually rendered for send — the count
    // passed at construction (matching the in-app notification's title)
    // must not be recomputed from the now-stale rehydrated rows.
    $postPlatform->delete();

    $mailable->assertHasSubject('1 post is at risk in Acme Co');
    $content = $mailable->content();
    expect($content->with['previewText'])->toBe('1 post is at risk in Acme Co');
});

test('renders without crashing when none of the post_platform ids resolve', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);

    $mailable = new PostAtRisk($workspace, ['00000000-0000-0000-0000-000000000000'], 0);

    $mailable->assertHasSubject('0 posts are at risk in Acme Co');
});

test('renders without crashing when the account is deleted before send', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->setTime(14, 30),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    $mailable = new PostAtRisk($workspace, [$postPlatform->id], 1);

    // Deleting the account (not the post_platform) between dispatch and
    // send: the FK's nullOnDelete sets post_platforms.social_account_id to
    // null, so the rehydrated group's account resolves to null. There's
    // nothing meaningful to render for it (no platform, no handle), so it
    // must be dropped from the body rather than crash the render.
    $account->delete();

    $mailable->assertHasSubject('1 post is at risk in Acme Co');
    $mailable->assertDontSeeInHtml('scheduled: 14:30 UTC');
});

<?php

declare(strict_types=1);

use App\Mail\PostAtRisk;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Collection;

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

    $groups = new Collection([
        ['account' => $account, 'postPlatforms' => new Collection([$postPlatform])],
    ]);

    $mailable = new PostAtRisk($workspace, $groups);

    $mailable->assertHasSubject('1 post is at risk in Acme Co');
    $mailable->assertSeeInHtml('Posts May Fail to Publish');
    $mailable->assertSeeInHtml('Acme Co');
    $mailable->assertSeeInHtml('1 post scheduled: 14:30 UTC');
});

test('renders plural subject and postsLabel when multiple posts are at risk', function () {
    $workspace = Workspace::factory()->create(['name' => 'Acme Co']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);

    $postPlatforms = collect([
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
        ]);
    });

    $groups = new Collection([
        ['account' => $account, 'postPlatforms' => new Collection($postPlatforms->all())],
    ]);

    $mailable = new PostAtRisk($workspace, $groups);

    $mailable->assertHasSubject('2 posts are at risk in Acme Co');
    $mailable->assertSeeInHtml('2 posts scheduled: 14:30, 15:00 UTC');
});

test('escapes a workspace name containing HTML in the intro', function () {
    $workspace = Workspace::factory()->create(['name' => '<script>alert(1)</script>']);
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

    $groups = new Collection([
        ['account' => $account, 'postPlatforms' => new Collection([$postPlatform])],
    ]);

    $mailable = new PostAtRisk($workspace, $groups);

    $mailable->assertDontSeeInHtml('<script>alert(1)</script>', false);
    $mailable->assertSeeInHtml('<script>alert(1)</script>');
});

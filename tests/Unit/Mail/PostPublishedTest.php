<?php

declare(strict_types=1);

use App\Mail\PostPublished;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;

test('published email falls back to the page display name when facebook has no username', function () {
    $workspace = Workspace::factory()->create(['name' => 'InboxPlacement.io']);
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'username' => null,
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->published()->create(['workspace_id' => $workspace->id]);
    PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'platform_url' => 'https://www.facebook.com/permalink.php?story_fbid=pfbid0&id=61592851040951',
    ]);

    $mail = new PostPublished($post);

    $mail->assertSeeInHtml('Facebook Page (@InboxPlacement.io)');
    $mail->assertDontSeeInHtml('Facebook Page (@)');
    $mail->assertSeeInHtml('https://www.facebook.com/permalink.php?story_fbid=pfbid0&id=61592851040951');
});

test('published email uses the username when the display name is empty', function () {
    $workspace = Workspace::factory()->create(['name' => 'InboxPlacement.io']);
    $account = SocialAccount::factory()->bluesky()->create([
        'workspace_id' => $workspace->id,
        'username' => 'inboxplacementio.bsky.social',
        'display_name' => '',
    ]);
    $post = Post::factory()->published()->create(['workspace_id' => $workspace->id]);
    PostPlatform::factory()->bluesky()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    $mail = new PostPublished($post);

    $mail->assertSeeInHtml('Bluesky (@inboxplacementio.bsky.social)');
    $mail->assertDontSeeInHtml('Bluesky (@)');
});

test('published email omits empty parentheses when both identifiers are missing', function () {
    $workspace = Workspace::factory()->create(['name' => 'InboxPlacement.io']);
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'username' => null,
        'display_name' => '',
    ]);
    $post = Post::factory()->published()->create(['workspace_id' => $workspace->id]);
    PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    $mail = new PostPublished($post);

    $mail->assertSeeInHtml('Facebook Page');
    $mail->assertDontSeeInHtml('Facebook Page (@)');
});

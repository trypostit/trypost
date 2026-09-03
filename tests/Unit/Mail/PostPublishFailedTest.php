<?php

declare(strict_types=1);

use App\Mail\PostPublishFailed;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;

test('failed email falls back to the page display name when facebook has no username', function () {
    $workspace = Workspace::factory()->create(['name' => 'InboxPlacement.io']);
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'username' => null,
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->failed()->create(['workspace_id' => $workspace->id]);
    PostPlatform::factory()->facebook()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    $mail = new PostPublishFailed($post);

    $mail->assertSeeInHtml('Facebook Page (@InboxPlacement.io)');
    $mail->assertDontSeeInHtml('Facebook Page (@)');
});

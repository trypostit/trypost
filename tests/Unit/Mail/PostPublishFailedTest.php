<?php

declare(strict_types=1);

use App\Mail\PostPublishFailed;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;

test('failed email falls back to the page display name when facebook has no username', function () {
    $workspace = Workspace::factory()->create(['name' => 'InboxPlacement.io']);
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'username' => null,
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->forAccount($account)->failed()->create();

    $mail = new PostPublishFailed($post);

    $mail->assertSeeInHtml('Facebook Page');
    $mail->assertSeeInHtml('InboxPlacement.io');
    $mail->assertDontSeeInHtml('Facebook Page (@)');
});

test('failed email links to the post in the sent tab', function () {
    $post = Post::factory()->failed()->create();

    $mail = new PostPublishFailed($post);

    $mail->assertSeeInHtml(route('app.posts.index', ['tab' => 'sent', 'post' => $post->id]));
    $mail->assertDontSeeInHtml(route('app.posts.edit', $post), false);
});

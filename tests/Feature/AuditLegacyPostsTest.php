<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

test('strict legacy audit fails for a scheduled post without an enabled target', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => Status::Scheduled,
        'scheduled_at' => now()->addDay(),
    ]);

    $this->artisan('posts:audit-legacy', ['--strict' => true])->assertFailed();
});

test('audit is read only and reports editable multi-target posts for splitting', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $accounts = SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id]);
    foreach ($accounts as $account) {
        PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);
    }

    $this->artisan('posts:audit-legacy')->assertSuccessful();
    $this->artisan('posts:audit-legacy', ['--strict' => true])->assertFailed();

    expect(Post::count())->toBe(1)
        ->and(PostPlatform::count())->toBe(2);
});

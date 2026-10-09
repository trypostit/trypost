<?php

declare(strict_types=1);

use App\Events\PostStatusUpdated;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\PrivateChannel;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->socialAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $this->post = Post::factory()->forAccount($this->socialAccount)->scheduled()->create([
        'user_id' => $this->user->id,
    ]);
});

test('event broadcasts on the workspace channel only', function () {
    $channels = (new PostStatusUpdated($this->post))->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe("private-workspace.{$this->workspace->id}");
});

test('event broadcasts with the post id', function () {
    expect((new PostStatusUpdated($this->post))->broadcastWith())->toBe(['post_id' => $this->post->id]);
});

test('event broadcasts as a stable name', function () {
    expect((new PostStatusUpdated($this->post))->broadcastAs())->toBe('post.platform.status.updated');
});

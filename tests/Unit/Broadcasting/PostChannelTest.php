<?php

declare(strict_types=1);

use App\Broadcasting\PostChannel;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;

test('post channel allows workspace member to join', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);

    $channel = new PostChannel;
    $result = $channel->join($user, $post);

    expect($result)->toBeTrue();
});

test('post channel denies non-member from joining', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);

    $otherUser = User::factory()->create();

    $channel = new PostChannel;
    $result = $channel->join($otherUser, $post);

    expect($result)->toBeFalse();
});

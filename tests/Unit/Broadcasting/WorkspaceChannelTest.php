<?php

declare(strict_types=1);

use App\Broadcasting\WorkspaceChannel;
use App\Models\User;
use App\Models\Workspace;

test('workspace channel allows a member to join', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));

    $channel = new WorkspaceChannel;

    expect($channel->join($user, $workspace))->toBeTrue();
});

test('workspace channel denies a non-member', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);

    $outsider = User::factory()->create();

    $channel = new WorkspaceChannel;

    expect($channel->join($outsider, $workspace))->toBeFalse();
});

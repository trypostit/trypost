<?php

declare(strict_types=1);

use App\Jobs\SetupWorkspaceDefaults;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

test('creating a workspace dispatches the defaults job', function () {
    Queue::fake();
    $user = User::factory()->create();

    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    Queue::assertPushed(SetupWorkspaceDefaults::class, fn (SetupWorkspaceDefaults $job) => $job->workspaceId === $workspace->id);
});

test('the defaults job is dispatched after commit', function () {
    Queue::fake();
    $user = User::factory()->create();

    Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    Queue::assertPushed(SetupWorkspaceDefaults::class, fn (SetupWorkspaceDefaults $job) => $job->afterCommit === true);
});

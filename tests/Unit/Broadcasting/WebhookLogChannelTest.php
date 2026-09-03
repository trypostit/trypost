<?php

declare(strict_types=1);

use App\Broadcasting\WebhookLogChannel;
use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;

test('webhook log channel allows a member who can manage webhooks', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $user->refresh();

    $webhook = Webhook::factory()->create(['workspace_id' => $workspace->id]);

    expect((new WebhookLogChannel)->join($user, $webhook))->toBeTrue();
});

test('webhook log channel denies a viewer', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $viewer = User::factory()->create(['account_id' => $owner->account_id]);
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $workspace->id]);
    $viewer->refresh();

    $webhook = Webhook::factory()->create(['workspace_id' => $workspace->id]);

    expect((new WebhookLogChannel)->join($viewer, $webhook))->toBeFalse();
});

test('webhook log channel denies a user from another workspace', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $webhook = Webhook::factory()->create(['workspace_id' => $workspace->id]);

    $outsider = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $outsider->id]);
    $outsider->update(['current_workspace_id' => $otherWorkspace->id]);
    $outsider->refresh();

    expect((new WebhookLogChannel)->join($outsider, $webhook))->toBeFalse();
});

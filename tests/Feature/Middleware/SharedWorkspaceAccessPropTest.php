<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

test('the current workspace shares the access flags of the current user', function (string $access, array $expected) {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);
    $actor = $access === 'owner' ? $owner->fresh() : workspaceMember($workspace, $access);

    $this->actingAs($actor)
        ->get(route('app.calendar'))
        ->assertInertia(fn ($page) => $page
            ->where('auth.currentWorkspace.id', $workspace->id)
            ->where('auth.currentWorkspace.is_owner', $expected['is_owner'])
            ->where('auth.currentWorkspace.is_admin', $expected['is_admin'])
            ->where('auth.currentWorkspace.requires_approval', $expected['requires_approval'])
            ->missing('auth.currentWorkspace.role'));
})->with([
    'owner' => ['owner', ['is_owner' => true, 'is_admin' => true, 'requires_approval' => false]],
    'admin' => ['admin', ['is_owner' => false, 'is_admin' => true, 'requires_approval' => false]],
    'member' => ['member', ['is_owner' => false, 'is_admin' => false, 'requires_approval' => false]],
    'needs approval' => ['approval', ['is_owner' => false, 'is_admin' => false, 'requires_approval' => true]],
]);

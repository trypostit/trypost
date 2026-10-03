<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\SocialAccountPolicy;
use Illuminate\Auth\Access\Response;

beforeEach(function () {
    $this->policy = new SocialAccountPolicy;
});

test('members of the current workspace can view a social account', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    expect($this->policy->view($user->fresh(), $account))->toBeTrue();
});

test('cross-workspace social account lookups deny as not found', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $otherWorkspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $result = $this->policy->view($user->fresh(), $account);

    expect($result)->toBeInstanceOf(Response::class)
        ->and($result->status())->toBe(404);
});

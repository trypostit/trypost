<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\PostPolicy;

beforeEach(function () {
    $this->policy = new PostPolicy;
});

/**
 * Build a post + an actor with the given workspace access, both in one account.
 *
 * @return array{0: User, 1: Post}
 */
function postPolicyActor(string $access): array
{
    $account = Account::factory()->create();
    $owner = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create(['account_id' => $account->id, 'user_id' => $owner->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);

    $actor = match ($access) {
        'owner' => $owner,
        'outsider' => User::factory()->create(['account_id' => $account->id]),
        default => tap(User::factory()->create(['account_id' => $account->id]), fn (User $user) => $workspace->members()->attach($user->id, membershipPivot($access))),
    };

    $actor->update(['current_workspace_id' => $workspace->id]);

    return [$actor->refresh(), $post];
}

test('every workspace member can view a post', function (string $access) {
    [$actor, $post] = postPolicyActor($access);

    expect($this->policy->view($actor, $post))->toBeTrue();
})->with(['owner', 'admin', 'member', 'approval']);

test('post update/delete/duplicate is allowed for every member and denied outside the workspace', function (string $access, bool $allowed) {
    [$actor, $post] = postPolicyActor($access);

    expect($this->policy->update($actor, $post))->toBe($allowed);
    expect($this->policy->delete($actor, $post))->toBe($allowed);
    expect($this->policy->duplicate($actor, $post))->toBe($allowed);
})->with([
    'owner' => ['owner', true],
    'admin' => ['admin', true],
    'member' => ['member', true],
    'needs approval' => ['approval', true],
    'outsider' => ['outsider', false],
]);

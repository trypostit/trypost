<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->owner->account_id,
        'user_id' => $this->owner->id,
    ]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
});

test('the account owner is an admin who publishes directly whatever the pivot says', function () {
    $this->workspace->members()->updateExistingPivot($this->owner->id, membershipPivot('approval'));
    $owner = $this->owner->fresh();

    expect($owner->ownsAccountOf($this->workspace))->toBeTrue()
        ->and($owner->isWorkspaceAdmin($this->workspace))->toBeTrue()
        ->and($owner->requiresApprovalIn($this->workspace))->toBeFalse()
        ->and($owner->canPublishDirectlyIn($this->workspace))->toBeTrue();
});

test('membership flags drive the admin and publishing abilities', function (string $access, bool $admin, bool $direct) {
    $user = workspaceMember($this->workspace, $access);

    expect($user->ownsAccountOf($this->workspace))->toBeFalse()
        ->and($user->isWorkspaceAdmin($this->workspace))->toBe($admin)
        ->and($user->canPublishDirectlyIn($this->workspace))->toBe($direct)
        ->and($user->requiresApprovalIn($this->workspace))->toBe(! $direct);
})->with([
    'admin' => ['admin', true, true],
    'direct publisher' => ['member', false, true],
    'needs approval' => ['approval', false, false],
]);

test('an admin row that also carries the approval flag still publishes directly', function () {
    $user = workspaceMember($this->workspace, 'admin');
    $this->workspace->members()->updateExistingPivot($user->id, ['is_admin' => true, 'requires_approval' => true]);

    expect($user->fresh()->requiresApprovalIn($this->workspace))->toBeFalse()
        ->and($user->fresh()->canPublishDirectlyIn($this->workspace))->toBeTrue();
});

test('a user outside the workspace has no ability and is never gated', function () {
    $outsider = workspaceOutsider($this->workspace);

    expect($outsider->isWorkspaceAdmin($this->workspace))->toBeFalse()
        ->and($outsider->canPublishDirectlyIn($this->workspace))->toBeFalse()
        ->and($outsider->requiresApprovalIn($this->workspace))->toBeFalse();
});

test('a pivot row from another account grants nothing', function () {
    $stranger = User::factory()->create();
    $this->workspace->members()->attach($stranger->id, membershipPivot('admin'));

    expect($stranger->fresh()->isWorkspaceAdmin($this->workspace))->toBeFalse()
        ->and($stranger->fresh()->canPublishDirectlyIn($this->workspace))->toBeFalse();
});

test('approvers are the owner and direct publishers, in a query count that does not grow with members', function () {
    $publisher = workspaceMember($this->workspace, 'member');
    $admin = workspaceMember($this->workspace, 'admin');
    workspaceMember($this->workspace, 'approval');

    $countQueries = function (): array {
        $workspace = $this->workspace->fresh();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $ids = $workspace->approvers()->pluck('id')->sort()->values()->all();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$ids, $queries];
    };

    [$ids, $fewMembers] = $countQueries();

    expect($ids)->toBe(collect([$this->owner->id, $publisher->id, $admin->id])->sort()->values()->all());

    foreach (range(1, 4) as $ignored) {
        workspaceMember($this->workspace, 'member');
        workspaceMember($this->workspace, 'approval');
    }

    [$ids, $manyMembers] = $countQueries();

    expect($ids)->toHaveCount(7)
        ->and($manyMembers)->toBe($fewMembers);
});

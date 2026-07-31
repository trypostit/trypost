<?php

declare(strict_types=1);

use App\Actions\Workspace\DeleteWorkspace;
use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

test('delete workspace does not lazy load account while reassigning current workspace', function () {
    Model::preventLazyLoading();

    $owner = User::factory()->create();

    $current = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $current->members()->attach($owner->id, ['role' => Role::Admin->value]);
    $owner->update(['current_workspace_id' => $current->id]);

    $other = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);

    $member = User::factory()->create(['account_id' => $owner->account_id]);
    $current->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $current->id]);

    expect(DeleteWorkspace::execute($current))->toBeTrue();

    $owner->refresh();

    expect($owner->current_workspace_id)->toBe($other->id);
    expect(User::find($member->id))->toBeNull();
});

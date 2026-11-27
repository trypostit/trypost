<?php

declare(strict_types=1);

use App\Models\PostTemplate;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $this->alice = workspaceMember($this->workspace, 'member');
    $this->bob = workspaceMember($this->workspace, 'member');
});

test('a teammate personal template never leaks through the scopes', function () {
    $aliceTemplate = PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);
    PostTemplate::factory()->personal($this->bob)->create(['workspace_id' => $this->workspace->id]);
    $teamTemplate = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id]);

    $visible = PostTemplate::query()->where('workspace_id', $this->workspace->id)->visibleTo($this->alice)->pluck('id');

    expect($visible->all())->toEqualCanonicalizing([$aliceTemplate->id, $teamTemplate->id])
        ->and(PostTemplate::query()->where('workspace_id', $this->workspace->id)->personalFor($this->alice)->pluck('id')->all())->toBe([$aliceTemplate->id])
        ->and(PostTemplate::query()->where('workspace_id', $this->workspace->id)->team()->pluck('id')->all())->toBe([$teamTemplate->id]);
});

test('orphaned personal templates are hidden and team templates survive their creator', function () {
    $orphan = PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);
    $team = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->alice->id]);

    $this->alice->delete();

    expect($orphan->fresh()->user_id)->toBeNull()
        ->and($team->fresh()->user_id)->toBeNull()
        ->and(PostTemplate::query()->visibleTo($this->bob)->pluck('id')->all())->toBe([$team->id])
        ->and(PostTemplate::query()->personalFor($this->bob)->count())->toBe(0);
});

test('the policy allows team templates and own personal templates only', function () {
    $team = PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id]);
    $alicePersonal = PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);
    $outsider = workspaceOutsider($this->workspace);

    foreach (['view', 'update', 'delete'] as $ability) {
        expect($this->bob->can($ability, $team))->toBeTrue($ability)
            ->and($this->alice->can($ability, $alicePersonal))->toBeTrue($ability)
            ->and($this->bob->can($ability, $alicePersonal))->toBeFalse($ability)
            ->and($outsider->can($ability, $team))->toBeFalse($ability);
    }

    expect($this->bob->can('create', PostTemplate::class))->toBeTrue()
        ->and($this->bob->can('viewAny', PostTemplate::class))->toBeTrue()
        ->and($outsider->can('create', PostTemplate::class))->toBeFalse()
        ->and($outsider->can('viewAny', PostTemplate::class))->toBeFalse();
});

test('an orphaned personal template is denied to everyone', function () {
    $orphan = PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);
    $this->alice->delete();

    foreach (['view', 'update', 'delete'] as $ability) {
        expect($this->bob->can($ability, $orphan->fresh()))->toBeFalse($ability);
    }
});

test('the morph alias is registered and deleting the workspace cascades', function () {
    PostTemplate::factory()->team()->create(['workspace_id' => $this->workspace->id]);
    PostTemplate::factory()->personal($this->alice)->create(['workspace_id' => $this->workspace->id]);

    expect((new PostTemplate)->getMorphClass())->toBe('postTemplate')
        ->and($this->workspace->postTemplates)->toHaveCount(2);

    $this->workspace->delete();

    expect(PostTemplate::query()->count())->toBe(0);
});

<?php

declare(strict_types=1);

use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->ideaStages()->delete();
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);
});

test('store appends a stage at the end and validates the name', function () {
    IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 0]);
    IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 4]);

    $this->actingAs($this->user)
        ->post(route('app.create.idea-stages.store'), ['name' => 'Backlog'])
        ->assertRedirect()
        ->assertSessionMissing('flash.banner');

    expect(IdeaStage::query()->where('name', 'Backlog')->value('position'))->toBe(5);

    $this->actingAs($this->user)
        ->postJson(route('app.create.idea-stages.store'), ['name' => str_repeat('a', 61)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
});

test('update renames a stage', function () {
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->put(route('app.create.idea-stages.update', $stage), ['name' => 'Renamed'])
        ->assertRedirect()
        ->assertSessionMissing('flash.banner');

    expect($stage->fresh()->name)->toBe('Renamed');
});

test('deleting a stage appends its ideas to Unassigned in order and compacts positions', function () {
    $first = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 0]);
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 1]);
    $last = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 2]);

    $u1 = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 0]);
    $u2 = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 1]);
    $g2 = Idea::factory()->inStage($stage)->create(['position' => 1]);
    $g1 = Idea::factory()->inStage($stage)->create(['position' => 0]);

    $this->actingAs($this->user)
        ->delete(route('app.create.idea-stages.destroy', $stage))
        ->assertRedirect()
        ->assertSessionMissing('flash.banner');

    expect(IdeaStage::query()->whereKey($stage->id)->exists())->toBeFalse();

    $unassigned = Idea::query()
        ->where('workspace_id', $this->workspace->id)
        ->whereNull('idea_stage_id')
        ->orderBy('position')
        ->pluck('id')
        ->all();

    expect($unassigned)->toBe([$u1->id, $u2->id, $g1->id, $g2->id])
        ->and($this->workspace->ideaStages()->pluck('id')->all())->toBe([$first->id, $last->id])
        ->and($this->workspace->ideaStages()->pluck('position')->all())->toBe([0, 1]);
});

test('deleting a stage with an empty Unassigned starts at position zero and spares other workspaces', function () {
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 0]);
    $g1 = Idea::factory()->inStage($stage)->create(['position' => 5]);
    $g2 = Idea::factory()->inStage($stage)->create(['position' => 9]);
    $foreign = Idea::factory()->create(['position' => 7]);

    $this->actingAs($this->user)
        ->delete(route('app.create.idea-stages.destroy', $stage))
        ->assertRedirect();

    expect($g1->fresh()->position)->toBe(0)
        ->and($g2->fresh()->position)->toBe(1)
        ->and($g1->fresh()->idea_stage_id)->toBeNull()
        ->and($foreign->fresh()->position)->toBe(7);
});

test('reorder rewrites positions', function () {
    $a = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 0]);
    $b = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 1]);
    $c = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 2]);

    $this->actingAs($this->user)
        ->put(route('app.create.idea-stages.reorder'), ['stage_ids' => [$c->id, $b->id, $a->id]])
        ->assertRedirect();

    expect($this->workspace->ideaStages()->pluck('id')->all())->toBe([$c->id, $b->id, $a->id]);
});

test('reorder rejects a stale id list and changes nothing', function (string $case) {
    $a = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 0]);
    $b = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 1]);
    $foreign = IdeaStage::factory()->create();

    $ids = match ($case) {
        'missing' => [$b->id],
        'extra' => [$b->id, $a->id, $foreign->id],
        'foreign' => [$b->id, $foreign->id],
        'duplicate' => [$a->id, $a->id],
    };

    $this->actingAs($this->user)
        ->putJson(route('app.create.idea-stages.reorder'), ['stage_ids' => $ids])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('stage_ids');

    expect($this->workspace->ideaStages()->pluck('id')->all())->toBe([$a->id, $b->id]);
})->with(['missing', 'extra', 'foreign', 'duplicate']);

test('another workspace stage cannot be updated or deleted', function () {
    $foreign = IdeaStage::factory()->create();

    $this->actingAs($this->user)
        ->put(route('app.create.idea-stages.update', $foreign), ['name' => 'Nope'])
        ->assertNotFound();

    $this->actingAs($this->user)
        ->delete(route('app.create.idea-stages.destroy', $foreign))
        ->assertNotFound();

    expect($foreign->fresh()->name)->not->toBe('Nope');
});

test('a user outside the workspace is forbidden on every endpoint', function () {
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider)->post(route('app.create.idea-stages.store'), ['name' => 'X'])->assertForbidden();
    $this->actingAs($outsider)->put(route('app.create.idea-stages.reorder'), ['stage_ids' => [$stage->id]])->assertForbidden();
    $this->actingAs($outsider)->put(route('app.create.idea-stages.update', $stage), ['name' => 'X'])->assertForbidden();
    $this->actingAs($outsider)->delete(route('app.create.idea-stages.destroy', $stage))->assertForbidden();
});

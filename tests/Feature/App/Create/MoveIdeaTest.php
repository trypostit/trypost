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
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);

    $this->stageA = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 0]);
    $this->stageB = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 1]);
    $this->stageC = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 2]);

    [$this->a1, $this->a2, $this->a3] = Idea::factory()->inStage($this->stageA)->count(3)
        ->sequence(['position' => 0], ['position' => 1], ['position' => 2])->create();
    $this->b1 = Idea::factory()->inStage($this->stageB)->create(['position' => 0]);
    $this->u1 = Idea::factory()->create(['workspace_id' => $this->workspace->id, 'position' => 0]);
});

function moveIdeaTestSnapshot(): array
{
    return Idea::query()->get()->mapWithKeys(fn (Idea $idea) => [$idea->id => [$idea->idea_stage_id, $idea->position]])->all();
}

function moveIdeaTestColumn(?string $stageId): array
{
    return Idea::where('idea_stage_id', $stageId)->orderBy('position')->pluck('id')->all();
}

test('reorders within a column', function () {
    $this->actingAs($this->user)->put(route('app.create.ideas.move', $this->a3), [
        'idea_stage_id' => $this->stageA->id,
        'idea_ids' => [$this->a3->id, $this->a1->id, $this->a2->id],
    ])->assertRedirect();

    expect(moveIdeaTestColumn($this->stageA->id))->toBe([$this->a3->id, $this->a1->id, $this->a2->id])
        ->and($this->a3->fresh()->position)->toBe(0)
        ->and($this->a2->fresh()->position)->toBe(2);
});

test('moves across columns and leaves the source in order', function () {
    $this->actingAs($this->user)->put(route('app.create.ideas.move', $this->a2), [
        'idea_stage_id' => $this->stageB->id,
        'idea_ids' => [$this->b1->id, $this->a2->id],
    ])->assertRedirect();

    expect($this->a2->fresh()->idea_stage_id)->toBe($this->stageB->id)
        ->and($this->a2->fresh()->position)->toBe(1)
        ->and(moveIdeaTestColumn($this->stageA->id))->toBe([$this->a1->id, $this->a3->id]);
});

test('moves into an empty stage', function () {
    $this->actingAs($this->user)->put(route('app.create.ideas.move', $this->a1), [
        'idea_stage_id' => $this->stageC->id,
        'idea_ids' => [$this->a1->id],
    ])->assertRedirect();

    expect($this->a1->fresh()->idea_stage_id)->toBe($this->stageC->id)
        ->and($this->a1->fresh()->position)->toBe(0);
});

test('moves into unassigned', function () {
    $this->actingAs($this->user)->put(route('app.create.ideas.move', $this->a1), [
        'idea_stage_id' => null,
        'idea_ids' => [$this->a1->id, $this->u1->id],
    ])->assertRedirect();

    expect($this->a1->fresh()->idea_stage_id)->toBeNull()
        ->and($this->a1->fresh()->position)->toBe(0)
        ->and($this->u1->fresh()->position)->toBe(1);
});

test('a stale column is rejected and changes nothing', function () {
    $before = moveIdeaTestSnapshot();

    $this->actingAs($this->user)->putJson(route('app.create.ideas.move', $this->a1), [
        'idea_stage_id' => $this->stageB->id,
        'idea_ids' => [$this->a1->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['idea_ids']);

    $foreign = Idea::factory()->create();

    $this->actingAs($this->user)->putJson(route('app.create.ideas.move', $this->a1), [
        'idea_stage_id' => $this->stageB->id,
        'idea_ids' => [$this->b1->id, $this->a1->id, $foreign->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['idea_ids']);

    expect(array_diff_key(moveIdeaTestSnapshot(), [$foreign->id => 1]))->toEqual($before);
});

test('a foreign stage is rejected and a foreign idea is forbidden', function () {
    $foreignStage = IdeaStage::factory()->create();

    $this->actingAs($this->user)->putJson(route('app.create.ideas.move', $this->a1), [
        'idea_stage_id' => $foreignStage->id,
        'idea_ids' => [$this->a1->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['idea_stage_id']);

    $foreignIdea = Idea::factory()->create();

    $this->actingAs($this->user)->putJson(route('app.create.ideas.move', $foreignIdea), [
        'idea_stage_id' => null,
        'idea_ids' => [$foreignIdea->id],
    ])->assertNotFound();
});

test('a user outside the workspace cannot move an idea', function () {
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider->fresh())->putJson(route('app.create.ideas.move', $this->a1), [
        'idea_stage_id' => $this->stageA->id,
        'idea_ids' => [$this->a3->id, $this->a1->id, $this->a2->id],
    ])->assertForbidden();

    expect($this->a1->fresh()->position)->toBe(0);
});

test('placing after an idea keeps the unloaded rest of the column in order', function () {
    $more = Idea::factory()->inStage($this->stageB)->count(12)
        ->sequence(fn ($sequence) => ['position' => $sequence->index + 1])->create();
    $column = [$this->b1->id, ...$more->pluck('id')->all()];

    $this->actingAs($this->user)->put(route('app.create.ideas.move', $this->a2), [
        'idea_stage_id' => $this->stageB->id,
        'after_idea_id' => $more[1]->id,
    ])->assertRedirect();

    expect(moveIdeaTestColumn($this->stageB->id))->toBe([...array_slice($column, 0, 3), $this->a2->id, ...array_slice($column, 3)])
        ->and(Idea::where('idea_stage_id', $this->stageB->id)->orderBy('position')->pluck('position')->all())->toBe(range(0, 13))
        ->and(moveIdeaTestColumn($this->stageA->id))->toBe([$this->a1->id, $this->a3->id]);
});

test('placing with no anchor puts the idea first, also within its own column', function () {
    $this->actingAs($this->user)->put(route('app.create.ideas.move', $this->a3), [
        'idea_stage_id' => $this->stageA->id,
        'after_idea_id' => null,
    ])->assertRedirect();

    expect(moveIdeaTestColumn($this->stageA->id))->toBe([$this->a3->id, $this->a1->id, $this->a2->id]);

    $this->actingAs($this->user)->put(route('app.create.ideas.move', $this->a3), [
        'idea_stage_id' => $this->stageA->id,
        'after_idea_id' => $this->a2->id,
    ])->assertRedirect();

    expect(moveIdeaTestColumn($this->stageA->id))->toBe([$this->a1->id, $this->a2->id, $this->a3->id]);

    $this->actingAs($this->user)->put(route('app.create.ideas.move', $this->a1), [
        'idea_stage_id' => null,
        'after_idea_id' => null,
    ])->assertRedirect();

    expect(moveIdeaTestColumn(null))->toBe([$this->a1->id, $this->u1->id]);
});

test('an anchor outside the target column, another workspace or the idea itself is stale and changes nothing', function () {
    $before = moveIdeaTestSnapshot();
    $foreign = Idea::factory()->create();

    foreach ([$this->a3->id, $foreign->id, $this->a1->id] as $anchor) {
        $this->actingAs($this->user)->putJson(route('app.create.ideas.move', $this->a1), [
            'idea_stage_id' => $this->stageB->id,
            'after_idea_id' => $anchor,
        ])->assertUnprocessable()->assertJsonValidationErrors(['after_idea_id']);
    }

    $this->actingAs($this->user)->putJson(route('app.create.ideas.move', $this->a1), [
        'idea_stage_id' => $this->stageB->id,
        'after_idea_id' => 'nope',
    ])->assertUnprocessable()->assertJsonValidationErrors(['after_idea_id']);

    expect(array_diff_key(moveIdeaTestSnapshot(), [$foreign->id => 1]))->toEqual($before);
});

test('placing after an idea refuses a foreign stage, a foreign idea and an outsider', function () {
    $this->actingAs($this->user)->putJson(route('app.create.ideas.move', $this->a1), [
        'idea_stage_id' => IdeaStage::factory()->create()->id,
        'after_idea_id' => null,
    ])->assertUnprocessable()->assertJsonValidationErrors(['idea_stage_id']);

    $this->actingAs($this->user)->putJson(route('app.create.ideas.move', Idea::factory()->create()), [
        'idea_stage_id' => null,
        'after_idea_id' => null,
    ])->assertNotFound();

    $this->actingAs(workspaceOutsider($this->workspace)->fresh())->putJson(route('app.create.ideas.move', $this->a1), [
        'idea_stage_id' => $this->stageB->id,
        'after_idea_id' => null,
    ])->assertForbidden();

    expect($this->a1->fresh()->idea_stage_id)->toBe($this->stageA->id);
});

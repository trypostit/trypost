<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\Media;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Facades\DB;

test('soft-deleted labels drop out of an idea', function () {
    $idea = Idea::factory()->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $idea->workspace_id]);
    $idea->labels()->attach($label);

    expect($idea->fresh()->labels)->toHaveCount(1);

    $label->delete();

    expect($idea->fresh()->labels)->toHaveCount(0);
});

test('deleting a stage unassigns its ideas at the database level', function () {
    $stage = IdeaStage::factory()->create();
    $idea = Idea::factory()->inStage($stage)->create();

    $stage->delete();

    expect($idea->fresh()->idea_stage_id)->toBeNull();
});

test('deleting the workspace cascades ideas, stages and pivot rows', function () {
    $workspace = Workspace::factory()->create();
    $stage = IdeaStage::factory()->create(['workspace_id' => $workspace->id]);
    $idea = Idea::factory()->inStage($stage)->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $idea->labels()->attach($label);

    $workspace->delete();

    expect(Idea::query()->count())->toBe(0)
        ->and(IdeaStage::query()->count())->toBe(0)
        ->and(DB::table('idea_workspace_label')->count())->toBe(0);
});

test('media round-trips as MediaItem arrays', function () {
    $workspace = Workspace::factory()->create();
    $asset = Media::factory()->temporaryUpload($workspace)->create();
    $expected = [MediaItem::fromMedia($asset)->toArray()];

    $idea = Idea::factory()->withMedia($asset)->create(['workspace_id' => $workspace->id]);

    expect($idea->fresh()->media)->toEqual($expected)
        ->and($idea->fresh()->media_items)->toHaveCount(1);
});

test('models use their morph aliases', function () {
    expect((new Idea)->getMorphClass())->toBe('idea')
        ->and((new IdeaStage)->getMorphClass())->toBe('ideaStage');
});

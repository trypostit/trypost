<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Models\IdeaStage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function ideaStagesBackfillMigration(): object
{
    return require database_path('migrations/2026_09_30_154338_create_idea_stages_table.php');
}

function workspaceAwaitingBackfill(Locale $locale): Workspace
{
    Queue::fake();
    $user = User::factory()->create(['locale' => $locale]);

    return Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
}

test('the backfill seeds three stages in the owner locale for every workspace without stages', function () {
    $english = workspaceAwaitingBackfill(Locale::English);
    $portuguese = workspaceAwaitingBackfill(Locale::PortugueseBrazil);

    ideaStagesBackfillMigration()->backfillDefaultStages();

    $englishStages = $english->ideaStages()->get();

    expect($englishStages->pluck('name')->all())->toBe(['To Do', 'In Progress', 'Done'])
        ->and($englishStages->pluck('position')->all())->toBe([0, 1, 2])
        ->and($englishStages->every(fn (IdeaStage $stage): bool => Str::isUuid($stage->id)))->toBeTrue()
        ->and($englishStages->every(fn (IdeaStage $stage): bool => $stage->created_at !== null))->toBeTrue()
        ->and($portuguese->ideaStages()->pluck('name')->all())->toBe([
            __('create.ideas.default_stages.todo', [], 'pt-BR'),
            __('create.ideas.default_stages.in_progress', [], 'pt-BR'),
            __('create.ideas.default_stages.done', [], 'pt-BR'),
        ]);
});

test('the backfill leaves workspaces that already have stages alone and is safe to run twice', function () {
    $customized = workspaceAwaitingBackfill(Locale::English);
    IdeaStage::factory()->create(['workspace_id' => $customized->id, 'name' => 'Mine', 'position' => 0]);
    $fresh = workspaceAwaitingBackfill(Locale::English);

    ideaStagesBackfillMigration()->backfillDefaultStages();
    ideaStagesBackfillMigration()->backfillDefaultStages();

    expect($customized->ideaStages()->pluck('name')->all())->toBe(['Mine'])
        ->and($fresh->ideaStages()->count())->toBe(3);
});

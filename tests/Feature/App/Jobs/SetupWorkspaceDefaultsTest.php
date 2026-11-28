<?php

declare(strict_types=1);

use App\Actions\Idea\CreateDefaultIdeaStages;
use App\Enums\User\Locale;
use App\Jobs\SetupWorkspaceDefaults;
use App\Models\IdeaStage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

function workspaceWithoutStages(Locale $locale): Workspace
{
    Queue::fake();
    $user = User::factory()->create(['locale' => $locale]);
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    expect($workspace->ideaStages()->count())->toBe(0);

    return $workspace;
}

test('the job creates three default stages in the owner english locale', function () {
    $workspace = workspaceWithoutStages(Locale::English);

    (new SetupWorkspaceDefaults($workspace->id))->handle();

    $stages = $workspace->ideaStages()->get();

    expect($stages->pluck('name')->all())->toBe(['To Do', 'In Progress', 'Done'])
        ->and($stages->pluck('position')->all())->toBe([0, 1, 2]);
});

test('the job translates the stages into the owner locale, not the request locale', function () {
    $workspace = workspaceWithoutStages(Locale::PortugueseBrazil);
    app()->setLocale('en');

    (new SetupWorkspaceDefaults($workspace->id))->handle();

    $expected = [
        __('create.ideas.default_stages.todo', [], 'pt-BR'),
        __('create.ideas.default_stages.in_progress', [], 'pt-BR'),
        __('create.ideas.default_stages.done', [], 'pt-BR'),
    ];

    expect($workspace->ideaStages()->pluck('name')->all())->toBe($expected)
        ->and($expected[0])->not->toBe('To Do');
});

test('running the job twice leaves three stages', function () {
    $workspace = workspaceWithoutStages(Locale::English);

    (new SetupWorkspaceDefaults($workspace->id))->handle();
    (new SetupWorkspaceDefaults($workspace->id))->handle();

    expect($workspace->ideaStages()->count())->toBe(3);
});

test('a workspace that already has a stage is left alone', function () {
    $workspace = workspaceWithoutStages(Locale::English);
    IdeaStage::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Mine', 'position' => 0]);

    (new SetupWorkspaceDefaults($workspace->id))->handle();

    expect($workspace->ideaStages()->pluck('name')->all())->toBe(['Mine']);
});

test('the job for a deleted workspace is a no-op', function () {
    $workspace = workspaceWithoutStages(Locale::English);
    $id = $workspace->id;
    $workspace->delete();

    (new SetupWorkspaceDefaults($id))->handle();

    expect(IdeaStage::query()->count())->toBe(0);
});

test('a newly created workspace gets its stages through the queue', function () {
    $user = User::factory()->create(['locale' => Locale::English]);

    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    expect($workspace->ideaStages()->pluck('position')->all())->toBe([0, 1, 2]);
});

test('the backfill action seeds an existing workspace once', function () {
    $workspace = workspaceWithoutStages(Locale::English);

    CreateDefaultIdeaStages::execute($workspace);
    CreateDefaultIdeaStages::execute($workspace->fresh());

    expect($workspace->ideaStages()->pluck('name')->all())->toBe(['To Do', 'In Progress', 'Done']);
});

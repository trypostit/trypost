<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSignature;

test('signature and label searches and create actions live in the page header', function (
    string $routeName,
    string $title,
    string $searchPlaceholder,
    string $createButtonTestId,
    string $createSheetTestId,
    string $cancelCreateTestId,
) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route($routeName));

    $page
        ->assertSee($title)
        ->assertVisible('header [data-testid="header-title"]')
        ->assertVisible('header [data-testid="header-icon"]')
        ->assertVisible('header [data-testid="header-search-input"]')
        ->assertVisible(sprintf('header input[placeholder="%s"]', $searchPlaceholder))
        ->assertVisible(sprintf('header [data-testid="%s"]', $createButtonTestId))
        ->click('@'.$createButtonTestId)
        ->assertVisible('@'.$createSheetTestId)
        ->click('@'.$cancelCreateTestId)
        ->resize(375, 812)
        ->assertVisible('header [data-testid="header-search-trigger"]')
        ->click('@header-search-trigger')
        ->assertVisible('@header-search-mobile-input')
        ->assertNoJavaScriptErrors();
})->with([
    'signatures' => ['app.signatures.index', 'Signatures', 'Search signatures...', 'create-signature-button', 'create-signature-sheet', 'cancel-create-signature'],
    'labels' => ['app.labels.index', 'Labels', 'Search labels...', 'create-label-button', 'create-label-sheet', 'cancel-create-label'],
]);

test('signature edit dialog uses the shared form and saves changes', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $signature = WorkspaceSignature::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Original signature',
    ]);
    $this->actingAs($user);

    visit(route('app.signatures.index'))
        ->click('button[aria-label="Edit signature"]')
        ->assertVisible('@edit-signature-name')
        ->fill('@edit-signature-name', 'Updated signature')
        ->fill('@edit-signature-content', '#updated')
        ->click('@submit-edit-signature')
        ->assertNoJavaScriptErrors();

    expect($signature->fresh()->name)->toBe('Updated signature')
        ->and($signature->fresh()->content)->toBe('#updated');
});

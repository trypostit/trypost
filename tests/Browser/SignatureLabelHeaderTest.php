<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

test('signature and label headers match the reference layout', function (
    string $routeName,
    string $title,
    string $searchPlaceholder,
    string $createButton,
) {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user);

    visit(route($routeName))
        ->assertSee($title)
        ->assertVisible('@header-title')
        ->assertVisible('@header-icon')
        ->assertVisible(sprintf('input[placeholder="%s"]', $searchPlaceholder))
        ->assertVisible($createButton)
        ->assertNoJavaScriptErrors();
})->with([
    'signatures' => ['app.signatures.index', 'Signatures', 'Search signatures...', '@create-signature-button'],
    'labels' => ['app.labels.index', 'Labels', 'Search labels...', '@create-label-button'],
]);

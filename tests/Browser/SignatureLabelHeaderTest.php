<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;

test('signature and label pages keep the title and create action in the settings column header', function (
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
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    WorkspaceSignature::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user);

    $page = visit(route($routeName));

    $page
        ->assertSee($title)
        ->assertVisible('[data-testid="settings-page"] header [data-testid="header-title"]')
        ->assertVisible(sprintf('[data-testid="settings-page"] header [data-testid="%s"]', $createButtonTestId))
        ->assertVisible('@header-search-input')
        ->assertVisible(sprintf('input[placeholder="%s"]', $searchPlaceholder))
        ->click('@'.$createButtonTestId)
        ->assertVisible('@'.$createSheetTestId)
        ->click('@'.$cancelCreateTestId)
        ->resize(375, 812)
        ->assertVisible('@header-search-input')
        ->assertVisible('@'.$createButtonTestId)
        ->assertNoJavaScriptErrors();
})->with([
    'signatures' => ['app.signatures.index', 'Signatures', 'Search signatures...', 'create-signature-button', 'create-signature-sheet', 'cancel-create-signature'],
    'labels' => ['app.labels.index', 'Labels', 'Search labels...', 'create-label-button', 'create-label-sheet', 'cancel-create-label'],
]);

test('signature edit uses a centered dialog and resets canceled changes', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $signature = WorkspaceSignature::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Original signature',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.signatures.index'));
    $page
        ->click("@signature-row-{$signature->id}")
        ->assertVisible('@edit-signature-sheet')
        ->assertVisible('@edit-signature-name')
        ->fill('@edit-signature-name', 'Unsaved name')
        ->click('@cancel-edit-signature')
        ->assertMissing('@edit-signature-sheet')
        ->click("@signature-row-{$signature->id}")
        ->assertValue('@edit-signature-name', 'Original signature');

    $layout = $page->script(<<<'JS'
        (async () => {
            const sheet = document.querySelector('[data-testid="edit-signature-sheet"]');
            await Promise.all(sheet.getAnimations().map((animation) => animation.finished));
            const rect = sheet.getBoundingClientRect();
            const cancel = sheet.querySelector('[data-testid="cancel-edit-signature"]');
            const save = sheet.querySelector('[data-testid="submit-edit-signature"]');

            return {
                centered: Math.abs(rect.left + rect.width / 2 - window.innerWidth / 2) < 2,
                shorterThanViewport: rect.height < window.innerHeight,
                cancelBeforeSave: cancel.getBoundingClientRect().right <= save.getBoundingClientRect().left,
            };
        })();
    JS);

    expect($layout)
        ->centered->toBeTrue()
        ->shorterThanViewport->toBeTrue()
        ->cancelBeforeSave->toBeTrue();

    $page
        ->fill('@edit-signature-name', 'Updated signature')
        ->fill('@edit-signature-content', '#updated')
        ->click('@submit-edit-signature')
        ->assertNoJavaScriptErrors();

    expect($signature->fresh()->name)->toBe('Updated signature')
        ->and($signature->fresh()->content)->toBe('#updated');
});

test('label and signature deletion use a plain confirmation without typing', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $label = WorkspaceLabel::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Keyword label',
    ]);
    $signature = WorkspaceSignature::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Keyword signature',
    ]);
    $this->actingAs($user);

    visit(route('app.labels.index'))
        ->click("@label-menu-{$label->id}")
        ->click("@delete-label-{$label->id}")
        ->assertVisible('@confirm-delete-modal')
        ->assertMissing('@confirm-delete-input')
        ->click('@confirm-delete-action')
        ->assertMissing('@confirm-delete-modal')
        ->assertNoJavaScriptErrors();

    visit(route('app.signatures.index'))
        ->click("@signature-menu-{$signature->id}")
        ->click("@delete-signature-{$signature->id}")
        ->assertVisible('@confirm-delete-modal')
        ->assertMissing('@confirm-delete-input')
        ->click('@confirm-delete-action')
        ->assertMissing('@confirm-delete-modal')
        ->assertNoJavaScriptErrors();

    expect($label->fresh()->trashed())->toBeTrue()
        ->and($signature->fresh()->trashed())->toBeTrue();
});

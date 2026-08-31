<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;

beforeEach(function () {
    $this->user = User::factory()->create([]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

// Index tests
test('labels index requires authentication', function () {
    $response = $this->get(route('app.labels.index'));

    $response->assertRedirect(route('login'));
});

test('labels index shows labels for workspace', function () {
    WorkspaceLabel::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->get(route('app.labels.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('labels/Index', false)
        ->has('workspace')
        ->has('labels.data', 3)
    );
});

test('labels index redirects if no workspace', function () {
    $this->user->update(['current_workspace_id' => null]);

    $response = $this->actingAs($this->user)->get(route('app.labels.index'));

    $response->assertRedirect(route('app.workspaces.create'));
});

// Store tests
test('store label requires authentication', function () {
    $response = $this->post(route('app.labels.store'), [
        'name' => 'Test Label',
        'color' => '#FF5733',
    ]);

    $response->assertRedirect(route('login'));
});

test('store label creates label', function () {
    $response = $this->actingAs($this->user)->post(route('app.labels.store'), [
        'name' => 'New Label',
        'color' => '#FF5733',
    ]);

    $response->assertRedirect(route('app.labels.index'));

    $this->assertDatabaseHas('workspace_labels', [
        'workspace_id' => $this->workspace->id,
        'name' => 'New Label',
        'color' => '#FF5733',
    ]);
});

test('store label validates required fields', function () {
    $response = $this->actingAs($this->user)->post(route('app.labels.store'), [
        'name' => '',
        'color' => '',
    ]);

    $response->assertSessionHasErrors(['name', 'color']);
});

test('store label validates color format', function () {
    $response = $this->actingAs($this->user)->post(route('app.labels.store'), [
        'name' => 'Valid Name',
        'color' => 'invalid-color',
    ]);

    $response->assertSessionHasErrors('color');
});

// Update tests
test('update label requires authentication', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->put(route('app.labels.update', $label), [
        'name' => 'Updated Label',
        'color' => '#00FF00',
    ]);

    $response->assertRedirect(route('login'));
});

test('update label updates the label', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->put(route('app.labels.update', $label), [
        'name' => 'Updated Label',
        'color' => '#00FF00',
    ]);

    $response->assertRedirect(route('app.labels.index'));

    $label->refresh();
    expect($label->name)->toBe('Updated Label');
    expect($label->color)->toBe('#00FF00');
});

test('update label returns 404 for other workspace label', function () {
    $otherWorkspace = Workspace::factory()->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = $this->actingAs($this->user)->put(route('app.labels.update', $label), [
        'name' => 'Updated Label',
        'color' => '#00FF00',
    ]);

    $response->assertNotFound();
});

// Destroy tests
test('destroy label requires authentication', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->delete(route('app.labels.destroy', $label));

    $response->assertRedirect(route('login'));
});

test('destroy label deletes the label', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->delete(route('app.labels.destroy', $label));

    $response->assertRedirect(route('app.labels.index'));
    expect(WorkspaceLabel::find($label->id))->toBeNull();
});

test('destroy label returns 404 for other workspace label', function () {
    $otherWorkspace = Workspace::factory()->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = $this->actingAs($this->user)->delete(route('app.labels.destroy', $label));

    $response->assertNotFound();
});

test('labels index filters by search query', function () {
    WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Important']);
    WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Urgent']);
    WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Review']);

    $response = $this->actingAs($this->user)->get(route('app.labels.index', ['search' => 'import']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('labels.data', 1)
        ->has('filters')
        ->where('filters.search', 'import')
    );
});

test('labels index search is case insensitive', function () {
    WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'IMPORTANT']);
    WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Urgent']);

    $response = $this->actingAs($this->user)->get(route('app.labels.index', ['search' => 'important']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('labels.data', 1)
        ->where('filters.search', 'important')
    );
});

test('labels index returns all when no search query', function () {
    WorkspaceLabel::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->get(route('app.labels.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('labels.data', 3)
        ->where('filters.search', '')
    );
});

// Member authorization tests
test('member can create label', function () {
    $member = User::factory()->create(['account_id' => $this->workspace->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($member)->post(route('app.labels.store'), [
        'name' => 'Test Label',
        'color' => '#FF0000',
    ]);

    $response->assertRedirect();
    expect($this->workspace->labels()->count())->toBe(1);
});

test('update label validates required fields', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->put(route('app.labels.update', $label), []);

    $response->assertSessionHasErrors(['name', 'color']);
});

test('update label validates color format', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->put(route('app.labels.update', $label), [
        'name' => 'Test',
        'color' => 'invalid',
    ]);

    $response->assertSessionHasErrors('color');
});

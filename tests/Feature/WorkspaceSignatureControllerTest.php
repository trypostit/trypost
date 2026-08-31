<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSignature;

beforeEach(function () {
    $this->user = User::factory()->create([]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

// Index tests
test('signatures index requires authentication', function () {
    $response = $this->get(route('app.signatures.index'));

    $response->assertRedirect(route('login'));
});

test('signatures index shows signatures for workspace', function () {
    WorkspaceSignature::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->get(route('app.signatures.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('signatures/Index', false)
        ->has('workspace')
        ->has('signatures.data', 3)
    );
});

test('signatures index redirects if no workspace', function () {
    $this->user->update(['current_workspace_id' => null]);

    $response = $this->actingAs($this->user)->get(route('app.signatures.index'));

    $response->assertRedirect(route('app.workspaces.create'));
});

// Store tests
test('store signature requires authentication', function () {
    $response = $this->post(route('app.signatures.store'), [
        'name' => 'Marketing',
        'content' => '#marketing #digital #growth',
    ]);

    $response->assertRedirect(route('login'));
});

test('store signature creates signature', function () {
    $response = $this->actingAs($this->user)->post(route('app.signatures.store'), [
        'name' => 'Marketing',
        'content' => '#marketing #digital #growth',
    ]);

    $response->assertRedirect(route('app.signatures.index'));

    $this->assertDatabaseHas('workspace_signatures', [
        'workspace_id' => $this->workspace->id,
        'name' => 'Marketing',
        'content' => '#marketing #digital #growth',
    ]);
});

test('store signature validates required fields', function () {
    $response = $this->actingAs($this->user)->post(route('app.signatures.store'), [
        'name' => '',
        'content' => '',
    ]);

    $response->assertSessionHasErrors(['name', 'content']);
});

// Update tests
test('update signature requires authentication', function () {
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->put(route('app.signatures.update', $signature), [
        'name' => 'Updated Name',
        'content' => '#updated #content',
    ]);

    $response->assertRedirect(route('login'));
});

test('update signature updates the signature', function () {
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->put(route('app.signatures.update', $signature), [
        'name' => 'Updated Name',
        'content' => '#updated #content',
    ]);

    $response->assertRedirect(route('app.signatures.index'));

    $signature->refresh();
    expect($signature->name)->toBe('Updated Name');
    expect($signature->content)->toBe('#updated #content');
});

test('update signature returns 404 for other workspace signature', function () {
    $otherWorkspace = Workspace::factory()->create();
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = $this->actingAs($this->user)->put(route('app.signatures.update', $signature), [
        'name' => 'Updated Name',
        'content' => '#updated #content',
    ]);

    $response->assertNotFound();
});

// Destroy tests
test('destroy signature requires authentication', function () {
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->delete(route('app.signatures.destroy', $signature));

    $response->assertRedirect(route('login'));
});

test('destroy signature deletes the signature', function () {
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->delete(route('app.signatures.destroy', $signature));

    $response->assertRedirect(route('app.signatures.index'));
    expect(WorkspaceSignature::find($signature->id))->toBeNull();
});

test('destroy signature returns 404 for other workspace signature', function () {
    $otherWorkspace = Workspace::factory()->create();
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = $this->actingAs($this->user)->delete(route('app.signatures.destroy', $signature));

    $response->assertNotFound();
});

test('signatures index filters by search query', function () {
    WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Marketing']);
    WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Travel']);
    WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Food']);

    $response = $this->actingAs($this->user)->get(route('app.signatures.index', ['search' => 'market']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('signatures.data', 1)
        ->has('filters')
        ->where('filters.search', 'market')
    );
});

test('signatures index search is case insensitive', function () {
    WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'MARKETING']);
    WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Travel']);

    $response = $this->actingAs($this->user)->get(route('app.signatures.index', ['search' => 'marketing']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('signatures.data', 1)
        ->where('filters.search', 'marketing')
    );
});

test('signatures index returns all when no search query', function () {
    WorkspaceSignature::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->get(route('app.signatures.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('signatures.data', 3)
        ->where('filters.search', '')
    );
});

// Member authorization tests
test('member can create signature', function () {
    $member = User::factory()->create(['account_id' => $this->workspace->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($member)->post(route('app.signatures.store'), [
        'name' => 'Test Signature',
        'content' => '#test #signature',
    ]);

    $response->assertRedirect();
    expect($this->workspace->signatures()->count())->toBe(1);
});

test('update signature validates required fields', function () {
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($this->user)->put(route('app.signatures.update', $signature), []);

    $response->assertSessionHasErrors(['name', 'content']);
});

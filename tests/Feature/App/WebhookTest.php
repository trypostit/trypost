<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Enums\Webhook\EventType;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Services\WebhookService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('ping')->andReturnNull();
    $this->app->instance(WebhookService::class, $mock);
});

test('guests are redirected to the login page', function () {
    $this->get(route('app.webhooks.index'))
        ->assertRedirect(route('login'));
});

test('authenticated users can view webhooks', function () {
    $this->actingAs($this->user)
        ->get(route('app.webhooks.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('webhooks/Index')
            ->has('webhooks')
        );
});

test('authenticated users can create a webhook', function () {
    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'https://example.com/webhooks',
            'events' => [EventType::PostPublished->value, EventType::PostFailed->value],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('webhooks', [
        'workspace_id' => $this->workspace->id,
        'endpoint' => 'https://example.com/webhooks',
    ]);
});

test('webhook signing_secret is generated with whsec_ prefix', function () {
    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'https://example.com/webhooks',
            'events' => [EventType::PostPublished->value],
        ]);

    $webhook = Webhook::query()->where('workspace_id', $this->workspace->id)->first();

    expect($webhook->signing_secret)->toStartWith('whsec_');
});

test('webhook creation fails when ping throws exception', function () {
    $failingMock = Mockery::mock(WebhookService::class);
    $failingMock->shouldReceive('ping')->andThrow(new RuntimeException('Connection refused'));
    $this->app->instance(WebhookService::class, $failingMock);

    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'https://unreachable.example.com/webhooks',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('endpoint');

    $this->assertDatabaseMissing('webhooks', [
        'endpoint' => 'https://unreachable.example.com/webhooks',
    ]);
});

test('re-enabling a paused webhook resets consecutive failures', function () {
    $webhook = Webhook::factory()->paused()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'status' => 'enabled',
        ])
        ->assertRedirect();

    $webhook->refresh();
    expect($webhook->status->value)->toBe('enabled');
    expect($webhook->consecutive_failures)->toBe(0);
    expect($webhook->paused_at)->toBeNull();
});

test('webhook endpoint is required', function () {
    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'events' => [EventType::PostPublished->value],
        ])
        ->assertSessionHasErrors('endpoint');

    expect(session('errors')->first('endpoint'))
        ->toContain(__('webhooks.create.endpoint'));
});

test('webhook endpoint must be a valid url', function () {
    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'not-a-url',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertSessionHasErrors('endpoint');
});

test('webhook events are required', function () {
    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'https://example.com/webhooks',
            'events' => [],
        ])
        ->assertSessionHasErrors('events');
});

test('webhook rejects wildcard events', function () {
    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'https://example.com/webhooks',
            'events' => ['*'],
        ])
        ->assertSessionHasErrors('events.0');
});

test('webhook rejects invalid event names', function () {
    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'https://example.com/webhooks',
            'events' => ['foo.bar'],
        ])
        ->assertSessionHasErrors('events.0');
});

test('authenticated users can view a webhook with signing_secret exposed', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'signing_secret' => 'whsec_test123',
    ]);

    $this->actingAs($this->user)
        ->get(route('app.webhooks.show', $webhook))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('webhooks/Show')
            ->has('webhook')
            ->has('logs')
            ->where('webhook.signing_secret', 'whsec_test123')
        );
});

test('authenticated users can update a webhook endpoint', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'endpoint' => 'https://updated.com/hook',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('webhooks', [
        'id' => $webhook->id,
        'endpoint' => 'https://updated.com/hook',
    ]);
});

test('authenticated users can update webhook events', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [EventType::PostCreated->value],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'events' => [EventType::PostCreated->value, EventType::PostFailed->value],
        ])
        ->assertRedirect();

    $webhook->refresh();
    expect($webhook->events)->toEqual([
        EventType::PostCreated->value,
        EventType::PostFailed->value,
    ]);
});

test('authenticated users can update webhook status', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'status' => 'disabled',
        ])
        ->assertRedirect();

    $webhook->refresh();
    expect($webhook->status->value)->toBe('disabled');
});

test('update webhook rejects invalid status', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'status' => 'invalid',
        ])
        ->assertSessionHasErrors('status');
});

test('update webhook rejects paused status from the user', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'status' => 'paused',
        ])
        ->assertSessionHasErrors('status');
});

test('update webhook rejects wildcard events', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'events' => ['*'],
        ])
        ->assertSessionHasErrors('events.0');
});

test('authenticated users can delete a webhook', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('app.webhooks.destroy', $webhook))
        ->assertRedirect(route('app.webhooks.index'));

    $this->assertDatabaseMissing('webhooks', [
        'id' => $webhook->id,
    ]);
});

test('deleting a webhook also deletes its logs', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('app.webhooks.destroy', $webhook))
        ->assertRedirect();

    $this->assertDatabaseMissing('webhook_logs', [
        'id' => $log->id,
    ]);
});

test('users cannot view webhooks from other workspaces', function () {
    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $webhook = Webhook::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.webhooks.show', $webhook))
        ->assertForbidden();
});

test('users cannot update webhooks from other workspaces', function () {
    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $webhook = Webhook::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'endpoint' => 'https://hacker.com/steal',
        ])
        ->assertForbidden();
});

test('authenticated users can rotate a webhook signing secret', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $originalSecret = $webhook->signing_secret;

    $this->actingAs($this->user)
        ->post(route('app.webhooks.rotate-secret', $webhook))
        ->assertRedirect();

    $webhook->refresh();
    expect($webhook->signing_secret)
        ->not->toBe($originalSecret)
        ->toStartWith('whsec_');
});

test('users cannot rotate signing secret for other workspaces webhooks', function () {
    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $webhook = Webhook::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.webhooks.rotate-secret', $webhook))
        ->assertForbidden();
});

test('users cannot delete webhooks from other workspaces', function () {
    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $webhook = Webhook::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->actingAs($this->user)
        ->delete(route('app.webhooks.destroy', $webhook))
        ->assertForbidden();
});

test('viewers cannot manage webhooks', function () {
    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($viewer)
        ->get(route('app.webhooks.index'))
        ->assertForbidden();
});

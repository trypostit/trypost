<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Enums\Webhook\EventType;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Services\WebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('assertEndpointAllowed')->andReturnNull();
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

test('webhook index hides the signing secret', function () {
    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'signing_secret' => 'whsec_hidden',
    ]);

    $this->actingAs($this->user)
        ->get(route('app.webhooks.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('webhooks', 1, fn ($webhook) => $webhook
                ->missing('signing_secret')
                ->etc()
            )
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

test('generateSigningSecret prefixes a 32 character random string', function () {
    $secret = Webhook::generateSigningSecret();

    expect($secret)
        ->toStartWith('whsec_')
        ->and(strlen($secret))->toBe(38)
        ->and(Webhook::generateSigningSecret())->not->toBe($secret);
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

test('webhook signing secret is encrypted at rest', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'signing_secret' => 'whsec_test123',
    ]);

    $raw = DB::table('webhooks')->where('id', $webhook->id)->value('signing_secret');

    expect($raw)->not->toStartWith('whsec_');
    expect($webhook->signing_secret)->toBe('whsec_test123');
});

test('webhook creation does not ping the endpoint', function () {
    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('assertEndpointAllowed')->once();
    $mock->shouldNotReceive('ping');
    $this->app->instance(WebhookService::class, $mock);

    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'https://example.com/webhooks',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('webhooks', [
        'endpoint' => 'https://example.com/webhooks',
    ]);
});

test('webhook creation fails when the endpoint is not allowed', function () {
    $failingMock = Mockery::mock(WebhookService::class);
    $failingMock->shouldReceive('assertEndpointAllowed')
        ->andThrow(new RuntimeException(__('webhooks.errors.endpoint_not_allowed')));
    $this->app->instance(WebhookService::class, $failingMock);

    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'http://127.0.0.1/webhooks',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('endpoint');

    $this->assertDatabaseMissing('webhooks', [
        'endpoint' => 'http://127.0.0.1/webhooks',
    ]);
});

test('re-enabling a webhook resets consecutive failures', function (string $from) {
    $webhook = $from === 'paused'
        ? Webhook::factory()->paused()->create([
            'workspace_id' => $this->workspace->id,
        ])
        : Webhook::factory()->disabled()->create([
            'workspace_id' => $this->workspace->id,
            'consecutive_failures' => 4,
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
})->with(['paused', 'disabled']);

test('updating an already enabled webhook does not reset consecutive failures', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'consecutive_failures' => 3,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'status' => 'enabled',
        ])
        ->assertRedirect();

    expect($webhook->fresh()->consecutive_failures)->toBe(3);
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

test('webhook accepts the post.unscheduled event', function () {
    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'https://example.com/webhooks',
            'events' => [EventType::PostUnscheduled->value],
        ])
        ->assertRedirect();

    $webhook = Webhook::query()->where('workspace_id', $this->workspace->id)->first();

    expect($webhook->events)->toEqual([EventType::PostUnscheduled->value]);
});

test('webhook rejects invalid event names', function () {
    $this->actingAs($this->user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'https://example.com/webhooks',
            'events' => ['foo.bar'],
        ])
        ->assertSessionHasErrors('events.0');
});

test('webhook show includes full log payload and response body', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
        'payload' => [
            'id' => 'post-1',
            'type' => EventType::PostPublished->value,
            'data' => ['content' => 'Hello'],
        ],
        'response_body' => 'OK',
    ]);

    $this->actingAs($this->user)
        ->get(route('app.webhooks.show', $webhook))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('webhooks/Show')
            ->has('logs.data', 1)
            ->where('logs.data.0.id', $log->id)
            ->where('logs.data.0.payload.id', 'post-1')
            ->where('logs.data.0.payload.data.content', 'Hello')
            ->where('logs.data.0.response_body', 'OK')
        );
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
        ->assertRedirect()
        ->assertSessionHas('flash.banner', __('webhooks.flash.updated'));

    $this->assertDatabaseHas('webhooks', [
        'id' => $webhook->id,
        'endpoint' => 'https://updated.com/hook',
    ]);
});

test('updating a webhook endpoint does not ping the new url', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'endpoint' => 'https://old.example.com/hook',
    ]);

    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('assertEndpointAllowed')->once()->with('https://updated.com/hook');
    $mock->shouldNotReceive('ping');
    $this->app->instance(WebhookService::class, $mock);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'endpoint' => 'https://updated.com/hook',
        ])
        ->assertRedirect();
});

test('updating a webhook endpoint fails when the endpoint is not allowed', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'endpoint' => 'https://old.example.com/hook',
    ]);

    $failingMock = Mockery::mock(WebhookService::class);
    $failingMock->shouldReceive('assertEndpointAllowed')
        ->andThrow(new RuntimeException(__('webhooks.errors.endpoint_not_allowed')));
    $this->app->instance(WebhookService::class, $failingMock);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'endpoint' => 'http://127.0.0.1/webhooks',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('endpoint');

    $webhook->refresh();

    expect($webhook->endpoint)->toBe('https://old.example.com/hook');
});

test('updating a webhook without changing the endpoint does not ping', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'endpoint' => 'https://same.example.com/hook',
    ]);

    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldNotReceive('assertEndpointAllowed');
    $mock->shouldNotReceive('ping');
    $this->app->instance(WebhookService::class, $mock);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'endpoint' => 'https://same.example.com/hook',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertRedirect();
});

test('updating webhook status does not ping the endpoint', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldNotReceive('assertEndpointAllowed');
    $mock->shouldNotReceive('ping');
    $this->app->instance(WebhookService::class, $mock);

    $this->actingAs($this->user)
        ->put(route('app.webhooks.update', $webhook), [
            'status' => 'disabled',
        ])
        ->assertRedirect();

    $webhook->refresh();

    expect($webhook->status->value)->toBe('disabled');
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

test('authenticated users can send a signed test event', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'endpoint' => 'https://example.com/hook',
    ]);

    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('ping')->once()->with($webhook->endpoint, $webhook->signing_secret);
    $this->app->instance(WebhookService::class, $mock);

    $this->actingAs($this->user)
        ->post(route('app.webhooks.send-test', $webhook))
        ->assertRedirect()
        ->assertSessionHas('flash.banner', __('webhooks.flash.tested'));
});

test('sending a test event flashes the error when ping fails', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $failingMock = Mockery::mock(WebhookService::class);
    $failingMock->shouldReceive('ping')->andThrow(new RuntimeException('Connection refused'));
    $this->app->instance(WebhookService::class, $failingMock);

    $this->actingAs($this->user)
        ->post(route('app.webhooks.send-test', $webhook))
        ->assertRedirect()
        ->assertSessionHas('flash.banner', 'Connection refused')
        ->assertSessionHas('flash.bannerStyle', 'danger');
});

test('users cannot send a test event for other workspaces webhooks', function () {
    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $webhook = Webhook::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.webhooks.send-test', $webhook))
        ->assertForbidden();
});

test('authenticated users can rotate a webhook signing secret', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $originalSecret = $webhook->signing_secret;

    $this->actingAs($this->user)
        ->post(route('app.webhooks.rotate-secret', $webhook))
        ->assertRedirect()
        ->assertSessionHas('flash.banner', __('webhooks.flash.secret_rotated'));

    $webhook->refresh();
    expect($webhook->signing_secret)
        ->not->toBe($originalSecret)
        ->toStartWith('whsec_');

    $raw = DB::table('webhooks')->where('id', $webhook->id)->value('signing_secret');

    expect($raw)->not->toStartWith('whsec_');
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

test('workspace admins can manage webhooks', function () {
    $admin = teammateForWebhookWorkspace(Role::Admin);

    $this->actingAs($admin)
        ->get(route('app.webhooks.index'))
        ->assertOk();

    $this->actingAs($admin)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'https://admin.example.com/webhooks',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertRedirect();

    $webhook = Webhook::query()->where('endpoint', 'https://admin.example.com/webhooks')->first();

    expect($webhook)->not->toBeNull();

    $this->actingAs($admin)
        ->get(route('app.webhooks.show', $webhook))
        ->assertOk();

    $this->actingAs($admin)
        ->put(route('app.webhooks.update', $webhook), [
            'status' => 'disabled',
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->post(route('app.webhooks.send-test', $webhook))
        ->assertRedirect();

    $this->actingAs($admin)
        ->post(route('app.webhooks.rotate-secret', $webhook))
        ->assertRedirect();

    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
    ]);

    Queue::fake();

    $this->actingAs($admin)
        ->post(route('app.webhooks.replay', [$webhook, $log]))
        ->assertRedirect();

    $this->actingAs($admin)
        ->delete(route('app.webhooks.destroy', $webhook))
        ->assertRedirect(route('app.webhooks.index'));
});

test('members and viewers cannot manage webhooks', function (Role $role) {
    $user = teammateForWebhookWorkspace($role);
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
    ]);

    $this->actingAs($user)
        ->get(route('app.webhooks.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('app.webhooks.show', $webhook))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('app.webhooks.store'), [
            'endpoint' => 'https://member.example.com/webhooks',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('app.webhooks.update', $webhook), [
            'endpoint' => 'https://stolen.example.com/hook',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('app.webhooks.send-test', $webhook))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('app.webhooks.rotate-secret', $webhook))
        ->assertForbidden();

    $this->actingAs($user)
        ->post(route('app.webhooks.replay', [$webhook, $log]))
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('app.webhooks.destroy', $webhook))
        ->assertForbidden();

    $this->assertDatabaseHas('webhooks', [
        'id' => $webhook->id,
        'endpoint' => $webhook->endpoint,
    ]);
    $this->assertDatabaseMissing('webhooks', [
        'endpoint' => 'https://member.example.com/webhooks',
    ]);
})->with([
    Role::Member,
    Role::Viewer,
]);

function teammateForWebhookWorkspace(Role $role): User
{
    $user = User::factory()->create(['account_id' => test()->user->account_id]);
    test()->workspace->members()->attach($user->id, ['role' => $role->value]);
    $user->update(['current_workspace_id' => test()->workspace->id]);

    return $user->fresh();
}

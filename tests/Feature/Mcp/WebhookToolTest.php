<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Enums\Webhook\EventType;
use App\Enums\Webhook\Status;
use App\Jobs\DispatchWebhook;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Webhook\CreateWebhookTool;
use App\Mcp\Tools\Webhook\DeleteWebhookTool;
use App\Mcp\Tools\Webhook\GetWebhookTool;
use App\Mcp\Tools\Webhook\ListWebhookLogsTool;
use App\Mcp\Tools\Webhook\ListWebhooksTool;
use App\Mcp\Tools\Webhook\ReplayWebhookLogTool;
use App\Mcp\Tools\Webhook\RotateWebhookSecretTool;
use App\Mcp\Tools\Webhook\SendWebhookTestTool;
use App\Mcp\Tools\Webhook\UpdateWebhookTool;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('assertEndpointAllowed')->andReturnNull();
    $mock->shouldReceive('ping')->andReturnNull();
    $this->app->instance(WebhookService::class, $mock);
});

test('list webhooks returns wrapped webhooks without signing secret', function () {
    Webhook::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)
        ->tool(ListWebhooksTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('webhooks', 2, function (AssertableJson $webhook) {
                $webhook->hasAll(['id', 'endpoint', 'events', 'status', 'created_at', 'updated_at'])
                    ->missing('signing_secret')
                    ->missing('workspace_id')
                    ->etc();
            });
        });
});

test('list webhooks only returns own workspace webhooks', function () {
    Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    Webhook::factory()->create();

    TryPostServer::actingAs($this->user)
        ->tool(ListWebhooksTool::class, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('webhooks', 1)->etc());
});

test('get webhook includes the signing secret', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'signing_secret' => 'whsec_visible',
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(GetWebhookTool::class, ['webhook_id' => $webhook->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($webhook) {
            $json->where('id', $webhook->id)
                ->where('signing_secret', 'whsec_visible')
                ->etc();
        });
});

test('cannot get webhook from another workspace', function () {
    $webhook = Webhook::factory()->create();

    TryPostServer::actingAs($this->user)
        ->tool(GetWebhookTool::class, ['webhook_id' => $webhook->id])
        ->assertHasErrors(['Webhook not found.']);
});

test('create webhook returns signing secret', function () {
    TryPostServer::actingAs($this->user)
        ->tool(CreateWebhookTool::class, [
            'endpoint' => 'https://example.com/webhooks',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('endpoint', 'https://example.com/webhooks')
                ->where('status', Status::Enabled->value)
                ->has('signing_secret')
                ->etc();
        });

    expect($this->workspace->webhooks()->count())->toBe(1);
});

test('create webhook validates required fields', function () {
    TryPostServer::actingAs($this->user)
        ->tool(CreateWebhookTool::class, [])
        ->assertHasErrors();
});

test('create webhook rejects wildcard events', function () {
    TryPostServer::actingAs($this->user)
        ->tool(CreateWebhookTool::class, [
            'endpoint' => 'https://example.com/webhooks',
            'events' => ['*'],
        ])
        ->assertHasErrors();
});

test('create webhook does not ping the endpoint', function () {
    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('assertEndpointAllowed')->once();
    $mock->shouldNotReceive('ping');
    $this->app->instance(WebhookService::class, $mock);

    TryPostServer::actingAs($this->user)
        ->tool(CreateWebhookTool::class, [
            'endpoint' => 'https://example.com/webhooks',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertOk();
});

test('create webhook fails when the endpoint is not allowed', function () {
    $failingMock = Mockery::mock(WebhookService::class);
    $failingMock->shouldReceive('assertEndpointAllowed')
        ->andThrow(new RuntimeException(__('webhooks.errors.endpoint_not_allowed')));
    $this->app->instance(WebhookService::class, $failingMock);

    TryPostServer::actingAs($this->user)
        ->tool(CreateWebhookTool::class, [
            'endpoint' => 'http://127.0.0.1/webhooks',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertHasErrors([__('webhooks.errors.endpoint_not_allowed')]);

    $this->assertDatabaseMissing('webhooks', [
        'endpoint' => 'http://127.0.0.1/webhooks',
    ]);
});

test('update webhook', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateWebhookTool::class, [
            'webhook_id' => $webhook->id,
            'status' => Status::Disabled->value,
        ])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('status', Status::Disabled->value)
                ->missing('signing_secret')
                ->etc();
        });
});

test('update webhook cannot set paused status', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateWebhookTool::class, [
            'webhook_id' => $webhook->id,
            'status' => Status::Paused->value,
        ])
        ->assertHasErrors();
});

test('re-enabling a webhook via mcp resets consecutive failures', function () {
    $webhook = Webhook::factory()->paused()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateWebhookTool::class, [
            'webhook_id' => $webhook->id,
            'status' => Status::Enabled->value,
        ])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('status', Status::Enabled->value)
                ->where('consecutive_failures', 0)
                ->where('paused_at', null)
                ->etc();
        });
});

test('updating an already enabled webhook via mcp does not reset consecutive failures', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'consecutive_failures' => 3,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateWebhookTool::class, [
            'webhook_id' => $webhook->id,
            'status' => Status::Enabled->value,
        ])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('consecutive_failures', 3)->etc();
        });
});

test('update webhook rejects wildcard events', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateWebhookTool::class, [
            'webhook_id' => $webhook->id,
            'events' => ['*'],
        ])
        ->assertHasErrors();
});

test('update webhook fails when the endpoint is not allowed', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'endpoint' => 'https://old.example.com/hook',
    ]);

    $failingMock = Mockery::mock(WebhookService::class);
    $failingMock->shouldReceive('assertEndpointAllowed')
        ->andThrow(new RuntimeException(__('webhooks.errors.endpoint_not_allowed')));
    $this->app->instance(WebhookService::class, $failingMock);

    TryPostServer::actingAs($this->user)
        ->tool(UpdateWebhookTool::class, [
            'webhook_id' => $webhook->id,
            'endpoint' => 'http://127.0.0.1/webhooks',
        ])
        ->assertHasErrors([__('webhooks.errors.endpoint_not_allowed')]);

    expect($webhook->fresh()->endpoint)->toBe('https://old.example.com/hook');
});

test('cannot update webhook from another workspace', function () {
    $webhook = Webhook::factory()->create();

    TryPostServer::actingAs($this->user)
        ->tool(UpdateWebhookTool::class, [
            'webhook_id' => $webhook->id,
            'status' => Status::Disabled->value,
        ])
        ->assertHasErrors(['Webhook not found.']);
});

test('send webhook test succeeds', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(SendWebhookTestTool::class, ['webhook_id' => $webhook->id])
        ->assertOk()
        ->assertStructuredContent(['tested' => true]);
});

test('send webhook test failure returns an error', function () {
    $failingMock = Mockery::mock(WebhookService::class);
    $failingMock->shouldReceive('assertEndpointAllowed')->andReturnNull();
    $failingMock->shouldReceive('ping')
        ->andThrow(new RuntimeException('Endpoint unreachable'));
    $this->app->instance(WebhookService::class, $failingMock);

    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(SendWebhookTestTool::class, ['webhook_id' => $webhook->id])
        ->assertHasErrors(['Endpoint unreachable']);
});

test('rotate webhook secret returns a new secret', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'signing_secret' => 'whsec_oldsecretoldsecretoldsecre',
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(RotateWebhookSecretTool::class, ['webhook_id' => $webhook->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('signing_secret')
                ->where('signing_secret', fn (string $secret) => $secret !== 'whsec_oldsecretoldsecretoldsecre' && str_starts_with($secret, 'whsec_'))
                ->etc();
        });
});

test('list webhook logs', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    WebhookLog::factory()->count(2)->create([
        'webhook_id' => $webhook->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(ListWebhookLogsTool::class, ['webhook_id' => $webhook->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->has('logs', 2, function (AssertableJson $log) {
                $log->hasAll(['id', 'event_type', 'payload', 'response_status', 'created_at'])
                    ->etc();
            });
        });
});

test('list webhook logs honors limit', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    WebhookLog::factory()->count(3)->create([
        'webhook_id' => $webhook->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(ListWebhookLogsTool::class, [
            'webhook_id' => $webhook->id,
            'limit' => 1,
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('logs', 1)->etc());
});

test('replay webhook log', function () {
    Queue::fake();

    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
        'event_type' => EventType::PostFailed->value,
        'payload' => [
            'type' => EventType::PostFailed->value,
            'data' => ['id' => 'post-9'],
        ],
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(ReplayWebhookLogTool::class, [
            'webhook_id' => $webhook->id,
            'log_id' => $log->id,
        ])
        ->assertOk()
        ->assertStructuredContent(['replayed' => true]);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($webhook) {
        return $job->webhook->id === $webhook->id
            && $job->eventType === EventType::PostFailed->value
            && data_get($job->payload, 'id') === 'post-9'
            && $job->force;
    });
});

test('cannot replay a log from another webhook', function () {
    Queue::fake();

    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $other = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $log = WebhookLog::factory()->create([
        'webhook_id' => $other->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(ReplayWebhookLogTool::class, [
            'webhook_id' => $webhook->id,
            'log_id' => $log->id,
        ])
        ->assertHasErrors(['Webhook log not found.']);

    Queue::assertNothingPushed();
});

test('delete webhook', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(DeleteWebhookTool::class, ['webhook_id' => $webhook->id])
        ->assertOk()
        ->assertStructuredContent(['deleted' => true]);

    $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
});

test('cannot delete webhook from another workspace', function () {
    $webhook = Webhook::factory()->create();

    TryPostServer::actingAs($this->user)
        ->tool(DeleteWebhookTool::class, ['webhook_id' => $webhook->id])
        ->assertHasErrors(['Webhook not found.']);

    expect($webhook->fresh())->not->toBeNull();
});

test('cannot send test rotate or list logs for a webhook from another workspace', function () {
    $webhook = Webhook::factory()->create();

    TryPostServer::actingAs($this->user)
        ->tool(SendWebhookTestTool::class, ['webhook_id' => $webhook->id])
        ->assertHasErrors(['Webhook not found.']);

    TryPostServer::actingAs($this->user)
        ->tool(RotateWebhookSecretTool::class, ['webhook_id' => $webhook->id])
        ->assertHasErrors(['Webhook not found.']);

    TryPostServer::actingAs($this->user)
        ->tool(ListWebhookLogsTool::class, ['webhook_id' => $webhook->id])
        ->assertHasErrors(['Webhook not found.']);
});

test('members and viewers cannot manage webhooks through mcp', function (Role $role) {
    $teammate = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($teammate->id, ['role' => $role->value]);
    $teammate->update(['current_workspace_id' => $this->workspace->id]);

    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
    ]);

    TryPostServer::actingAs($teammate)
        ->tool(ListWebhooksTool::class, [])
        ->assertHasErrors(['Not authorized to manage webhooks.']);

    TryPostServer::actingAs($teammate)
        ->tool(CreateWebhookTool::class, [
            'endpoint' => 'https://member.example.com/webhooks',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertHasErrors(['Not authorized to manage webhooks.']);

    TryPostServer::actingAs($teammate)
        ->tool(GetWebhookTool::class, ['webhook_id' => $webhook->id])
        ->assertHasErrors(['Not authorized to manage webhooks.']);

    TryPostServer::actingAs($teammate)
        ->tool(UpdateWebhookTool::class, [
            'webhook_id' => $webhook->id,
            'status' => Status::Disabled->value,
        ])
        ->assertHasErrors(['Not authorized to manage webhooks.']);

    TryPostServer::actingAs($teammate)
        ->tool(SendWebhookTestTool::class, ['webhook_id' => $webhook->id])
        ->assertHasErrors(['Not authorized to manage webhooks.']);

    TryPostServer::actingAs($teammate)
        ->tool(RotateWebhookSecretTool::class, ['webhook_id' => $webhook->id])
        ->assertHasErrors(['Not authorized to manage webhooks.']);

    TryPostServer::actingAs($teammate)
        ->tool(ListWebhookLogsTool::class, ['webhook_id' => $webhook->id])
        ->assertHasErrors(['Not authorized to manage webhooks.']);

    TryPostServer::actingAs($teammate)
        ->tool(ReplayWebhookLogTool::class, [
            'webhook_id' => $webhook->id,
            'log_id' => $log->id,
        ])
        ->assertHasErrors(['Not authorized to manage webhooks.']);

    TryPostServer::actingAs($teammate)
        ->tool(DeleteWebhookTool::class, ['webhook_id' => $webhook->id])
        ->assertHasErrors(['Not authorized to manage webhooks.']);

    expect($webhook->fresh())->not->toBeNull();
    $this->assertDatabaseMissing('webhooks', [
        'endpoint' => 'https://member.example.com/webhooks',
    ]);
})->with([
    Role::Member,
    Role::Viewer,
]);

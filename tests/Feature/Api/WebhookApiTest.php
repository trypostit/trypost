<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Enums\Webhook\EventType;
use App\Enums\Webhook\Status;
use App\Jobs\DispatchWebhook;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Services\WebhookService;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];

    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('assertEndpointAllowed')->andReturnNull();
    $mock->shouldReceive('ping')->andReturnNull();
    $this->app->instance(WebhookService::class, $mock);
});

test('list webhooks omits the signing secret', function () {
    Webhook::factory()->count(2)->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->getJson(
        route('api.webhooks.index'),
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertOk();
    $response->assertJsonCount(2);
    expect($response->json('0'))->not->toHaveKey('signing_secret');
});

test('list webhooks does not include other workspace webhooks', function () {
    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'endpoint' => 'https://own.example.com/hook',
    ]);
    Webhook::factory()->create([
        'endpoint' => 'https://other.example.com/hook',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->getJson(
        route('api.webhooks.index'),
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertOk();
    $response->assertJsonCount(1);
    $response->assertJsonPath('0.endpoint', 'https://own.example.com/hook');
});

test('create webhook returns the signing secret', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.store'),
        [
            'endpoint' => 'https://example.com/webhooks',
            'events' => [EventType::PostPublished->value, EventType::PostFailed->value],
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertCreated();
    $response->assertJsonPath('endpoint', 'https://example.com/webhooks');
    $response->assertJsonPath('status', Status::Enabled->value);
    expect($response->json('events'))->toEqual([
        EventType::PostPublished->value,
        EventType::PostFailed->value,
    ]);
    expect($response->json('signing_secret'))->toStartWith('whsec_');
});

test('create webhook validation errors', function () {
    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.store'),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint', 'events']);
});

test('create webhook fails when the endpoint is not allowed', function () {
    $failingMock = Mockery::mock(WebhookService::class);
    $failingMock->shouldReceive('assertEndpointAllowed')
        ->andThrow(new RuntimeException(__('webhooks.errors.endpoint_not_allowed')));
    $this->app->instance(WebhookService::class, $failingMock);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.store'),
        [
            'endpoint' => 'http://127.0.0.1/webhooks',
            'events' => [EventType::PostPublished->value],
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint']);

    $this->assertDatabaseMissing('webhooks', [
        'endpoint' => 'http://127.0.0.1/webhooks',
    ]);
});

test('create webhook does not ping the endpoint', function () {
    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('assertEndpointAllowed')->once();
    $mock->shouldNotReceive('ping');
    $this->app->instance(WebhookService::class, $mock);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.store'),
        [
            'endpoint' => 'https://example.com/webhooks',
            'events' => [EventType::PostPublished->value],
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertCreated();
});

test('create webhook rejects wildcard and invalid events', function (array $events) {
    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.store'),
        [
            'endpoint' => 'https://example.com/webhooks',
            'events' => $events,
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['events.0']);
})->with([
    [['*']],
    [['foo.bar']],
]);

test('show webhook includes the signing secret', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'signing_secret' => 'whsec_visible',
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->getJson(
        route('api.webhooks.show', $webhook),
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertOk()
        ->assertJsonPath('id', $webhook->id)
        ->assertJsonPath('signing_secret', 'whsec_visible');
});

test('update webhook', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->putJson(
        route('api.webhooks.update', $webhook),
        [
            'endpoint' => 'https://hooks.example.com/updated',
            'status' => Status::Disabled->value,
        ],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertOk();
    $response->assertJsonPath('endpoint', 'https://hooks.example.com/updated');
    $response->assertJsonPath('status', Status::Disabled->value);
    expect($response->json())->not->toHaveKey('signing_secret');
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

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->putJson(
        route('api.webhooks.update', $webhook),
        ['endpoint' => 'http://127.0.0.1/webhooks'],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint']);

    expect($webhook->fresh()->endpoint)->toBe('https://old.example.com/hook');
});

test('update webhook does not ping the endpoint', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'endpoint' => 'https://old.example.com/hook',
    ]);

    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('assertEndpointAllowed')->once()->with('https://updated.example.com/hook');
    $mock->shouldNotReceive('ping');
    $this->app->instance(WebhookService::class, $mock);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->putJson(
        route('api.webhooks.update', $webhook),
        ['endpoint' => 'https://updated.example.com/hook'],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertOk();
});

test('update webhook rejects wildcard and invalid events', function (array $events) {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->putJson(
        route('api.webhooks.update', $webhook),
        ['events' => $events],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['events.0']);
})->with([
    [['*']],
    [['foo.bar']],
]);

test('update webhook cannot set paused status', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->putJson(
        route('api.webhooks.update', $webhook),
        ['status' => Status::Paused->value],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('re-enabling a webhook resets consecutive failures', function () {
    $webhook = Webhook::factory()->paused()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->putJson(
        route('api.webhooks.update', $webhook),
        ['status' => Status::Enabled->value],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertOk()
        ->assertJsonPath('status', Status::Enabled->value)
        ->assertJsonPath('consecutive_failures', 0)
        ->assertJsonPath('paused_at', null);
});

test('updating an already enabled webhook does not reset consecutive failures', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'consecutive_failures' => 3,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->putJson(
        route('api.webhooks.update', $webhook),
        ['status' => Status::Enabled->value],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertOk()
        ->assertJsonPath('consecutive_failures', 3);
});

test('send test succeeds', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.send-test', $webhook),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertOk()
        ->assertJsonPath('tested', true);
});

test('send test failure returns 422', function () {
    $failingMock = Mockery::mock(WebhookService::class);
    $failingMock->shouldReceive('assertEndpointAllowed')->andReturnNull();
    $failingMock->shouldReceive('ping')
        ->andThrow(new RuntimeException('Endpoint unreachable'));
    $this->app->instance(WebhookService::class, $failingMock);

    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.send-test', $webhook),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Endpoint unreachable')
        ->assertJsonValidationErrors(['endpoint']);
});

test('rotate secret returns a new signing secret', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'signing_secret' => 'whsec_oldsecretoldsecretoldsecre',
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.rotate-secret', $webhook),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertOk();
    expect($response->json('signing_secret'))
        ->toStartWith('whsec_')
        ->not->toBe('whsec_oldsecretoldsecretoldsecre');
});

test('list logs paginates at 15', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    WebhookLog::factory()->count(16)->create([
        'webhook_id' => $webhook->id,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->getJson(
        route('api.webhooks.logs', $webhook),
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('data.0.event_type', EventType::PostPublished->value);
});

test('replay webhook log', function () {
    Queue::fake();

    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
        'event_type' => EventType::PostPublished->value,
        'payload' => [
            'type' => EventType::PostPublished->value,
            'data' => ['id' => 'post-1'],
        ],
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.replay', [$webhook, $log]),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertOk()
        ->assertJsonPath('replayed', true);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($webhook) {
        return $job->webhook->id === $webhook->id
            && $job->eventType === EventType::PostPublished->value
            && data_get($job->payload, 'id') === 'post-1'
            && $job->force;
    });
});

test('replay of a log from another webhook is not found', function () {
    Queue::fake();

    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $otherWebhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $log = WebhookLog::factory()->create([
        'webhook_id' => $otherWebhook->id,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.replay', [$webhook, $log]),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertNotFound();

    Queue::assertNothingPushed();
});

test('delete webhook', function () {
    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->deleteJson(
        route('api.webhooks.destroy', $webhook),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertStatus(Response::HTTP_NO_CONTENT);

    $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
});

test('webhooks from another workspace are not found', function () {
    $other = Webhook::factory()->create();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->getJson(
        route('api.webhooks.show', $other),
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertNotFound();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->putJson(
        route('api.webhooks.update', $other),
        ['status' => Status::Disabled->value],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertNotFound();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->deleteJson(
        route('api.webhooks.destroy', $other),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertNotFound();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.send-test', $other),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertNotFound();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.rotate-secret', $other),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertNotFound();

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->getJson(
        route('api.webhooks.logs', $other),
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertNotFound();

    $log = WebhookLog::factory()->create([
        'webhook_id' => $other->id,
    ]);

    $this->withHeaders([
        'Authorization' => 'Bearer '.$this->plainToken,
    ])->postJson(
        route('api.webhooks.replay', [$other, $log]),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    )
        ->assertNotFound();
});

test('members and viewers cannot manage webhooks through the api', function (Role $role) {
    $teammate = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($teammate->id, ['role' => $role->value]);
    $teammate->update(['current_workspace_id' => $this->workspace->id]);
    $plainToken = passportToken($teammate, $this->workspace);

    $webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $log = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->getJson(route('api.webhooks.index'))
        ->assertForbidden();

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->postJson(route('api.webhooks.store'), [
            'endpoint' => 'https://member.example.com/webhooks',
            'events' => [EventType::PostPublished->value],
        ])
        ->assertForbidden();

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->getJson(route('api.webhooks.show', $webhook))
        ->assertForbidden();

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->putJson(route('api.webhooks.update', $webhook), [
            'status' => Status::Disabled->value,
        ])
        ->assertForbidden();

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->postJson(route('api.webhooks.send-test', $webhook))
        ->assertForbidden();

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->postJson(route('api.webhooks.rotate-secret', $webhook))
        ->assertForbidden();

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->getJson(route('api.webhooks.logs', $webhook))
        ->assertForbidden();

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->postJson(route('api.webhooks.replay', [$webhook, $log]))
        ->assertForbidden();

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->deleteJson(route('api.webhooks.destroy', $webhook))
        ->assertForbidden();

    $this->assertDatabaseHas('webhooks', ['id' => $webhook->id]);
    $this->assertDatabaseMissing('webhooks', [
        'endpoint' => 'https://member.example.com/webhooks',
    ]);
})->with([
    Role::Member,
    Role::Viewer,
]);

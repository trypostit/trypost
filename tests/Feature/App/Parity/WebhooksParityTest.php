<?php

declare(strict_types=1);

use App\Actions\Webhook\ListWebhookLogs;
use App\Enums\Webhook\EventType;
use App\Enums\Webhook\Status;
use App\Jobs\DispatchWebhook;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\ApiKey\CreateApiKeyTool;
use App\Mcp\Tools\ApiKey\DeleteApiKeyTool;
use App\Mcp\Tools\ApiKey\ListApiKeysTool;
use App\Mcp\Tools\Webhook\CreateWebhookTool;
use App\Mcp\Tools\Webhook\DeleteWebhookTool;
use App\Mcp\Tools\Webhook\GetWebhookTool;
use App\Mcp\Tools\Webhook\ListWebhookLogsTool;
use App\Mcp\Tools\Webhook\ListWebhooksTool;
use App\Mcp\Tools\Webhook\ReplayWebhookLogTool;
use App\Mcp\Tools\Webhook\RotateWebhookSecretTool;
use App\Mcp\Tools\Webhook\SendWebhookTestTool;
use App\Mcp\Tools\Webhook\UpdateWebhookTool;
use App\Models\AccessToken;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Services\WebhookService;
use App\Support\Requests\ApiKey\ApiKeyRequestRules;
use App\Support\Requests\Webhook\WebhookRequestRules;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;

function webhooksParityAllowEverything(): MockInterface
{
    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('assertEndpointAllowed')->andReturnNull();
    app()->instance(WebhookService::class, $mock);

    return $mock;
}

function webhooksParityKeys(object $workspace): array
{
    return AccessToken::query()
        ->where('workspace_id', $workspace->id)
        ->where('name', 'Integration')
        ->get()
        ->map(fn (AccessToken $token): array => [
            'user_id' => $token->user_id,
            'revoked' => $token->revoked,
            'has_expiry' => $token->expires_at !== null,
        ])
        ->all();
}

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

test('a webhook created through the api and mcp stores the same events and refuses the same bad url', function () {
    webhooksParityAllowEverything();
    $events = [EventType::PostPublished->value, EventType::PostFailed->value];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.webhooks.store'), ['endpoint' => 'https://example.com/hook', 'events' => $events])->assertCreated();
    TryPostServer::actingAs($this->user)->tool(CreateWebhookTool::class, ['endpoint' => 'https://example.com/hook', 'events' => $events])->assertOk();

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.webhooks.store'), ['endpoint' => 'not-a-url', 'events' => $events])->assertUnprocessable();
    TryPostServer::actingAs($this->user)->tool(CreateWebhookTool::class, ['endpoint' => 'not-a-url', 'events' => $events])->assertHasErrors();

    $stored = Webhook::query()->where('workspace_id', $this->workspace->id)->get();

    expect($stored)->toHaveCount(2)
        ->and($stored->pluck('events')->all())->toEqual([$events, $events])
        ->and($stored->pluck('status')->unique()->all())->toEqual([Status::Enabled]);
});

test('the web, api and mcp webhook rules refuse the same events and endpoints', function () {
    webhooksParityAllowEverything();

    foreach ([
        ['endpoint' => 'https://example.com/hook', 'events' => []],
        ['endpoint' => 'https://example.com/hook', 'events' => ['post.unknown']],
        ['endpoint' => 'https://example.com/'.str_repeat('a', 260), 'events' => [EventType::PostPublished->value]],
        ['endpoint' => '', 'events' => [EventType::PostPublished->value]],
    ] as $payload) {
        auth()->forgetGuards();
        $this->actingAs($this->user)->postJson(route('app.webhooks.store'), $payload)->assertUnprocessable();
        auth()->forgetGuards();
        $this->withHeaders(parityApi($this->token))->postJson(route('api.webhooks.store'), $payload)->assertUnprocessable();
        TryPostServer::actingAs($this->user)->tool(CreateWebhookTool::class, $payload)->assertHasErrors();
    }

    expect(Webhook::query()->count())->toBe(0);
});

test('a private endpoint is refused on every surface by the same service', function () {
    $payload = ['endpoint' => 'http://127.0.0.1/hook', 'events' => [EventType::PostPublished->value]];

    $this->withHeaders(parityApi($this->token))->postJson(route('api.webhooks.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    TryPostServer::actingAs($this->user)->tool(CreateWebhookTool::class, $payload)->assertHasErrors([__('webhooks.errors.endpoint_not_allowed')]);

    expect(Webhook::query()->count())->toBe(0);
});

test('updating a webhook through the api and mcp stores the same endpoint, events and status', function () {
    webhooksParityAllowEverything();
    $first = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    $second = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    $changes = ['endpoint' => 'https://example.com/changed', 'events' => [EventType::PostDeleted->value], 'status' => Status::Disabled->value];

    $this->withHeaders(parityApi($this->token))->putJson(route('api.webhooks.update', $first), $changes)->assertOk();
    TryPostServer::actingAs($this->user)->tool(UpdateWebhookTool::class, ['webhook_id' => $second->id, ...$changes])->assertOk();

    expect($first->fresh()->only(['endpoint', 'events', 'status']))->toEqual($second->fresh()->only(['endpoint', 'events', 'status']));

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.webhooks.update', $first), ['status' => Status::Paused->value])->assertUnprocessable();
    TryPostServer::actingAs($this->user)->tool(UpdateWebhookTool::class, ['webhook_id' => $second->id, 'status' => Status::Paused->value])->assertHasErrors();
});

test('showing and listing webhooks expose the same fields and only the show call carries the signing secret', function () {
    $webhook = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);

    $apiShow = $this->withHeaders(parityApi($this->token))->getJson(route('api.webhooks.show', $webhook))->assertOk()->json();
    $apiList = $this->withHeaders(parityApi($this->token))->getJson(route('api.webhooks.index'))->assertOk();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetWebhookTool::class, ['webhook_id' => $webhook->id])->assertOk()->assertStructuredContent($apiShow);
    TryPostServer::actingAs($this->user)->tool(ListWebhooksTool::class)->assertOk()->assertStructuredContent(parityMcpPage('webhooks', $apiList));

    expect($apiShow)->toHaveKey('signing_secret')->and($apiList->json('data.0'))->not->toHaveKey('signing_secret');
});

test('rotating the secret through the api and mcp changes it and returns the new one', function () {
    $first = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    $second = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    $firstBefore = $first->signing_secret;
    $secondBefore = $second->signing_secret;

    $apiSecret = $this->withHeaders(parityApi($this->token))->postJson(route('api.webhooks.rotate-secret', $first))->assertOk()->json('signing_secret');
    auth()->forgetGuards();
    $mcp = TryPostServer::actingAs($this->user)->tool(RotateWebhookSecretTool::class, ['webhook_id' => $second->id])->assertOk();

    expect($apiSecret)->not->toBe($firstBefore)->and($apiSecret)->toBe($first->fresh()->signing_secret)
        ->and($second->fresh()->signing_secret)->not->toBe($secondBefore);
    $mcp->assertStructuredContent(fn ($json) => $json->where('signing_secret', $second->fresh()->signing_secret)->etc());
});

test('sending a test ping goes through the same service call on the api and mcp', function () {
    $webhook = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    $mock = Mockery::mock(WebhookService::class);
    $mock->shouldReceive('ping')->twice()->with($webhook->endpoint, $webhook->signing_secret)->andReturnNull();
    app()->instance(WebhookService::class, $mock);

    $this->withHeaders(parityApi($this->token))->postJson(route('api.webhooks.send-test', $webhook))->assertOk()->assertJson(['tested' => true]);
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(SendWebhookTestTool::class, ['webhook_id' => $webhook->id])->assertOk()->assertStructuredContent(['tested' => true]);
});

test('replaying a log dispatches a forced delivery from the api and mcp', function () {
    Queue::fake();
    $webhook = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    $log = WebhookLog::factory()->create(['webhook_id' => $webhook->id]);

    $this->withHeaders(parityApi($this->token))->postJson(route('api.webhooks.replay', [$webhook, $log]))->assertOk()->assertJson(['replayed' => true]);
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(ReplayWebhookLogTool::class, ['webhook_id' => $webhook->id, 'log_id' => $log->id])->assertOk()->assertStructuredContent(['replayed' => true]);

    Queue::assertPushed(DispatchWebhook::class, 2);
    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->force === true);
});

test('a log of another webhook is refused on every surface', function () {
    Queue::fake();
    $webhook = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    $other = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    $log = WebhookLog::factory()->create(['webhook_id' => $other->id]);

    $this->withHeaders(parityApi($this->token))->postJson(route('api.webhooks.replay', [$webhook, $log]))->assertNotFound();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(ReplayWebhookLogTool::class, ['webhook_id' => $webhook->id, 'log_id' => $log->id])->assertHasErrors();

    Queue::assertNothingPushed();
});

test('the api and mcp page webhook logs by the configured size in the same order', function () {
    config()->set('app.pagination.default', 2);
    $webhook = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    WebhookLog::factory()->count(3)->sequence(fn ($sequence) => ['created_at' => now()->subMinutes($sequence->index)])->create(['webhook_id' => $webhook->id]);

    $first = $this->withHeaders(parityApi($this->token))->getJson(route('api.webhooks.logs', [$webhook, 'per_page' => 50]))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.total', 3);
    $second = $this->withHeaders(parityApi($this->token))->getJson(route('api.webhooks.logs', [$webhook, 'page' => 2]))->assertOk()->assertJsonCount(1, 'data');
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListWebhookLogsTool::class, ['webhook_id' => $webhook->id])->assertOk()->assertStructuredContent(parityMcpPage('logs', $first));
    TryPostServer::actingAs($this->user)->tool(ListWebhookLogsTool::class, ['webhook_id' => $webhook->id, 'page' => 2])->assertOk()->assertStructuredContent(parityMcpPage('logs', $second));
    expect([...collect($first->json('data'))->pluck('id'), ...collect($second->json('data'))->pluck('id')])->toBe(ListWebhookLogs::execute($webhook)->pluck('id')->all());
});

test('deleting a webhook through the api and mcp removes it with its logs', function () {
    $first = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    $second = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    WebhookLog::factory()->create(['webhook_id' => $first->id]);
    WebhookLog::factory()->create(['webhook_id' => $second->id]);

    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.webhooks.destroy', $first))->assertNoContent();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(DeleteWebhookTool::class, ['webhook_id' => $second->id])->assertOk();

    expect(Webhook::query()->count())->toBe(0)->and(WebhookLog::query()->count())->toBe(0);
});

test('a webhook of another workspace is refused on the api and mcp', function () {
    $foreign = Webhook::factory()->create();

    $this->withHeaders(parityApi($this->token))->getJson(route('api.webhooks.show', $foreign))->assertNotFound();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(GetWebhookTool::class, ['webhook_id' => $foreign->id])->assertHasErrors();
});

test('a member who is not an admin is refused webhooks on the api and mcp', function () {
    $member = workspaceMember($this->workspace, 'member');
    $memberToken = passportToken($member, $this->workspace);

    $this->withHeaders(parityApi($memberToken))->getJson(route('api.webhooks.index'))->assertStatus(Response::HTTP_FORBIDDEN);
    TryPostServer::actingAs($member)->tool(ListWebhooksTool::class)->assertHasErrors();
});

test('an api key created through the api and mcp is stored the same way and refuses the same past expiry', function () {
    $this->withHeaders(parityApi($this->token))->postJson(route('api.api-keys.store'), ['name' => 'Integration', 'expires_at' => now()->addDays(10)->toDateString()])->assertCreated()->assertJsonStructure(['plain_token', 'token' => ['id', 'name']]);
    TryPostServer::actingAs($this->user)->tool(CreateApiKeyTool::class, ['name' => 'Integration', 'expires_at' => now()->addDays(10)->toDateString()])->assertOk();

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.api-keys.store'), ['name' => 'Integration', 'expires_at' => now()->subDays(2)->toDateString()])->assertUnprocessable();
    TryPostServer::actingAs($this->user)->tool(CreateApiKeyTool::class, ['name' => 'Integration', 'expires_at' => now()->subDays(2)->toDateString()])->assertHasErrors();

    $keys = webhooksParityKeys($this->workspace);

    expect($keys)->toHaveCount(2)->and($keys[0])->toBe($keys[1]);
});

test('the web, api and mcp api key rules refuse a missing name', function () {
    $this->actingAs($this->user)->postJson(route('app.api-keys.store'), ['name' => ''])->assertUnprocessable();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.api-keys.store'), ['name' => ''])->assertUnprocessable();
    TryPostServer::actingAs($this->user)->tool(CreateApiKeyTool::class, ['name' => ''])->assertHasErrors();

    expect(webhooksParityKeys($this->workspace))->toBe([]);
});

test('listing and revoking api keys behaves the same on the api and mcp', function () {
    $first = AccessToken::find($this->user->createToken('First')->token->id);
    $second = AccessToken::find($this->user->createToken('Second')->token->id);
    $first->forceFill(['workspace_id' => $this->workspace->id])->saveQuietly();
    $second->forceFill(['workspace_id' => $this->workspace->id])->saveQuietly();

    $apiList = $this->withHeaders(parityApi($this->token))->getJson(route('api.api-keys.index'))->assertOk();
    auth()->forgetGuards();

    $mcpList = TryPostServer::actingAs($this->user)->tool(ListApiKeysTool::class)->assertOk();
    $mcpList->assertStructuredContent(parityMcpPage('api_keys', $apiList));

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.api-keys.destroy', $first->id))->assertNoContent();
    TryPostServer::actingAs($this->user)->tool(DeleteApiKeyTool::class, ['api_key_id' => $second->id])->assertOk();

    expect($first->fresh()->revoked)->toBeTrue()->and($second->fresh()->revoked)->toBeTrue();

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.api-keys.destroy', $first->id))->assertNoContent();
    TryPostServer::actingAs($this->user)->tool(DeleteApiKeyTool::class, ['api_key_id' => $second->id])->assertHasErrors();
});

test('regenerating an api key exists on the web only', function () {
    expect(Route::has('app.api-keys.regenerate'))->toBeTrue()
        ->and(Route::has('api.api-keys.regenerate'))->toBeFalse();
});

test('the webhook rules are shared by the web, api and mcp and give identical messages', function () {
    webhooksParityAllowEverything();
    $payload = ['endpoint' => 'not-a-url', 'events' => ['post.unknown']];

    $web = $this->actingAs($this->user)->postJson(route('app.webhooks.store'), $payload)->assertUnprocessable()->json('errors');
    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))->postJson(route('api.webhooks.store'), $payload)->assertUnprocessable()->json('errors');

    expect(array_keys($api))->toEqual(['endpoint', 'events.0'])
        ->and(array_keys($web))->toEqual(array_keys($api))
        ->and(WebhookRequestRules::store())->toHaveKeys(['endpoint', 'events', 'events.*'])
        ->and(WebhookRequestRules::update())->toHaveKeys(['endpoint', 'events', 'events.*', 'status']);

    TryPostServer::actingAs($this->user)->tool(CreateWebhookTool::class, $payload)->assertHasErrors([$api['endpoint'][0], $api['events.0'][0]]);
});

test('updating a webhook through mcp reports the same validation message as the api', function () {
    webhooksParityAllowEverything();
    $webhook = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    $payload = ['status' => Status::Paused->value];

    $message = $this->withHeaders(parityApi($this->token))->putJson(route('api.webhooks.update', $webhook), $payload)->assertUnprocessable()->json('errors.status.0');
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(UpdateWebhookTool::class, ['webhook_id' => $webhook->id, ...$payload])->assertHasErrors([$message]);
});

test('the webhook logs list is one query shared by the api and mcp, newest first', function () {
    $webhook = Webhook::factory()->create(['workspace_id' => $this->workspace->id]);
    foreach ([3, 2, 1] as $minutes) {
        WebhookLog::factory()->create(['webhook_id' => $webhook->id, 'created_at' => now()->subMinutes($minutes)]);
    }

    $expected = ListWebhookLogs::execute($webhook)->pluck('id')->all();
    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.webhooks.logs', $webhook))->assertOk()->json('data.*.id');
    auth()->forgetGuards();
    $mcp = TryPostServer::actingAs($this->user)->tool(ListWebhookLogsTool::class, ['webhook_id' => $webhook->id])->assertOk();

    expect($api)->toBe($expected);
    $mcp->assertStructuredContent(fn ($json) => $json->has('logs', 3)->where('logs.0.id', $expected[0])->where('logs.2.id', $expected[2])->etc());
});

test('creating an api key returns the same structure on the api and mcp', function () {
    $api = $this->withHeaders(parityApi($this->token))->postJson(route('api.api-keys.store'), ['name' => 'Integration'])->assertCreated()->json();
    auth()->forgetGuards();
    $mcp = TryPostServer::actingAs($this->user)->tool(CreateApiKeyTool::class, ['name' => 'Integration'])->assertOk();

    expect(array_keys($api))->toEqual(['token', 'plain_token'])
        ->and(ApiKeyRequestRules::store())->toHaveKeys(['name', 'expires_at']);

    $mcp->assertStructuredContent(fn ($json) => $json
        ->has('plain_token')
        ->has('token', fn ($token) => $token->hasAll(array_keys($api['token']))->where('name', 'Integration')->etc())
        ->etc());
});

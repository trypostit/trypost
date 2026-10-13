<?php

declare(strict_types=1);

use App\Enums\PostHog\McpEvent;
use App\Jobs\PostHog\SendEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;
use Laravel\Passport\Events\AccessTokenCreated;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
});

function issueMcpToken(object $test, string $grantType, string $clientName = 'Claude', array $scopes = ['mcp:use']): void
{
    $clientId = mcpOauthClient($clientName);
    $token = mcpAccessToken($test->user, $clientId, $test->workspace, $scopes);

    request()->merge(['grant_type' => $grantType]);
    event(new AccessTokenCreated($token->id, $test->user->id, $clientId));
}

function mcpConnectedEvents(): array
{
    return collect(Bus::dispatched(SendEvent::class))
        ->filter(fn (SendEvent $event): bool => $event->method === 'capture'
            && data_get($event->payload, 'event') === McpEvent::Connected->value)
        ->values()
        ->all();
}

test('an AI app finishing the OAuth authorization records an MCP connection', function (string $clientName, string $client) {
    issueMcpToken($this, 'authorization_code', $clientName);

    $events = mcpConnectedEvents();

    expect($events)->toHaveCount(1)
        ->and(data_get($events[0]->payload, 'distinctId'))->toBe($this->user->id)
        ->and(data_get($events[0]->payload, 'properties'))->toMatchArray([
            'client' => $client,
            'client_name' => $clientName,
            'workspace_id' => $this->workspace->id,
            'account_id' => $this->user->account_id,
        ]);
})->with([
    'Claude' => ['Claude', 'claude'],
    'ChatGPT' => ['ChatGPT', 'chatgpt'],
    'another app' => ['Cursor', 'other'],
]);

test('no MCP connection is recorded', function (Closure $issue) {
    $issue($this);

    expect(mcpConnectedEvents())->toBe([]);
})->with([
    'a refreshed token' => [fn (object $test) => issueMcpToken($test, 'refresh_token')],
    'a token without the MCP scope' => [fn (object $test) => issueMcpToken($test, 'authorization_code', scopes: [])],
    'PostHog is off' => [function (object $test) {
        config(['services.posthog.enabled' => false]);
        issueMcpToken($test, 'authorization_code');
    }],
]);

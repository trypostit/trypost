<?php

declare(strict_types=1);

use App\Enums\PostHog\McpEvent;
use App\Jobs\PostHog\SendEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Passport\AccessTokenRepository;
use App\Passport\AuthCode;
use App\Passport\OAuthPayloadDecryptor;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Events\AccessTokenCreated;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;

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

test('the token repository records the connection when it stores an authorization code token', function () {
    $clientId = mcpOauthClient('Claude');
    $authCodeId = Str::random(80);
    $tokenId = Str::random(80);

    AuthCode::query()->forceCreate([
        'id' => $authCodeId,
        'user_id' => $this->user->id,
        'client_id' => $clientId,
        'workspace_id' => $this->workspace->id,
        'scopes' => '[]',
        'revoked' => true,
        'expires_at' => now()->addMinutes(10),
    ]);

    request()->merge([
        'grant_type' => 'authorization_code',
        'code' => app(OAuthPayloadDecryptor::class)->encrypt([
            'client_id' => $clientId,
            'auth_code_id' => $authCodeId,
            'user_id' => (string) $this->user->id,
            'scopes' => ['mcp:use'],
            'expire_time' => now()->addMinutes(10)->timestamp,
        ]),
    ]);

    $client = Mockery::mock(ClientEntityInterface::class);
    $client->shouldReceive('getIdentifier')->andReturn($clientId);

    $entity = Mockery::mock(AccessTokenEntityInterface::class);
    $entity->shouldReceive('getIdentifier')->andReturn($tokenId);
    $entity->shouldReceive('getUserIdentifier')->andReturn((string) $this->user->id);
    $entity->shouldReceive('getClient')->andReturn($client);
    $entity->shouldReceive('getScopes')->andReturn([new Scope('mcp:use')]);
    $entity->shouldReceive('getExpiryDateTime')->andReturn(now()->addHour()->toDateTimeImmutable());

    app(AccessTokenRepository::class)->persistNewAccessToken($entity);

    $events = mcpConnectedEvents();

    expect($events)->toHaveCount(1)
        ->and(data_get($events[0]->payload, 'properties.client'))->toBe('claude')
        ->and(data_get($events[0]->payload, 'properties.workspace_id'))->toBe($this->workspace->id);
});

test('a failure while recording the connection never breaks the token request', function () {
    $clientId = mcpOauthClient('Claude');
    $token = mcpAccessToken($this->user, $clientId, $this->workspace);
    $this->mock(PostHogService::class)->shouldReceive('capture')->once()->andThrow(new RuntimeException('PostHog is down'));

    request()->merge(['grant_type' => 'authorization_code']);

    expect(fn () => event(new AccessTokenCreated($token->id, $this->user->id, $clientId)))->not->toThrow(Throwable::class);
});

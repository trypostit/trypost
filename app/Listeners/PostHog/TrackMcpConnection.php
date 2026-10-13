<?php

declare(strict_types=1);

namespace App\Listeners\PostHog;

use App\Enums\AccessToken\McpClient;
use App\Enums\PostHog\McpEvent;
use App\Models\AccessToken;
use App\Services\PostHogService;
use Laravel\Passport\Events\AccessTokenCreated;

/**
 * Records an MCP connection when an AI app finishes the OAuth authorization.
 * Refreshed tokens of an existing connection are not new connections.
 */
class TrackMcpConnection
{
    public function __construct(private PostHogService $postHog) {}

    public function handle(AccessTokenCreated $event): void
    {
        if (! PostHogService::isEnabled() || request('grant_type') !== 'authorization_code') {
            return;
        }

        rescue(fn () => $this->capture($event));
    }

    private function capture(AccessTokenCreated $event): void
    {
        $token = AccessToken::query()
            ->mcpOAuth()
            ->with(['client', 'workspace.account'])
            ->find($event->tokenId);

        if (blank($token?->workspace)) {
            return;
        }

        $this->postHog->capture(
            (string) $event->userId,
            McpEvent::Connected->value,
            [
                'client' => McpClient::fromClientName($token->client->name)->value,
                'client_name' => $token->client->name,
                'workspace_id' => $token->workspace_id,
            ],
            $token->workspace->account,
        );
    }
}

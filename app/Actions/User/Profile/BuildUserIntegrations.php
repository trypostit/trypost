<?php

declare(strict_types=1);

namespace App\Actions\User\Profile;

use App\Models\AccessToken;
use App\Models\MediaSourceConnection;
use App\Models\User;
use App\Models\Webhook;

class BuildUserIntegrations
{
    /**
     * How the user connects TryPost to other tools: their API keys, the AI
     * apps connected over MCP, the webhooks of the workspaces they are in, and
     * the media sources (Canva, Google Photos…) they linked.
     *
     * @return array<string, mixed>
     */
    public static function execute(User $user): array
    {
        $workspaceIds = $user->workspaces()->pluck('workspaces.id')->all();

        return [
            'api_keys' => AccessToken::query()
                ->where('user_id', $user->id)
                ->personalAccessApiKey()
                ->with('workspace')
                ->latest()
                ->get()
                ->map(fn (AccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'workspace_name' => $token->workspace?->name,
                    'revoked' => (bool) $token->revoked,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'mcp_apps' => AccessToken::query()
                ->where('user_id', $user->id)
                ->activeMcpOAuth()
                ->with(['client', 'workspace'])
                ->latest()
                ->get()
                ->map(fn (AccessToken $token): array => [
                    'id' => $token->id,
                    'client' => $token->client?->name,
                    'workspace_name' => $token->workspace?->name,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'webhooks' => Webhook::query()
                ->whereIn('workspace_id', $workspaceIds)
                ->with('workspace')
                ->latest()
                ->get()
                ->map(fn (Webhook $webhook): array => [
                    'id' => $webhook->id,
                    'endpoint' => $webhook->endpoint,
                    'events' => collect($webhook->events)->values()->all(),
                    'status' => $webhook->status?->value,
                    'workspace_name' => $webhook->workspace?->name,
                    'consecutive_failures' => (int) $webhook->consecutive_failures,
                    'last_sent_at' => $webhook->last_sent_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'media_sources' => MediaSourceConnection::query()
                ->where('user_id', $user->id)
                ->latest()
                ->get()
                ->map(fn (MediaSourceConnection $connection): array => [
                    'id' => $connection->id,
                    'source' => $connection->source?->value,
                    'connected_at' => $connection->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }
}

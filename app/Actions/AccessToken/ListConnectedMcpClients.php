<?php

declare(strict_types=1);

namespace App\Actions\AccessToken;

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ListConnectedMcpClients
{
    /**
     * The viewer's own MCP OAuth connections for the given workspace, keyed by
     * OAuth client. Matches API keys: each person only sees what they connected
     * for the workspace they are viewing.
     *
     * @return list<array{client_id: string, name: string, can_disconnect: bool, last_used_at: ?CarbonInterface}>
     */
    public static function forUser(User $user, Workspace $workspace): array
    {
        $tokens = AccessToken::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->connectedMcpOAuth()
            ->with(['client', 'user.currentWorkspace', 'workspace', 'refreshToken'])
            ->get();

        return $tokens
            ->filter(fn (AccessToken $token): bool => $token->isListedMcpConnection($user, $workspace))
            ->groupBy('client_id')
            ->map(function (Collection $group): array {
                /** @var AccessToken $token */
                $token = $group->first();

                return [
                    'client_id' => $token->client_id,
                    'name' => $token->client->name,
                    'can_disconnect' => true,
                    'last_used_at' => $group->max('last_used_at'),
                ];
            })
            // Timestamp, not the date object: never-used clients are null.
            ->sortByDesc(fn (array $client): int => $client['last_used_at']?->getTimestamp() ?? 0)
            ->values()
            ->all();
    }
}

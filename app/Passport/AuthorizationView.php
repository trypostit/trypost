<?php

declare(strict_types=1);

namespace App\Passport;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class AuthorizationView
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __invoke(array $parameters): Response
    {
        $user = data_get($parameters, 'user');
        $workspaces = $user instanceof User
            ? $user->accountWorkspaces()->orderBy('name')->get()
            : collect();

        return Inertia::render('mcp/Authorize', [
            'client' => [
                'id' => data_get($parameters, 'client.id'),
                'name' => data_get($parameters, 'client.name'),
            ],
            'user' => [
                'email' => data_get($parameters, 'user.email'),
            ],
            'workspaces' => $workspaces->map->only(['id', 'name'])->values(),
            'selectedWorkspaceId' => $workspaces->firstWhere(
                'id',
                $user instanceof User ? $user->current_workspace_id : null,
            )?->id ?? $workspaces->first()?->id ?? '',
            'scopes' => collect(data_get($parameters, 'scopes', []))->map->toArray()->values(),
            'authToken' => data_get($parameters, 'authToken'),
            'state' => data_get($parameters, 'request.state', ''),
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\AccessToken\ListConnectedMcpClients;
use App\Actions\AccessToken\RevokeMcpOAuthGrants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class McpSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        $this->authorize('view', $workspace);

        return Inertia::render('settings/workspace/Mcp', [
            'mcpUrl' => route('mcp.trypost'),
            'connectedClients' => ListConnectedMcpClients::forUser($user),
        ]);
    }

    public function disconnect(Request $request, string $client): RedirectResponse
    {
        $user = $request->user();

        $this->authorize('view', $user->currentWorkspace);

        if (! RevokeMcpOAuthGrants::forUserClient($user, $client)) {
            return back();
        }

        return back()->with('flash.success', __('mcp.disconnected'));
    }
}

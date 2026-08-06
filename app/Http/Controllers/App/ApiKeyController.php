<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\ApiKey\CreateApiKey;
use App\Http\Requests\App\ApiKey\StoreApiKeyRequest;
use App\Models\AccessToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyController extends Controller
{
    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('manageTeam', $workspace);

        $tokens = AccessToken::where('user_id', $request->user()->id)
            ->where('workspace_id', $workspace->id)
            ->where('revoked', false)
            ->latest()
            ->get()
            ->map(fn (AccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->expires_at,
                'created_at' => $token->created_at,
            ]);

        return Inertia::render('settings/workspace/ApiKeys', [
            'workspace' => $workspace,
            'apiTokens' => $tokens,
        ]);
    }

    public function store(StoreApiKeyRequest $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('manageTeam', $workspace);

        $created = CreateApiKey::execute(
            $request->user(),
            $workspace,
            $request->validated(),
        );

        return back()
            ->with('flash.success', __('settings.api_keys.flash.created'))
            ->with('flash.plainToken', $created['plain_token']);
    }

    public function destroy(Request $request, string $tokenId): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('manageTeam', $workspace);

        $token = AccessToken::where('id', $tokenId)
            ->where('user_id', $request->user()->id)
            ->where('workspace_id', $workspace->id)
            ->first();

        if (! $token) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $token->forceFill(['revoked' => true])->saveQuietly();

        return back()->with('flash.success', __('settings.api_keys.flash.deleted'));
    }
}

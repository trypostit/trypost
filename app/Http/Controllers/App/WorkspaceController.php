<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Workspace\CreateWorkspace;
use App\Actions\Workspace\DeleteWorkspace;
use App\Http\Requests\App\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\App\Workspace\UpdateWorkspaceRequest;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $workspaces = $user->accountWorkspaces()
            ->with('media')
            ->withCount(['socialAccounts', 'posts'])
            ->latest()
            ->get();

        return Inertia::render('workspaces/Index', [
            'workspaces' => $workspaces,
            'currentWorkspaceId' => $user->current_workspace_id,
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $this->authorize('create', Workspace::class);

        $user = $request->user();

        if ($redirect = $this->denyAdditionalWorkspace($user, redirectWhenAtLimit: false)) {
            return $redirect;
        }

        return Inertia::render('workspaces/Create');
    }

    private function denyAdditionalWorkspace(User $user, bool $redirectWhenAtLimit = true): ?RedirectResponse
    {
        // Invitee signup shells must stay empty until accept — otherwise they become billable.
        if (Invite::query()->where('email', $user->email)->whereNull('accepted_at')->exists()) {
            abort(403);
        }

        if (config('trypost.self_hosted') || $user->ownedWorkspacesCount() === 0) {
            return null;
        }

        if (! $user->account?->hasActiveSubscription()) {
            return redirect()->route('app.billing.index')
                ->with('flash.error', __('workspaces.subscription_required'));
        }

        if ($redirectWhenAtLimit && ! $user->account->canCreateWorkspace()) {
            return redirect()->route('app.workspaces.create')
                ->with('flash.error', __('workspaces.limit_reached'));
        }

        return null;
    }

    public function store(StoreWorkspaceRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($redirect = $this->denyAdditionalWorkspace($user)) {
            return $redirect;
        }

        CreateWorkspace::execute($user, $request->validated());

        return redirect()->route('app.workspace.channels')
            ->with('success', __('workspaces.create.success'));
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $user = $request->user();

        $this->authorize('view', $workspace);

        if (! $user->belongsToWorkspace($workspace)) {
            abort(403);
        }

        $user->switchWorkspace($workspace);

        return redirect()->route('app.calendar');
    }

    public function settings(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('update', $workspace);

        return Inertia::render('settings/workspace/Workspace', [
            'workspace' => $workspace,
            'isOnlyWorkspace' => ! config('trypost.self_hosted')
                && $workspace->account->workspaces()->count() <= 1,
            'otherMemberCount' => $workspace->members()
                ->where('users.id', '!=', $user->id)
                ->whereDoesntHave(
                    'workspaces',
                    fn ($query) => $query
                        ->where('workspaces.account_id', $workspace->account_id)
                        ->where('workspaces.id', '!=', $workspace->id),
                )
                ->count(),
        ]);
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('update', $workspace);

        $request->validate([
            'photo' => ['required', 'image', 'max:2048'],
        ]);

        $workspace->clearMediaCollection('logo');
        $workspace->addMedia($request->file('photo'), 'logo');
        $workspace->unsetRelation('media');

        return back()->with('flash.success', __('settings.flash.logo_updated'));
    }

    public function deleteLogo(Request $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('update', $workspace);

        $workspace->clearMediaCollection('logo');
        $workspace->unsetRelation('media');

        return back()->with('flash.success', __('settings.flash.logo_deleted'));
    }

    public function updateSettings(UpdateWorkspaceRequest $request): RedirectResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('update', $workspace);

        $workspace->update($request->validated());

        return back()->with('flash.success', __('settings.flash.workspace_updated'));
    }

    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('delete', $workspace);

        if (! DeleteWorkspace::execute($workspace)) {
            return back()->with('flash.error', __('workspaces.cannot_delete_last'));
        }

        $request->user()->refresh();

        // No current left (self-hosted last delete / no fallback) — go to create
        // so EnsureHasWorkspace cannot bounce and drop the flash.
        if (! $request->user()->current_workspace_id) {
            return redirect()->route('app.workspaces.create')
                ->with('flash.success', __('workspaces.flash.deleted'));
        }

        // Fallback workspace already set — back into the app, not the picker.
        return redirect()->route('app.calendar')
            ->with('flash.success', __('workspaces.flash.deleted'));
    }
}

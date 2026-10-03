<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\AccessToken\RevokeWorkspaceApiKeys;
use App\Actions\Invite\CreateInvite;
use App\Actions\Invite\DeleteInvite;
use App\Actions\Invite\RemoveMember;
use App\Http\Requests\App\Invite\StoreWorkspaceInviteRequest;
use App\Http\Requests\App\Invite\UpdateWorkspaceMemberRequest;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class WorkspaceInviteController extends Controller
{
    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('manageTeam', $workspace);

        return Inertia::render('settings/workspace/Members', [
            'workspace' => $workspace,
            'invites' => $workspace->invites()
                ->latest()
                ->get(),
            'members' => $workspace->members()
                ->get()
                ->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'photo_url' => $member->photo_url,
                    'is_admin' => (bool) $member->pivot->is_admin,
                    'requires_approval' => (bool) $member->pivot->requires_approval,
                ]),
            'owner' => [
                'id' => $workspace->account?->owner?->id,
                'name' => $workspace->account?->owner?->name,
                'email' => $workspace->account?->owner?->email,
            ],
        ]);
    }

    public function store(StoreWorkspaceInviteRequest $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('inviteMember', $workspace);

        $existingInvite = $workspace->invites()
            ->where('email', $request->email)
            ->first();

        if ($existingInvite) {
            return back()->withErrors([
                'email' => __('settings.members.errors.invite_exists'),
            ]);
        }

        // Accounts are closed: a user always belongs to exactly one account.
        // Block invites to an email already registered, or two accounts would
        // hold the same person. Employees use a dedicated work email instead.
        if (User::query()->where('email', $request->email)->exists()) {
            return back()->withErrors([
                'email' => __('settings.members.errors.email_belongs_to_account'),
            ]);
        }

        CreateInvite::execute($workspace, [...$request->validated(), ...$request->memberAccess()]);

        session()->flash('flash.banner', __('settings.members.flash.invite_sent'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function destroy(Request $request, Invite $invite): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('manageTeam', $workspace);

        if ($invite->account_id !== $workspace->account_id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        DeleteInvite::execute($invite);

        session()->flash('flash.banner', __('settings.members.flash.invite_deleted'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function removeMember(Request $request, string $userId): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('manageTeam', $workspace);

        if ($userId === $request->user()->id) {
            return back()->withErrors(['member' => __('settings.members.errors.cannot_remove_self')]);
        }

        if ($userId === $workspace->account?->owner_id) {
            return back()->withErrors(['member' => __('settings.members.errors.cannot_remove_owner')]);
        }

        RemoveMember::execute($workspace, $userId);

        session()->flash('flash.banner', __('settings.members.flash.member_removed'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function updateMember(UpdateWorkspaceMemberRequest $request, string $userId): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        if ($userId === $request->user()->id) {
            return back()->withErrors(['is_admin' => __('settings.members.errors.cannot_change_own_access')]);
        }

        if ($userId === $workspace->account?->owner_id) {
            return back()->withErrors(['is_admin' => __('settings.members.errors.cannot_change_owner_access')]);
        }

        $access = $request->memberAccess();

        $workspace->members()->updateExistingPivot($userId, $access);

        RevokeWorkspaceApiKeys::forUserUnlessAdmin($userId, $workspace, $access['is_admin']);

        return back();
    }
}

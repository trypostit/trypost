<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\AccessToken\RevokeMcpOAuthGrants;
use App\Actions\AccessToken\RevokeWorkspaceApiKeys;
use App\Actions\Invite\CreateInvite;
use App\Actions\Invite\DeleteInvite;
use App\Actions\Invite\RemoveMember;
use App\Enums\UserWorkspace\Role as WorkspaceRole;
use App\Http\Requests\App\Invite\StoreWorkspaceInviteRequest;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
                ->map(fn ($member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->pivot->role,
                ]),
            'owner' => [
                'id' => $workspace->account?->owner?->id,
                'name' => $workspace->account?->owner?->name,
                'email' => $workspace->account?->owner?->email,
            ],
            'roles' => collect(WorkspaceRole::cases())
                ->map(fn ($role) => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ])->values(),
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

        CreateInvite::execute($workspace, $request->validated());

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

        // You cannot remove yourself
        if ($userId === $request->user()->id) {
            return back()->withErrors(['member' => 'You cannot remove yourself.']);
        }

        // Account owner cannot be removed
        if ($userId === $workspace->account?->owner_id) {
            return back()->withErrors(['member' => 'Cannot remove the account owner.']);
        }

        RemoveMember::execute($workspace, $userId);

        session()->flash('flash.banner', __('settings.members.flash.member_removed'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function updateRole(Request $request, string $userId): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('manageTeam', $workspace);

        // You cannot change your own role
        if ($userId === $request->user()->id) {
            return back()->withErrors(['role' => 'You cannot change your own role.']);
        }

        // Account owner's role cannot be changed
        if ($userId === $workspace->account?->owner_id) {
            return back()->withErrors(['role' => 'Cannot change the account owner role.']);
        }

        $validated = $request->validate([
            'role' => ['required', Rule::in(array_column(WorkspaceRole::cases(), 'value'))],
        ]);
        $role = WorkspaceRole::from((string) data_get($validated, 'role'));

        $workspace->members()->updateExistingPivot($userId, [
            'role' => $role->value,
        ]);

        RevokeWorkspaceApiKeys::forUserUnlessAdmin($userId, $workspace, $role);

        // Viewers cannot use MCP on this workspace. Only revoke account-scoped
        // OAuth grants when they also lack createPost everywhere else.
        if ($role === WorkspaceRole::Viewer) {
            $member = User::query()->find($userId);

            if ($member instanceof User) {
                RevokeMcpOAuthGrants::forUserIfLacksCreatePost($member->fresh());
            }
        }

        session()->flash('flash.banner', __('settings.members.flash.role_updated'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }
}

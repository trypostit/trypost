<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AcceptInviteController extends Controller
{
    /**
     * Display the invite view.
     */
    public function show(Invite $invite): Response
    {
        $invite->load('account');

        $firstWorkspaceId = collect($invite->workspaces ?? [])->first();
        $workspace = $firstWorkspaceId ? Workspace::find($firstWorkspaceId) : null;

        $role = $invite->role;

        return Inertia::render('auth/AcceptInvite', [
            'invite' => [
                'id' => $invite->id,
                'email' => $invite->email,
                'account' => [
                    'id' => $invite->account->id,
                    'name' => $invite->account->name,
                ],
                'workspace' => $workspace ? [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                ] : null,
                'role' => [
                    'value' => $role->value,
                    'label' => $role->label(),
                ],
            ],
        ]);
    }

    /**
     * Accept the invite.
     */
    public function accept(Request $request, Invite $invite): RedirectResponse
    {
        $user = $request->user();

        // Verify the invite is for this user
        if ($invite->email !== $user->email) {
            session()->flash('flash.banner', __('settings.members.flash.wrong_email'));
            session()->flash('flash.bannerStyle', 'danger');

            return redirect()->route('app.calendar');
        }

        $workspaces = $this->resolvableInviteWorkspaces($invite);

        if ($workspaces->isEmpty()) {
            $invite->delete();

            session()->flash('flash.banner', __('settings.members.flash.invite_workspace_gone'));
            session()->flash('flash.bannerStyle', 'danger');

            return redirect()->route('app.calendar');
        }

        // Already on the account: still attach any missing workspace memberships.
        if ($user->account_id === $invite->account_id) {
            $this->attachInviteWorkspaces($user, $invite, $workspaces);

            $invite->update(['accepted_at' => now()]);

            session()->flash('flash.banner', __('settings.members.flash.already_member'));
            session()->flash('flash.bannerStyle', 'info');

            return redirect()->route('app.calendar');
        }

        $user->update(['account_id' => $invite->account_id]);

        $this->attachInviteWorkspaces($user, $invite, $workspaces);

        $invite->update(['accepted_at' => now()]);

        session()->flash('flash.banner', __('settings.members.flash.invite_accepted'));
        session()->flash('flash.bannerStyle', 'success');

        return redirect()->route('app.calendar');
    }

    /**
     * Decline the invite.
     */
    public function decline(Request $request, Invite $invite): RedirectResponse
    {
        $user = $request->user();

        // Verify the invite is for this user
        if ($invite->email !== $user->email) {
            session()->flash('flash.banner', __('settings.members.flash.wrong_email'));
            session()->flash('flash.bannerStyle', 'danger');

            return redirect()->route('app.calendar');
        }

        $invite->delete();

        session()->flash('flash.banner', __('settings.members.flash.invite_declined'));
        session()->flash('flash.bannerStyle', 'info');

        return redirect()->route('app.calendar');
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function resolvableInviteWorkspaces(Invite $invite): Collection
    {
        return collect($invite->workspaces ?? [])
            ->map(fn (mixed $workspaceId): ?Workspace => Workspace::query()->find($workspaceId))
            ->filter(fn (?Workspace $workspace): bool => $workspace !== null
                && $workspace->account_id === $invite->account_id)
            ->values();
    }

    /**
     * @param  Collection<int, Workspace>  $workspaces
     */
    private function attachInviteWorkspaces(User $user, Invite $invite, Collection $workspaces): void
    {
        foreach ($workspaces as $workspace) {
            $workspace->members()->syncWithoutDetaching([
                $user->id => ['role' => $invite->role->value],
            ]);

            if (! $user->current_workspace_id) {
                $user->update(['current_workspace_id' => $workspace->id]);
                $user->refresh();
            }
        }
    }
}

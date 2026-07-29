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
use Illuminate\Support\Facades\DB;
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

        $workspaces = $this->resolvableInviteWorkspaces($invite);
        $expired = $workspaces->isEmpty();

        if ($expired) {
            if ($invite->exists) {
                $invite->delete();
            }

            return Inertia::render('auth/AcceptInvite', [
                'expired' => true,
                'invite' => null,
            ]);
        }

        $workspace = $workspaces->first();
        $role = $invite->role;

        return Inertia::render('auth/AcceptInvite', [
            'expired' => false,
            'invite' => [
                'id' => $invite->id,
                'email' => $invite->email,
                'account' => [
                    'id' => $invite->account->id,
                    'name' => $invite->account->name,
                ],
                'workspace' => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                ],
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

            return $this->redirectAfterInvite($user);
        }

        $outcome = DB::transaction(function () use ($user, $invite): string {
            $lockedInvite = Invite::query()
                ->whereKey($invite->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedInvite || $lockedInvite->accepted_at !== null) {
                return 'gone';
            }

            $workspaces = $this->resolvableInviteWorkspaces($lockedInvite);

            if ($workspaces->isEmpty()) {
                $lockedInvite->delete();

                return 'gone';
            }

            // Already on the account: still attach any missing workspace memberships.
            if ($user->account_id === $lockedInvite->account_id) {
                $this->attachInviteWorkspaces($user, $lockedInvite, $workspaces);

                $lockedInvite->update(['accepted_at' => now()]);

                return 'already';
            }

            $user->update(['account_id' => $lockedInvite->account_id]);
            $user->refresh();

            $this->attachInviteWorkspaces($user, $lockedInvite, $workspaces);

            $lockedInvite->update(['accepted_at' => now()]);

            return 'accepted';
        });

        if ($outcome === 'gone') {
            session()->flash('flash.banner', __('settings.members.flash.invite_workspace_gone'));
            session()->flash('flash.bannerStyle', 'danger');

            return $this->redirectAfterInvite($user->fresh());
        }

        if ($outcome === 'already') {
            session()->flash('flash.banner', __('settings.members.flash.already_member'));
            session()->flash('flash.bannerStyle', 'info');

            return $this->redirectAfterInvite($user->fresh());
        }

        session()->flash('flash.banner', __('settings.members.flash.invite_accepted'));
        session()->flash('flash.bannerStyle', 'success');

        return $this->redirectAfterInvite($user->fresh());
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

            return $this->redirectAfterInvite($user);
        }

        $invite->delete();

        session()->flash('flash.banner', __('settings.members.flash.invite_declined'));
        session()->flash('flash.bannerStyle', 'info');

        return $this->redirectAfterInvite($user);
    }

    /**
     * Avoid bouncing through calendar (EnsureAccountReady / EnsureHasWorkspace)
     * when the user has no current workspace — that drop flashed messages.
     */
    private function redirectAfterInvite(User $user): RedirectResponse
    {
        if ($user->current_workspace_id) {
            return redirect()->route('app.calendar');
        }

        return redirect()->route('app.workspaces.create');
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
            $alreadyMember = $workspace->members()
                ->where('users.id', $user->id)
                ->exists();

            // Never overwrite an existing pivot role (avoids demoting admins).
            if (! $alreadyMember) {
                $workspace->members()->attach($user->id, [
                    'role' => $invite->role->value,
                ]);
            }

            if (! $user->current_workspace_id) {
                $user->update(['current_workspace_id' => $workspace->id]);
                $user->refresh();
            }
        }
    }
}

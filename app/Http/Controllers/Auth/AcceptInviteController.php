<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\User\EnsurePersonalAccount;
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
            // Non-mutating for crawler/email prefetch: cleanup happens on
            // workspace delete and on accept/decline of a dead invite.
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

            if (! $lockedInvite) {
                return 'gone';
            }

            if ($lockedInvite->accepted_at !== null) {
                return 'already_accepted';
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

        return match ($outcome) {
            'gone' => $this->flashAndRedirect(
                $user,
                __('settings.members.flash.invite_workspace_gone'),
                'danger',
            ),
            'already_accepted', 'already' => $this->flashAndRedirect(
                $user,
                __('settings.members.flash.already_member'),
                'info',
            ),
            default => $this->flashAndRedirect(
                $user,
                __('settings.members.flash.invite_accepted'),
                'success',
            ),
        };
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

        $workspaces = $this->resolvableInviteWorkspaces($invite);

        $invite->delete();

        if ($workspaces->isEmpty()) {
            session()->flash('flash.banner', __('settings.members.flash.invite_workspace_gone'));
            session()->flash('flash.bannerStyle', 'danger');

            return $this->redirectAfterInvite($user);
        }

        session()->flash('flash.banner', __('settings.members.flash.invite_declined'));
        session()->flash('flash.bannerStyle', 'info');

        return $this->redirectAfterInvite($user);
    }

    private function flashAndRedirect(User $user, string $banner, string $style): RedirectResponse
    {
        session()->flash('flash.banner', $banner);
        session()->flash('flash.bannerStyle', $style);

        return $this->redirectAfterInvite($user->fresh() ?? $user);
    }

    /**
     * Avoid bouncing through calendar (EnsureAccountReady / EnsureHasWorkspace)
     * when the user has no current workspace — that drops flashed messages.
     *
     * Only treat current as valid when it belongs to the user's current account
     * (accept may have just switched account_id while leaving an old current).
     */
    private function redirectAfterInvite(User $user): RedirectResponse
    {
        $user->refresh();

        if ($this->currentWorkspaceBelongsToAccount($user)) {
            return redirect()->route('app.calendar');
        }

        $fallback = $user->workspaces()
            ->where('workspaces.account_id', $user->account_id)
            ->first()
            ?? $user->workspaces()->first();

        if ($fallback) {
            $user->update(['current_workspace_id' => $fallback->id]);

            return redirect()->route('app.calendar');
        }

        if (! $user->isAccountOwner()) {
            EnsurePersonalAccount::execute($user);
            $user->refresh();
        }

        return redirect()->route('app.workspaces.create');
    }

    private function currentWorkspaceBelongsToAccount(User $user): bool
    {
        if (! $user->current_workspace_id || ! $user->account_id) {
            return false;
        }

        return $user->workspaces()
            ->where('workspaces.id', $user->current_workspace_id)
            ->where('workspaces.account_id', $user->account_id)
            ->exists();
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

            // Accept often switches account_id while an old personal workspace is
            // still current — always land on a workspace of the invite account.
            if (! $this->currentWorkspaceBelongsToAccount($user)) {
                $user->update(['current_workspace_id' => $workspace->id]);
                $user->refresh();
            }
        }
    }
}

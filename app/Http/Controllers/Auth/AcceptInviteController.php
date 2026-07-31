<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Invite\AcceptInvite;
use App\Actions\Invite\DeclineInvite;
use App\Actions\Invite\RedirectAfterInvite;
use App\Actions\Invite\ResolveInviteWorkspaces;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $workspaces = ResolveInviteWorkspaces::execute($invite);
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

        return match (AcceptInvite::execute($user, $invite)) {
            'wrong_email' => RedirectAfterInvite::flashAndRedirect(
                $user,
                __('settings.members.flash.wrong_email'),
                'danger',
            ),
            'gone' => RedirectAfterInvite::flashAndRedirect(
                $user,
                __('settings.members.flash.invite_workspace_gone'),
                'danger',
            ),
            'already_accepted', 'already' => RedirectAfterInvite::flashAndRedirect(
                $user,
                __('settings.members.flash.already_member'),
                'info',
            ),
            default => RedirectAfterInvite::flashAndRedirect(
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

        return match (DeclineInvite::execute($user, $invite)) {
            'wrong_email' => RedirectAfterInvite::flashAndRedirect(
                $user,
                __('settings.members.flash.wrong_email'),
                'danger',
            ),
            'gone' => RedirectAfterInvite::flashAndRedirect(
                $user,
                __('settings.members.flash.invite_workspace_gone'),
                'danger',
            ),
            default => RedirectAfterInvite::flashAndRedirect(
                $user,
                __('settings.members.flash.invite_declined'),
                'info',
            ),
        };
    }
}

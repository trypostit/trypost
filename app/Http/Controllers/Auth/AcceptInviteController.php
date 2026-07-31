<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Invite\AcceptInvite;
use App\Actions\Invite\DeclineInvite;
use App\Actions\Invite\ResolveInviteWorkspaces;
use App\Actions\Invite\SettleAfterInvite;
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
        $result = AcceptInvite::execute($request->user(), $invite);

        return SettleAfterInvite::flashAndRedirect(
            $request->user(),
            $result->flashBanner(),
            $result->flashStyle(),
        );
    }

    /**
     * Decline the invite.
     */
    public function decline(Request $request, Invite $invite): RedirectResponse
    {
        $result = DeclineInvite::execute($request->user(), $invite);

        return SettleAfterInvite::flashAndRedirect(
            $request->user(),
            $result->flashBanner(),
            $result->flashStyle(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Actions\Account\DeleteEmptyOwnedAccounts;
use App\Actions\Auth\LogoutAndInvalidateSession;
use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Actions\User\SettleStrandedMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class SettleAfterInvite
{
    /**
     * After accept/decline: clear stranded non-owners, pick a safe same-account
     * current workspace, and redirect without bouncing through calendar
     * middleware that would drop flash messages.
     *
     * Never point current_workspace at a membership on another account
     * (WorkspacePolicy requires account_id match).
     */
    public static function execute(User $user): RedirectResponse
    {
        $user->refresh();

        if (AttachInviteWorkspaces::currentWorkspaceBelongsToAccount($user)) {
            return redirect()->route('app.calendar');
        }

        $hasSameAccountWorkspace = $user->workspaces()
            ->where('workspaces.account_id', $user->account_id)
            ->exists();

        if (! $hasSameAccountWorkspace && ! $user->isAccountOwner() && $user->account) {
            $userId = $user->id;
            $leavingAccount = $user->account;
            $banner = session('flash.banner');
            $bannerStyle = session('flash.bannerStyle');

            // Logout first while the row still exists. SessionGuard cycles the
            // remember token via save() — if we delete first on the Auth user
            // instance, logout re-inserts them.
            if (Auth::id() === $userId) {
                LogoutAndInvalidateSession::execute(request(), [
                    'banner' => $banner,
                    'bannerStyle' => $bannerStyle,
                ]);
            }

            $stranded = User::query()->find($userId);

            if ($stranded) {
                $settled = SettleStrandedMember::execute($stranded, $leavingAccount);
                DeleteEmptyOwnedAccounts::executeByIds($settled['empty_account_ids']);
                DeleteOrphanedMediaFiles::execute($settled['media_paths']);
            }

            $user = User::query()->find($userId);

            if (! $user) {
                return redirect()->route('login');
            }
        }

        $fallback = $user->workspaces()
            ->where('workspaces.account_id', $user->account_id)
            ->first();

        if ($fallback) {
            $user->update(['current_workspace_id' => $fallback->id]);

            return redirect()->route('app.calendar');
        }

        return redirect()->route('app.workspaces.create');
    }

    public static function flashAndRedirect(User $user, string $banner, string $style): RedirectResponse
    {
        session()->flash('flash.banner', $banner);
        session()->flash('flash.bannerStyle', $style);

        return self::execute($user->fresh() ?? $user);
    }
}

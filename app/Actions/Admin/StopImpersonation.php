<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Admin\AdminActionType;
use App\Models\User;
use App\Support\Admin\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StopImpersonation
{
    public function __construct(private readonly RecordAdminAction $recordAdminAction) {}

    /**
     * Sign the admin back in. It works even if the admin left `ADMIN_EMAILS`
     * meanwhile: they get their own account back, not the backoffice. When
     * the admin's account is gone, nobody stays signed in as the customer:
     * the session ends.
     *
     * @return User|null the admin, or null when their account no longer exists
     */
    public function execute(Request $request, User $impersonated): ?User
    {
        $admin = User::query()->find(Impersonation::impersonatorId());

        $request->session()->forget(Impersonation::SESSION_KEY);

        if (! $admin instanceof User) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return null;
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        $this->recordAdminAction->execute($admin, AdminActionType::StopImpersonation, $impersonated);

        return $admin;
    }
}

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
     * meanwhile: they get their own account back, not the backoffice.
     *
     * @return User|null the admin, or null when the session holds no admin
     */
    public function execute(Request $request, User $impersonated): ?User
    {
        $admin = User::query()->find(Impersonation::impersonatorId());

        $request->session()->forget(Impersonation::SESSION_KEY);

        if (! $admin instanceof User) {
            return null;
        }

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        $this->recordAdminAction->execute($admin, AdminActionType::StopImpersonation, $impersonated);

        return $admin;
    }
}

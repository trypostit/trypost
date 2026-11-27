<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Admin\AdminActionType;
use App\Models\User;
use App\Support\Admin\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class StartImpersonation
{
    public function __construct(private readonly RecordAdminAction $recordAdminAction) {}

    /**
     * Sign the admin in as the user, without "remember me", keeping the
     * admin's id in the session so they can come back.
     */
    public function execute(Request $request, User $admin, User $user): void
    {
        $this->recordAdminAction->execute($admin, AdminActionType::StartImpersonation, $user);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(Impersonation::SESSION_KEY, $admin->id);
    }
}

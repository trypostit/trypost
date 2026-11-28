<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\User\StartImpersonation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\ImpersonateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class ImpersonationController extends Controller
{
    public function store(ImpersonateUserRequest $request, User $user, StartImpersonation $startImpersonation): RedirectResponse
    {
        $startImpersonation->execute($request, $request->user(), $user);

        return redirect()->route('app.calendar');
    }
}

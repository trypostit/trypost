<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\User\BlockUser;
use App\Actions\User\UnblockUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\BlockUserRequest;
use App\Http\Requests\Admin\User\UnblockUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class UserBlockController extends Controller
{
    public function store(BlockUserRequest $request, User $user, BlockUser $blockUser): RedirectResponse
    {
        $blockUser->execute($request->user(), $user, $request->reason());

        return back();
    }

    public function destroy(UnblockUserRequest $request, User $user, UnblockUser $unblockUser): RedirectResponse
    {
        $unblockUser->execute($request->user(), $user);

        return back();
    }
}

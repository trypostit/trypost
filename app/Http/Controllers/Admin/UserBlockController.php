<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\BlockUser;
use App\Actions\Admin\UnblockUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\BlockUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UserBlockController extends Controller
{
    public function store(BlockUserRequest $request, User $user, BlockUser $blockUser): RedirectResponse
    {
        $blockUser->execute($request->user(), $user, $request->string('reason')->trim()->toString());

        return back();
    }

    public function destroy(Request $request, User $user, UnblockUser $unblockUser): RedirectResponse
    {
        $unblockUser->execute($request->user(), $user);

        return back();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Settings;

use App\Actions\Auth\LogoutOtherSessions;
use App\Http\Controllers\App\Controller;
use App\Http\Requests\App\Settings\DestroyOtherSessionsRequest;
use Illuminate\Http\RedirectResponse;

class SessionController extends Controller
{
    public function destroyOthers(DestroyOtherSessionsRequest $request): RedirectResponse
    {
        LogoutOtherSessions::execute($request->user(), $request->session()->getId());

        return back()->with('flash.success', __('settings.profile.sessions.flash_logged_out'));
    }
}

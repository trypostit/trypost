<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Settings;

use App\Http\Controllers\App\Controller;
use App\Http\Requests\App\Settings\DestroyOtherSessionsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function destroyOthers(DestroyOtherSessionsRequest $request): RedirectResponse
    {
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with('flash.success', __('settings.profile.sessions.flash_logged_out'));
    }
}

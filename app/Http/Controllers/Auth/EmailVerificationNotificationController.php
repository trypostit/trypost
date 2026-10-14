<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('app.calendar'));
        }

        $request->user()->sendEmailVerificationNotification();

        return back()
            ->with('status', 'verification-link-sent')
            ->with('flash.success', __('settings.profile.verification_sent'));
    }
}

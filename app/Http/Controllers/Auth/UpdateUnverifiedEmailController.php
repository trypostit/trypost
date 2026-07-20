<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\NotDisposableEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Email;

class UpdateUnverifiedEmailController extends Controller
{
    /**
     * Fix the email before verifying: a user who mistypes their address at
     * signup is stuck (the account exists, the link never arrives). This only
     * works while the email is unverified; a verified account changes its email
     * through the settings flow, with re-authentication.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('app.calendar'));
        }

        $rules = [
            'email' => [
                'required', 'string', 'lowercase', Email::default(), 'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
        ];

        if (config('trypost.security.block_disposable_emails')) {
            $rules['email'][] = new NotDisposableEmail;
        }

        $validated = $request->validate($rules);

        if ($validated['email'] !== $user->email) {
            $user->forceFill(['email' => $validated['email']])->save();
        }

        $user->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    }
}

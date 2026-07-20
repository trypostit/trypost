<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class VerifyEmailController extends Controller
{
    /**
     * Confirm the email from the signed link and double as a magic link: the
     * signed URL proves ownership of the email, so it also authenticates a user
     * who arrives logged out (email opened in another browser or device).
     */
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        abort_unless(Str::isUuid($id), 404);

        $user = User::findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if (! $request->user()?->is($user)) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return redirect()->intended(route('app.calendar').'?verified=1');
    }
}

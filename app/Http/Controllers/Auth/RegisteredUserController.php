<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\User\CreateUser;
use App\Http\Controllers\Auth\Concerns\PreservesUtmParameters;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    use PreservesUtmParameters;

    public function create(Request $request): Response
    {
        $this->storeUtmParameters($request);

        return Inertia::render('auth/Register', [
            'email' => $request->query('email'),
            'redirect' => $request->query('redirect'),
            'invite' => $request->query('invite'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', Rules\Password::defaults()],
        ]);

        // An invite binds registration to the invited email — never a different
        // one. Only a valid invite id enforces this; the param can also be a
        // self-hosted gate placeholder, which must not trigger a UUID lookup.
        $inviteId = (string) $request->input('invite', '');
        $invite = null;

        if ($inviteId !== '' && Str::isUuid($inviteId)) {
            $invite = Invite::query()->find($inviteId);

            abort_if(! $invite || $invite->email !== $request->email, 403);
        }

        $isInviteRegistration = $invite !== null
            || str_contains($request->input('redirect', ''), '/invites/');

        $utmParameters = $this->retrieveUtmParameters();

        $user = CreateUser::execute([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'is_invite' => $isInviteRegistration,
            'registration_ip' => $request->ip(),
        ], $utmParameters);

        event(new Registered($user));

        Auth::login($user);

        $request->session()->forget('pending_invite_id');

        if ($redirect = $request->input('redirect')) {
            if (str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
                return redirect($redirect);
            }
        }

        session()->flash('auth_provider', 'email');

        return redirect()->route('register.success', $utmParameters);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\User\CreateUser;
use App\Http\Controllers\Auth\Concerns\PreservesUtmParameters;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Auth\RegisterRequest;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    public function store(RegisterRequest $request): RedirectResponse
    {
        $utmParameters = $this->retrieveUtmParameters();

        $user = CreateUser::execute([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'is_invite' => $request->isInviteRegistration(),
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\User\CreateUser;
use App\Http\Controllers\Auth\Concerns\PreservesAttributionParameters;
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
    use PreservesAttributionParameters;

    public function create(Request $request): Response
    {
        $this->storeAttributionParameters($request);

        return Inertia::render('auth/Register', [
            'email' => $request->query('email'),
            'invite' => $request->query('invite'),
        ]);
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $attributionParameters = $this->retrieveAttributionParameters();
        $invite = $request->invite();

        $user = CreateUser::execute([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'is_invite' => $invite !== null,
            'registration_ip' => $request->ip(),
        ], $attributionParameters);

        event(new Registered($user));

        Auth::login($user);

        $request->session()->forget('pending_invite_id');

        if ($invite) {
            return redirect()->route('app.invites.show', $invite);
        }

        return redirect()->route('app.welcome');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\User\CreateUser;
use App\Http\Controllers\Auth\Concerns\PreservesUtmParameters;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\NotDisposableEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Illuminate\Validation\Rules\Email;
use Illuminate\Validation\ValidationException;
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
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Honeypot: a hidden field only bots fill in. The front-end clears it on
        // autofill, so a non-empty value here means an automated request. Reply
        // with a "success" redirect so the bot isn't told it was detected.
        if ($request->filled('contact_time')) {
            Log::info('Registration honeypot triggered', ['ip' => $request->ip()]);

            return redirect()->route('login');
        }

        $emailRules = ['required', 'string', 'lowercase', Email::default(), 'max:255', 'unique:'.User::class];

        if (config('trypost.security.block_disposable_emails')) {
            $emailRules[] = new NotDisposableEmail;
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => $emailRules,
            'password' => ['required', Rules\Password::defaults()],
        ]);

        $this->ensureIpRegistrationQuota($request);

        $isInviteRegistration = str_contains($request->input('redirect', ''), '/invites/');

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

    /**
     * A free trial hands out AI credits, so N accounts from the same IP in one
     * day is the classic farming pattern. The error is intentionally generic on
     * the email field: it doesn't confirm to the attacker which limit was hit.
     */
    private function ensureIpRegistrationQuota(Request $request): void
    {
        $limit = (int) config('trypost.security.max_registrations_per_ip_per_day', 0);

        if ($limit <= 0) {
            return;
        }

        $recent = User::query()
            ->where('registration_ip', $request->ip())
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recent >= $limit) {
            Log::warning('Registration per-IP quota reached', ['ip' => $request->ip(), 'count' => $recent]);

            throw ValidationException::withMessages([
                'email' => __('auth.register.quota_reached'),
            ]);
        }
    }
}

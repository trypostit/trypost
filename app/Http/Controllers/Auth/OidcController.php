<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\JoinOidcUserToAccount;
use App\Actions\Auth\ReleaseAccountOwnership;
use App\Actions\Auth\SyncOidcWorkspaceRole;
use App\Actions\User\CreateUser;
use App\Actions\Workspace\CreateWorkspace;
use App\Enums\Auth\SocialAuthProvider;
use App\Enums\UserWorkspace\Role as WorkspaceRole;
use App\Http\Controllers\Auth\Concerns\PreservesAttributionParameters;
use App\Http\Controllers\Auth\Concerns\PreservesInvite;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\User;
use App\Socialite\OidcProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * Sign-in through a generic OpenID Connect provider. Mirrors GoogleController;
 * the ID token is kept in the session so logging out of TryPost can also end
 * the session at the identity provider.
 */
class OidcController extends Controller
{
    use PreservesAttributionParameters, PreservesInvite;

    /** Session key holding the ID token used as `id_token_hint` on logout. */
    public const ID_TOKEN_SESSION_KEY = 'oidc.id_token';

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(SocialAuthProvider::Oidc->isEnabled(), 404);

        $this->storeAttributionParameters($request);
        $this->storeInvite($request);

        return Socialite::driver('oidc')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(SocialAuthProvider::Oidc->isEnabled(), 404);

        $driver = Socialite::driver('oidc');

        try {
            $oidcUser = $driver->user();
        } catch (\Exception $e) {
            // Misconfigured providers are painful to debug from a generic
            // error page, so keep the reason in the log.
            Log::warning('OIDC login failed: '.$e->getMessage());

            return redirect()->route('login')->withErrors([
                'email' => __('auth.oidc_failed'),
            ]);
        }

        if (! $this->groupsAllow($oidcUser)) {
            return redirect()->route('login')->withErrors([
                'email' => __('auth.oidc_group_denied'),
            ]);
        }

        if (blank($oidcUser->getEmail())) {
            return redirect()->route('login')->withErrors([
                'email' => __('auth.oidc_email_missing'),
            ]);
        }

        if ($driver instanceof OidcProvider && filled($idToken = $driver->idToken())) {
            $request->session()->put(self::ID_TOKEN_SESSION_KEY, $idToken);
        }

        // `guest` middleware gates login/signup; `auth` gates the settings connect flow.
        if (Auth::check()) {
            return $this->connectToCurrentUser(Auth::user(), $oidcUser->getId());
        }

        $user = User::where('oidc_id', $oidcUser->getId())->first();

        if (! $user) {
            // Matching on the email address is how someone with a local account
            // moves over to SSO. An address the provider has not verified must
            // not be able to do that, or anyone able to sign up there with
            // someone else's address could walk into their account. Signing in
            // is still fine - it just creates a separate account.
            $byEmail = User::where('email', $oidcUser->getEmail())->first();

            if ($byEmail && data_get($oidcUser->getRaw(), 'email_verified') === false) {
                return redirect()->route('login')->withErrors([
                    'email' => __('auth.oidc_email_unverified'),
                ]);
            }

            $user = $byEmail;
        }

        if ($user) {
            return $this->loginExistingUser($user, $oidcUser->getId(), $this->groupsOf($oidcUser));
        }

        return $this->registerNewUser($oidcUser, $this->groupsOf($oidcUser));
    }

    private function connectToCurrentUser(User $user, string $oidcId): RedirectResponse
    {
        $existing = User::where('oidc_id', $oidcId)
            ->where('id', '!=', $user->id)
            ->first();

        if ($existing) {
            return redirect()->route('app.authentication.edit')
                ->with('flash.error', __('settings.authentication.providers.flash_already_linked', ['provider' => SocialAuthProvider::Oidc->label()]));
        }

        if ($user->oidc_id !== $oidcId) {
            $user->update(['oidc_id' => $oidcId]);
        }

        return redirect()->route('app.authentication.edit')
            ->with('flash.success', __('settings.authentication.providers.flash_connected', ['provider' => SocialAuthProvider::Oidc->label()]));
    }

    /**
     * @param  array<int, string>  $groups
     */
    private function loginExistingUser(User $user, string $oidcId, array $groups = []): RedirectResponse
    {
        if (! $user->oidc_id) {
            $user->update(['oidc_id' => $oidcId]);
        }

        // Roles follow the provider on every sign-in, so revoking admin there
        // takes effect here without anyone touching the application.
        ReleaseAccountOwnership::execute($user);
        SyncOidcWorkspaceRole::execute($user->fresh(), $groups);

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Auth::login($user, remember: true);

        // New session id on privilege change, so a session id planted before
        // login cannot be reused afterwards.
        request()->session()->regenerate();

        $this->retrieveAttributionParameters();

        if ($invite = Invite::fromId($this->retrieveInvite())) {
            return redirect()->route('app.invites.show', $invite);
        }

        return redirect()->route('app.home');
    }

    /**
     * Group names as reported by the provider.
     *
     * @return array<int, string>
     */
    private function groupsOf(\Laravel\Socialite\Contracts\User $oidcUser): array
    {
        $claim = (string) config('trypost.oidc_groups_claim', 'groups');
        $value = data_get($oidcUser->getRaw(), $claim, []);

        // Most providers send an array, some a single space- or
        // comma-separated string. Both have to work, or group handling
        // silently does nothing on half the providers out there.
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_map('strval', array_filter((array) $value, 'is_scalar')));
    }

    /**
     * Whether the provider reported a group that is allowed to sign in. With no
     * allow-list configured, anyone the provider lets through is welcome - the
     * provider is then the only gate, which is the usual setup.
     */
    private function groupsAllow(\Laravel\Socialite\Contracts\User $oidcUser): bool
    {
        $allowed = array_filter(array_map(
            'trim',
            explode(',', (string) config('trypost.oidc_allowed_groups'))
        ));

        if ($allowed === []) {
            return true;
        }

        return array_intersect($this->groupsOf($oidcUser), $allowed) !== [];
    }

    /**
     * Auto-join places users on a shared account, which only ever makes sense
     * on a single-team install - hence the self-hosted guard, so a misplaced
     * flag cannot drop strangers into someone else's account.
     */
    private function autoJoinEnabled(): bool
    {
        return (bool) config('trypost.oidc_auto_join_enabled')
            && (bool) config('trypost.self_hosted');
    }

    /**
     * @param  array<int, string>  $groups
     */
    private function registerNewUser(\Laravel\Socialite\Contracts\User $oidcUser, array $groups = []): RedirectResponse
    {
        // With auto-join on, provider group membership replaces the invite, so
        // a missing invite must not be a hard stop.
        $invite = $this->autoJoinEnabled()
            ? Invite::fromId($this->retrieveInvite())
            : $this->resolveInviteForRegistration();

        if ($redirect = $this->inviteEmailMismatchRedirect($invite, $oidcUser->getEmail())) {
            return $redirect;
        }

        $attributionParameters = $this->retrieveAttributionParameters();

        $user = CreateUser::execute([
            'name' => $oidcUser->getName() ?: $oidcUser->getNickname(),
            'email' => $oidcUser->getEmail(),
            'oidc_id' => $oidcUser->getId(),
            'email_verified_at' => now(),
            // Both paths join an existing account, so neither wants the
            // personal workspace a plain signup would create.
            'is_invite' => $invite !== null || $this->autoJoinEnabled(),
            'registration_ip' => request()->ip(),
        ], $attributionParameters);

        event(new Registered($user));

        Auth::login($user, remember: true);

        request()->session()->regenerate();

        if ($invite) {
            return redirect()->route('app.invites.show', $invite);
        }

        if ($this->autoJoinEnabled()) {
            $role = WorkspaceRole::tryFrom((string) config('trypost.oidc_auto_join_role')) ?? WorkspaceRole::Member;

            if (JoinOidcUserToAccount::execute($user, $role)) {
                SyncOidcWorkspaceRole::execute($user->fresh(), $groups);

                return redirect()->route('app.home');
            }

            // Nothing to join yet: this is the first user on a fresh instance,
            // and the account they were just given has no workspace because
            // auto-join suppressed it. Give them the one an ordinary signup
            // would have created, or they land in an application with nowhere
            // to work - and nobody can ever join them either.
            CreateWorkspace::execute($user, ['name' => $user->name."'s Workspace"]);
            ReleaseAccountOwnership::execute($user);
        }

        return redirect()->route('app.welcome');
    }
}

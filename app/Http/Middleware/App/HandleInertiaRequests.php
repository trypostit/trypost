<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\PostPlatform\ContentType;
use App\Http\Resources\App\HandleInertiaRequests\AuthAccountResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthPlanResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthUserResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthWorkspaceResource;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\DeferProp;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        $currentWorkspace = $user?->currentWorkspace?->load('media');
        $account = $user?->account;
        $isSelfHosted = (bool) config('trypost.self_hosted');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user ? AuthUserResource::make($user) : null,
                'currentWorkspace' => $currentWorkspace ? AuthWorkspaceResource::make($currentWorkspace, $user) : null,
                'workspaces' => $user
                    ? $user->workspaces()->with('media')->get()->map(fn ($ws) => AuthWorkspaceResource::summary($ws))
                    : [],
                'account' => $account ? AuthAccountResource::make($account) : null,
                'plan' => $account && $account->plan ? AuthPlanResource::make($account, $account->plan) : null,
                'hasActiveSubscription' => $account ? $account->hasActiveSubscription() : false,
                'subscriptionPastDue' => $account ? $account->isPastDue() : false,
            ],
            'usage' => $account && ! $isSelfHosted ? $account->usage() : null,
            'features' => $account && ! $isSelfHosted ? $account->featureLimits() : null,
            'onboardingProgress' => $this->onboardingProgress($request, $user),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => $request->session()->get('flash', []),
            'applicationUrl' => config('app.url'),
            'env' => config('app.env'),
            'locale' => app()->getLocale(),
            'languages' => collect(config('languages.available'))->map(fn ($name, $code) => [
                'code' => $code,
                'name' => $name,
            ])->values()->all(),
            'aiEnabled' => filled(config('ai.providers.'.config('ai.default').'.key')),
            'selfHosted' => $isSelfHosted,
            'googleAuthEnabled' => config('trypost.google_auth_enabled'),
            'githubAuthEnabled' => config('trypost.github_auth_enabled'),
        ];
    }

    /**
     * @return array<string, callable>
     */
    public function shareOnce(Request $request): array
    {
        return [
            ...parent::shareOnce($request),
            'contentTypeMediaRules' => fn (): array => ContentType::mediaRulesForFrontend(),
        ];
    }

    /**
     * Defer step queries for mid-activation owners; everyone else gets false inline.
     *
     * Never defer on Passport consent *views*: Inertia deferred props re-request the
     * same URL, Passport rotates `authToken` on every authorize hit, and approve then
     * fails with InvalidAuthTokenException against the stale token still on the page.
     *
     * Social OAuth popup close pages set `onboardingProgress` to false in
     * `SocialController::popupCallback()` so a deferred reload does not re-hit the
     * select route after the connect session was cleared.
     */
    private function onboardingProgress(Request $request, ?User $user): DeferProp|false
    {
        if ($this->isPassportConsentViewRequest($request)) {
            return false;
        }

        $onboarding = app(ResolveOnboardingStatus::class);

        return $user && $onboarding->canShowProgress($user)
            ? Inertia::defer(fn (): array|false => $onboarding->sidebarProgress($user))
            : false;
    }

    /**
     * Exact GET consent-view route names only — not approve/deny, and not wildcards
     * like passport.authorizations.* (those would suppress defer on POST approve too,
     * which is unnecessary and easy to misread as "all OAuth").
     */
    private function isPassportConsentViewRequest(Request $request): bool
    {
        return $request->routeIs(
            'passport.authorizations.authorize',
            'passport.device.authorizations.authorize',
        );
    }
}

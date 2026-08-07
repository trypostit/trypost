<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\PostPlatform\ContentType;
use App\Http\Resources\App\HandleInertiaRequests\AuthAccountResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthPlanResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthUserResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthWorkspaceResource;
use Illuminate\Http\Request;
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
            'onboardingResidual' => fn (): array|false => $user
                ? app(ResolveOnboardingStatus::class)->residual($user)
                : false,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'flash' => $request->session()->get('flash', []),
            'applicationUrl' => config('app.url'),
            'env' => config('app.env'),
            'locale' => app()->getLocale(),
            'languages' => collect(config('languages.available'))->map(fn ($name, $code) => [
                'code' => $code,
                'name' => $name,
            ])->values()->all(),
            'aiEnabled' => ! empty(config('services.gemini.api_key')) || ! empty(config('services.openai.api_key')),
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
}

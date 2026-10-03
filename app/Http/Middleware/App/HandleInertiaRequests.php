<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Enums\Auth\SocialAuthProvider;
use App\Enums\Media\Source as MediaSource;
use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\User\Locale;
use App\Http\Resources\App\HandleInertiaRequests\AuthAccountResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthPlanResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthUserResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthWorkspaceResource;
use App\Http\Resources\App\HandleInertiaRequests\SidebarChannelResource;
use App\Http\Resources\App\PlanResource;
use App\Models\Plan;
use App\Support\HeicConverter;
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
            'channels' => fn (): array => $currentWorkspace
                ? SidebarChannelResource::collection($currentWorkspace)
                : [],
            'mediaSources' => fn (): ?array => $user !== null ? ['menu' => MediaSource::menu()] : null,
            'mediaUploadLimits' => fn (): ?array => $user !== null ? [
                'max_bytes' => [
                    MediaType::Image->value => MediaType::Image->maxSizeInBytes(),
                    MediaType::Video->value => MediaType::Video->maxSizeInBytes(),
                    MediaType::Document->value => MediaType::Document->maxSizeInBytes(),
                ],
                'extensions' => [
                    MediaType::Image->value => MediaType::Image->extensions(),
                    MediaType::Video->value => MediaType::Video->extensions(),
                    MediaType::Document->value => MediaType::Document->extensions(),
                ],
                'upload_retention_hours' => (int) config('trypost.media.upload_retention_hours'),
                'heic' => HeicConverter::available(),
            ] : null,
            'legal' => [
                'terms' => (string) config('trypost.legal.terms_url'),
                'privacy' => (string) config('trypost.legal.privacy_url'),
            ],
            'usage' => $account && ! $isSelfHosted ? $account->usage() : null,
            'features' => $account && ! $isSelfHosted ? $account->featureLimits() : null,
            'flash' => $request->session()->get('flash', []),
            'applicationUrl' => config('app.url'),
            'env' => config('app.env'),
            'locale' => app()->getLocale(),
            'languages' => Locale::options(),
            'aiEnabled' => filled(config('ai.providers.'.config('ai.default').'.key')),
            'selfHosted' => $isSelfHosted,
            'googleAuthEnabled' => SocialAuthProvider::Google->isEnabled(),
            'githubAuthEnabled' => SocialAuthProvider::GitHub->isEnabled(),
        ];
    }

    /**
     * @return array<string, callable>
     */
    public function shareOnce(Request $request): array
    {
        return [
            ...parent::shareOnce($request),
            'connectablePlatforms' => fn (): array => SocialPlatform::connectableOptions(),
            'contentTypeMediaRules' => fn (): array => ContentType::mediaRulesForFrontend(),
            'defaultCropPresets' => fn (): array => ContentType::defaultCropPresets(),
            'plans' => function (): array {
                if (config('trypost.self_hosted') || auth()->user()?->account === null) {
                    return [];
                }

                return PlanResource::collection(
                    Plan::active()->orderBy('sort')->get()
                )->resolve();
            },
        ];
    }
}

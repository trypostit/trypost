<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Enums\Auth\SocialAuthProvider;
use App\Enums\Media\Source as MediaSource;
use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\User\Locale;
use App\Enums\User\ReferralSource;
use App\Http\Resources\App\HandleInertiaRequests\AuthAccountResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthPlanResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthUserResource;
use App\Http\Resources\App\HandleInertiaRequests\AuthWorkspaceResource;
use App\Http\Resources\App\HandleInertiaRequests\ComposerResource;
use App\Http\Resources\App\HandleInertiaRequests\SidebarChannelResource;
use App\Http\Resources\App\PlanResource;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Support\HeicConverter;
use App\Support\LinkTlds;
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
        $account = $user?->account;
        $isSelfHosted = (bool) config('trypost.self_hosted');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => fn (): array => [
                'user' => $user ? AuthUserResource::make($user) : null,
                'currentWorkspace' => $user?->currentWorkspace
                    ? AuthWorkspaceResource::make($user->currentWorkspace->loadMissing('media'), $user)
                    : null,
                'workspaces' => $user
                    ? $user->workspaces()->with('media')->get()->map(fn (Workspace $workspace): array => AuthWorkspaceResource::summary($workspace))
                    : [],
                'account' => $account ? AuthAccountResource::make($account) : null,
                'plan' => $account && $account->plan ? AuthPlanResource::make($account, $account->plan) : null,
                'hasActiveSubscription' => $account ? $account->hasActiveSubscription() : false,
                'subscriptionPastDue' => $account ? $account->isPastDue() : false,
            ],
            'channels' => fn (): array => $user?->currentWorkspace
                ? SidebarChannelResource::collection($user->currentWorkspace)
                : [],
            'referralSources' => fn (): ?array => $this->showsReferralSources($user, $account, $isSelfHosted)
                    ? array_map(fn (ReferralSource $source): string => $source->value, ReferralSource::cases())
                    : null,
            'usage' => fn (): ?array => $account && ! $isSelfHosted ? $account->usage() : null,
            'features' => fn (): ?array => $account && ! $isSelfHosted ? $account->featureLimits() : null,
            'flash' => $request->session()->get('flash', []),
            'applicationUrl' => config('app.url'),
            'env' => config('app.env'),
            'locale' => app()->getLocale(),
            'selfHosted' => $isSelfHosted,
        ];
    }

    /**
     * @return array<string, callable>
     */
    public function shareOnce(Request $request): array
    {
        $user = $request->user();
        $workspace = $user?->currentWorkspace;

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
            'legal' => fn (): array => [
                'terms' => (string) config('trypost.legal.terms_url'),
                'privacy' => (string) config('trypost.legal.privacy_url'),
            ],
            'languages' => fn (): array => Locale::options(),
            'aiEnabled' => fn (): bool => filled(config('ai.providers.'.config('ai.default').'.key')),
            'googleAuthEnabled' => fn (): bool => SocialAuthProvider::Google->isEnabled(),
            'githubAuthEnabled' => fn (): bool => SocialAuthProvider::GitHub->isEnabled(),
            ...($user !== null ? [
                'mediaSources' => fn (): array => ['menu' => MediaSource::menu()],
                'mediaUploadLimits' => fn (): array => [
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
                ],
            ] : []),
            ...($workspace !== null ? ['composer' => ComposerResource::once($workspace)] : []),
            ...($user !== null && config('trypost.platforms.x.defuse_links') ? ['xLinkTlds' => fn (): array => LinkTlds::all()] : []),
        ];
    }

    private function showsReferralSources(?User $user, ?Account $account, bool $isSelfHosted): bool
    {
        return $user !== null
            && ! $isSelfHosted
            && $account?->hasAppAccess() === true
            && $user->getRawOriginal('referral_source') === null
            && $user->created_at?->lte(now()->subHour()) === true;
    }
}

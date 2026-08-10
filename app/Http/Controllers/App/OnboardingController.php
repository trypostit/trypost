<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\PostHog\OnboardingEvent;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Http\Resources\App\SocialAccountResource;
use App\Services\PostHogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly ResolveOnboardingStatus $resolveOnboardingStatus,
        private readonly PostHogService $postHog,
    ) {}

    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $account = $user->accountOrFail();
        // Pure read on GET — observers + POST complete/skip stamp progress.
        $status = $this->resolveOnboardingStatus->handle($user);

        if (data_get($status, 'dismissed_at') !== null) {
            return redirect()->route('app.calendar');
        }

        if (
            $account->isOnboardingOpen()
            && $user->isAccountOwner()
            && ! data_get($status, 'all_complete')
        ) {
            $this->postHog->capture(
                $user->id,
                OnboardingEvent::Viewed->value,
                account: $account,
            );
        }

        return Inertia::render('onboarding/Index', [
            'status' => fn (): array => $status,
            'canSkipSteps' => fn (): bool => $user->isAccountOwner(),
            'canManageAccounts' => fn (): bool => $user->can('manageAccounts', $workspace),
            'canCreatePost' => fn (): bool => $user->can('createPost', $workspace),
            'mcpUrl' => fn (): string => route('mcp.trypost'),
            'platforms' => fn (): array => SocialPlatform::connectableOptions(),
            'accounts' => fn (): array => SocialAccountResource::collection(
                $workspace->socialAccounts()->orderBy('id')->get(),
            )->resolve(),
        ]);
    }

    public function skipMcp(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAccountOwner(), Response::HTTP_FORBIDDEN);

        $this->resolveOnboardingStatus->skipMcp($request->user());

        return back();
    }

    public function complete(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->isAccountOwner(), Response::HTTP_FORBIDDEN);

        $account = $user->accountOrFail();

        if ($account->isOnboardingDismissed()) {
            return redirect()->route('app.calendar');
        }

        if ($account->isOnboardingCompleted()) {
            return redirect()->route('app.onboarding');
        }

        if (! data_get($this->resolveOnboardingStatus->handle($user), 'all_complete')) {
            return redirect()->route('app.onboarding');
        }

        $this->resolveOnboardingStatus->markCompleted($user);

        return redirect()->route('app.onboarding');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\PostHog\OnboardingEvent;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly ResolveOnboardingStatus $resolveOnboardingStatus,
        private readonly PostHogService $postHog,
    ) {}

    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        if ($redirect = $this->redirectIfSelfHosted()) {
            return $redirect;
        }

        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $account = $user->accountOrFail();
        // Pure read on GET — observers + POST complete/skip stamp progress.
        $status = $this->resolveOnboardingStatus->handle($user);

        if (data_get($status, 'dismissed_at') !== null || $account->isOnboardingCompleted()) {
            return redirect()->route('app.calendar');
        }

        if (
            $account->isOnboardingOpen()
            && $user->isAccountOwner()
            && ! data_get($status, 'all_complete')
        ) {
            $this->captureViewedOnce($user->id, $account);
        }

        return Inertia::render('onboarding/Index', [
            'status' => fn (): array => $status,
            'canSkipSteps' => fn (): bool => $user->isAccountOwner(),
            'canManageAccounts' => fn (): bool => $user->can('manageAccounts', $workspace),
            'canCreatePost' => fn (): bool => $user->can('createPost', $workspace),
            'mcpUrl' => fn (): string => route('mcp.trypost'),
            'samplePrompt' => fn (): string => __('onboarding.first_post.sample_prompt'),
            'platforms' => fn (): array => SocialPlatform::connectableOptions(),
            'accounts' => fn (): array => SocialAccountResource::collection(
                $workspace->socialAccounts()->orderBy('id')->get(),
            )->resolve(),
        ]);
    }

    public function skipMcp(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfSelfHosted()) {
            return $redirect;
        }

        abort_unless($request->user()->isAccountOwner(), Response::HTTP_FORBIDDEN);

        $this->resolveOnboardingStatus->skipMcp($request->user());

        return back();
    }

    public function complete(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfSelfHosted()) {
            return $redirect;
        }

        $user = $request->user();

        abort_unless($user->isAccountOwner(), Response::HTTP_FORBIDDEN);

        if (! $user->accountOrFail()->isOnboardingOpen()) {
            return redirect()->route('app.calendar');
        }

        if (! data_get($this->resolveOnboardingStatus->handle($user), 'all_complete')) {
            return redirect()->route('app.onboarding');
        }

        $this->resolveOnboardingStatus->markCompleted($user);

        return redirect()->route('app.calendar');
    }

    /**
     * Funnel "viewed" once per account — revisits while activation is open
     * should not spam PostHog (Cache::add is the dedupe, including Echo reloads).
     */
    private function captureViewedOnce(string $userId, Account $account): void
    {
        $dedupeKey = "onboarding:viewed:{$account->id}";

        if (! Cache::add($dedupeKey, true, now()->addYear())) {
            return;
        }

        try {
            $this->postHog->capture(
                $userId,
                OnboardingEvent::Viewed->value,
                account: $account,
            );
        } catch (Throwable $exception) {
            Cache::forget($dedupeKey);

            throw $exception;
        }
    }

    private function redirectIfSelfHosted(): ?RedirectResponse
    {
        return config('trypost.self_hosted')
            ? redirect()->route('app.calendar')
            : null;
    }
}

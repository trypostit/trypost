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
        if ($redirect = $this->redirectIfSelfHosted()) {
            return $redirect;
        }

        $user = $request->user();
        $workspace = $user->currentWorkspace;
        // Capture before syncProgress — same-request auto-stamp still shows the ready state.
        $wasAlreadyComplete = $user->account?->onboarding_completed_at !== null;
        $status = $this->resolveOnboardingStatus->syncProgress($user);

        // Legacy dismiss (deploy backfill) is terminal, including Echo partial reloads.
        if ($status['dismissed_at'] !== null) {
            return redirect()->route('app.calendar');
        }

        $isPartial = $request->hasHeader('X-Inertia-Partial-Component');

        // Full revisit after completion → calendar. Partials (Echo) and the same
        // request that just stamped still render the ready state.
        if ($wasAlreadyComplete && ! $isPartial) {
            return redirect()->route('app.calendar');
        }

        if (
            ! $isPartial
            && $status['completed_at'] === null
            && $status['dismissed_at'] === null
            && $user->isAccountOwner()
            && $user->account !== null
        ) {
            $this->postHog->capture(
                $user->id,
                OnboardingEvent::Viewed->value,
                account: $user->account,
            );
        }

        $accounts = SocialAccountResource::collection(
            $workspace->socialAccounts()->orderBy('id')->get(),
        )->resolve();

        return Inertia::render('onboarding/Index', [
            'status' => $status,
            'canSkipSteps' => $user->isAccountOwner(),
            'canManageAccounts' => $user->can('manageAccounts', $workspace),
            'canCreatePost' => $user->can('createPost', $workspace),
            'mcpUrl' => route('mcp.trypost'),
            'samplePrompt' => __('onboarding.first_post.sample_prompt'),
            'platforms' => SocialPlatform::connectableOptions(),
            'accounts' => $accounts,
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
        $account = $user->account;

        // Already stamped (e.g. observer / syncProgress auto-complete) — just leave.
        if ($account?->onboarding_completed_at !== null) {
            return redirect()->route('app.calendar');
        }

        // Legacy dismiss stays terminal: never let Continue stamp after backfill.
        if ($account?->onboarding_dismissed_at !== null) {
            return redirect()->route('app.calendar');
        }

        $status = $this->resolveOnboardingStatus->handle($user);

        if (! $status['all_complete']) {
            return redirect()->route('app.onboarding');
        }

        $this->resolveOnboardingStatus->markCompleted($user);

        return redirect()->route('app.calendar');
    }

    private function redirectIfSelfHosted(): ?RedirectResponse
    {
        if (! config('trypost.self_hosted')) {
            return null;
        }

        return redirect()->route('app.calendar');
    }
}

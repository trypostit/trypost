<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Enums\PostHog\CheckoutEvent;
use App\Enums\PostHog\WelcomeEvent;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Http\Requests\App\Welcome\StoreWelcomeConnectRequest;
use App\Http\Requests\App\Welcome\StoreWelcomeGoalsRequest;
use App\Http\Requests\App\Welcome\StoreWelcomePersonaRequest;
use App\Http\Requests\App\Welcome\StoreWelcomeReferralSourceRequest;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Plan;
use App\Services\PostHogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WelcomeController extends Controller
{
    public function persona(Request $request): InertiaResponse|RedirectResponse
    {
        if ($redirect = $this->redirectIfUnavailable($request)) {
            return $redirect;
        }

        return Inertia::render('welcome/Persona', [
            'personas' => array_map(fn (Persona $persona): string => $persona->value, Persona::cases()),
            'selected' => $request->user()->persona?->value,
        ]);
    }

    public function storePersona(StoreWelcomePersonaRequest $request, PostHogService $postHog): RedirectResponse
    {
        if ($redirect = $this->redirectIfUnavailable($request)) {
            return $redirect;
        }

        $user = $request->user();
        $persona = (string) $request->validated('persona');

        $user->update(['persona' => $persona]);

        $postHog->identify($user->id, [
            'persona' => $persona,
        ]);
        $postHog->capture(
            $user->id,
            WelcomeEvent::Persona->value,
            ['persona' => $persona],
            $user->account,
        );

        return redirect()->route('app.welcome.goals');
    }

    public function goals(Request $request): InertiaResponse|RedirectResponse
    {
        if ($redirect = $this->redirectIfStepIncomplete($request)) {
            return $redirect;
        }

        $user = $request->user();

        return Inertia::render('welcome/Goals', [
            'goals' => array_map(fn (Goal $goal): string => $goal->value, Goal::cases()),
            'selected' => $user->goals ?? [],
        ]);
    }

    public function storeGoals(StoreWelcomeGoalsRequest $request, PostHogService $postHog): RedirectResponse
    {
        if ($redirect = $this->redirectIfStepIncomplete($request)) {
            return $redirect;
        }

        $user = $request->user();
        $goals = array_values($request->validated('goals'));

        $user->update(['goals' => $goals]);

        $postHog->identify($user->id, [
            'goals' => $goals,
        ]);
        $postHog->capture(
            $user->id,
            WelcomeEvent::Goals->value,
            ['goals' => $goals],
            $user->account,
        );

        return redirect()->route('app.welcome.referral-source');
    }

    public function referralSource(Request $request): InertiaResponse|RedirectResponse
    {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true)) {
            return $redirect;
        }

        $user = $request->user();

        return Inertia::render('welcome/ReferralSource', [
            'sources' => array_map(fn (ReferralSource $source): string => $source->value, ReferralSource::cases()),
            'selected' => $user->referral_source?->value,
        ]);
    }

    public function storeReferralSource(
        StoreWelcomeReferralSourceRequest $request,
        PostHogService $postHog,
    ): RedirectResponse {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true)) {
            return $redirect;
        }

        $user = $request->user();
        $referralSource = (string) $request->validated('referral_source');

        $user->update(['referral_source' => $referralSource]);

        $postHog->identify($user->id, [
            'referral_source' => $referralSource,
        ]);
        $postHog->capture(
            $user->id,
            WelcomeEvent::Referral->value,
            ['referral_source' => $referralSource],
            $user->account,
        );

        return redirect()->route('app.welcome.connect');
    }

    public function connect(Request $request): InertiaResponse|RedirectResponse
    {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true, requireReferral: true)) {
            return $redirect;
        }

        $workspace = $request->user()->currentWorkspace;

        abort_unless($workspace !== null, Response::HTTP_NOT_FOUND);

        return Inertia::render('welcome/Connect', [
            'platforms' => SocialPlatform::connectableOptions(),
            'accounts' => SocialAccountResource::collection(
                $workspace->socialAccounts()->orderBy('id')->get(),
            )->resolve(),
        ]);
    }

    public function storeConnect(
        StoreWelcomeConnectRequest $request,
        StartSubscriptionCheckout $checkout,
        PostHogService $postHog,
    ): Response|RedirectResponse {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true, requireReferral: true)) {
            return $redirect;
        }

        abort_unless($request->user()->currentWorkspace !== null, Response::HTTP_NOT_FOUND);

        $user = $request->user();
        $platforms = $request->connectedPlatforms();

        $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();
        $priceId = $plan->stripe_monthly_price_id;

        abort_if($priceId === null, Response::HTTP_INTERNAL_SERVER_ERROR, 'Monthly price is not configured.');

        $response = $checkout->redirect(
            $user->account,
            $priceId,
            route('app.welcome.connect'),
        );

        try {
            $postHog->capture(
                $user->id,
                WelcomeEvent::Connect->value,
                ['platforms' => $platforms],
                $user->account,
            );
            $postHog->capture(
                $user->id,
                CheckoutEvent::Started->value,
                ['plan_name' => $plan->name, 'interval' => 'monthly'],
                $user->account,
            );
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }

    public function subscriptionRequired(Request $request): InertiaResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user->account?->hasAppAccess()) {
            return redirect()->route('app.calendar');
        }

        if ($user->isAccountOwner()) {
            return redirect()->route('app.welcome.persona');
        }

        return Inertia::render('welcome/SubscriptionRequired', [
            'ownerName' => $user->account?->owner?->name,
        ]);
    }

    private function redirectIfStepIncomplete(
        Request $request,
        bool $requireGoals = false,
        bool $requireReferral = false,
    ): ?RedirectResponse {
        if ($redirect = $this->redirectIfUnavailable($request)) {
            return $redirect;
        }

        $user = $request->user();

        if (! $user->persona) {
            return redirect()->route('app.welcome.persona');
        }

        if ($requireGoals && ! Goal::containsCurrent($user->goals)) {
            return redirect()->route('app.welcome.goals');
        }

        if ($requireReferral && ! $user->referral_source) {
            return redirect()->route('app.welcome.referral-source');
        }

        return null;
    }

    private function redirectIfUnavailable(Request $request): ?RedirectResponse
    {
        $user = $request->user();

        // Match EnsureAccountReady — generic-trial (no-card) users already have
        // app access and must not be sent through Stripe checkout again.
        // Self-hosted always has app access, so welcome/checkout is skipped too.
        if ($user->account?->hasAppAccess()) {
            return redirect()->route('app.calendar');
        }

        // Members can't check out — hold them on a dedicated screen instead of
        // walking an ICP flow they can never finish.
        if (! $user->isAccountOwner()) {
            return redirect()->route('app.welcome.subscription-required');
        }

        return null;
    }
}

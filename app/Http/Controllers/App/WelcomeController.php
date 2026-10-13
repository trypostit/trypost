<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\AccessToken\ListConnectedMcpClients;
use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Billing\Interval;
use App\Enums\Plan\Slug;
use App\Enums\PostHog\CheckoutEvent;
use App\Enums\PostHog\WelcomeEvent;
use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\PreviousSoftware;
use App\Http\Requests\App\Welcome\StoreWelcomeGoalsRequest;
use App\Http\Requests\App\Welcome\StoreWelcomeMcpConnectClickRequest;
use App\Http\Requests\App\Welcome\StoreWelcomePersonaRequest;
use App\Http\Requests\App\Welcome\StoreWelcomeSoftwareRequest;
use App\Http\Resources\App\PlanResource;
use App\Models\Plan;
use App\Services\PostHogService;
use App\Support\Billing\ConfigureSubscriptionCheckout;
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

        $user = $request->user();

        return Inertia::render('welcome/Persona', [
            'personas' => array_map(fn (Persona $persona): string => $persona->value, Persona::cases()),
            'selected' => $user->persona?->value,
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

        return redirect()->route('app.welcome.software');
    }

    public function software(Request $request): InertiaResponse|RedirectResponse
    {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true)) {
            return $redirect;
        }

        $user = $request->user();

        return Inertia::render('welcome/Software', [
            'software' => array_map(fn (PreviousSoftware $software): array => [
                'value' => $software->value,
                'name' => $software->brandName(),
            ], PreviousSoftware::cases()),
            'selected' => $user->previous_software?->value,
        ]);
    }

    public function storeSoftware(StoreWelcomeSoftwareRequest $request, PostHogService $postHog): RedirectResponse
    {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true)) {
            return $redirect;
        }

        $user = $request->user();
        $previousSoftware = (string) $request->validated('previous_software');

        $user->update(['previous_software' => $previousSoftware]);

        $postHog->identify($user->id, [
            'previous_software' => $previousSoftware,
        ]);
        $postHog->capture(
            $user->id,
            WelcomeEvent::Software->value,
            ['previous_software' => $previousSoftware],
            $user->account,
        );

        return redirect()->route('app.welcome.plan');
    }

    public function plan(Request $request): InertiaResponse|RedirectResponse
    {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true, requireSoftware: true)) {
            return $redirect;
        }

        $plan = Plan::active()->where('slug', Slug::Socials)->firstOrFail();

        return Inertia::render('welcome/Plan', [
            'plan' => PlanResource::make($plan)->resolve(),
            'firstMonthOffer' => ConfigureSubscriptionCheckout::offersFirstMonthCoupon($request->user()->account, $plan),
        ]);
    }

    public function storePlan(
        Request $request,
        StartSubscriptionCheckout $checkout,
        PostHogService $postHog,
    ): Response|RedirectResponse {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true, requireSoftware: true)) {
            return $redirect;
        }

        $user = $request->user();
        $plan = Plan::active()->where('slug', Slug::Socials)->firstOrFail();
        $priceId = Interval::Monthly->priceIdFor($plan);

        abort_if($priceId === null, Response::HTTP_INTERNAL_SERVER_ERROR, 'Price is not configured.');

        $firstMonthOffer = ConfigureSubscriptionCheckout::offersFirstMonthCoupon($user->account, $plan);
        $response = $checkout->redirect($user->account, $priceId, route('app.welcome.plan'), $plan);

        try {
            $postHog->capture(
                $user->id,
                CheckoutEvent::Started->value,
                [
                    'plan_name' => $plan->name,
                    'interval' => Interval::Monthly->value,
                    'first_month_offer' => $firstMonthOffer,
                ],
                $user->account,
            );
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }

    public function mcp(Request $request): InertiaResponse|RedirectResponse
    {
        $user = $request->user();

        if (! $user->account?->hasAppAccess()) {
            return redirect()->route('app.welcome.persona');
        }

        $workspace = $user->currentWorkspace;

        if (blank($workspace)) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('view', $workspace);

        return Inertia::render('welcome/Mcp', [
            'mcpUrl' => route('mcp.trypost'),
            'connectedClients' => ListConnectedMcpClients::forUser($user, $workspace),
        ]);
    }

    public function storeMcpConnectClick(StoreWelcomeMcpConnectClickRequest $request, PostHogService $postHog): Response
    {
        $user = $request->user();

        $postHog->capture(
            $user->id,
            WelcomeEvent::McpConnectClicked->value,
            ['client' => (string) $request->validated('client')],
            $user->account,
        );

        return response()->noContent();
    }

    public function skipMcp(Request $request, PostHogService $postHog): RedirectResponse
    {
        $user = $request->user();

        $postHog->capture(
            $user->id,
            WelcomeEvent::McpSkipped->value,
            [],
            $user->account,
        );

        return redirect()->route('app.posts.index');
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
        bool $requireSoftware = false,
    ): ?RedirectResponse {
        if ($redirect = $this->redirectIfUnavailable($request)) {
            return $redirect;
        }

        $user = $request->user();

        if (blank($user->persona)) {
            return redirect()->route('app.welcome.persona');
        }

        if ($requireGoals && ! Goal::containsCurrent($user->goals)) {
            return redirect()->route('app.welcome.goals');
        }

        if ($requireSoftware && blank($user->previous_software)) {
            return redirect()->route('app.welcome.software');
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

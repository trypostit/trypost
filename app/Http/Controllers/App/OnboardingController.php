<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Http\Requests\App\Onboarding\StoreOnboardingGoalsRequest;
use App\Http\Requests\App\Onboarding\StoreOnboardingReferralSourceRequest;
use App\Http\Requests\App\Onboarding\StoreOnboardingRequest;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Account;
use App\Models\Plan;
use App\Services\PostHogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class OnboardingController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();

        if ($user->account?->subscribed(Account::SUBSCRIPTION_NAME)) {
            return redirect()->route('app.calendar');
        }

        return Inertia::render('onboarding/Index', [
            'personas' => array_map(fn (Persona $persona): string => $persona->value, Persona::cases()),
            'selected' => $user->persona?->value,
        ]);
    }

    public function store(StoreOnboardingRequest $request, PostHogService $postHog): RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();

        if ($user->account?->subscribed(Account::SUBSCRIPTION_NAME)) {
            return redirect()->route('app.calendar');
        }

        $persona = (string) $request->validated('persona');

        $user->update(['persona' => $persona]);

        $postHog->identify($user->id, [
            'persona' => $persona,
        ]);

        return redirect()->route('app.onboarding.goals');
    }

    public function goals(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();

        if ($user->account?->subscribed(Account::SUBSCRIPTION_NAME)) {
            return redirect()->route('app.calendar');
        }

        if (! $user->persona) {
            return redirect()->route('app.onboarding');
        }

        return Inertia::render('onboarding/Goals', [
            'goals' => array_map(fn (Goal $goal): string => $goal->value, Goal::cases()),
            'selected' => $user->goals ?? [],
        ]);
    }

    public function storeGoals(StoreOnboardingGoalsRequest $request, PostHogService $postHog): RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();

        if ($user->account?->subscribed(Account::SUBSCRIPTION_NAME)) {
            return redirect()->route('app.calendar');
        }

        if (! $user->persona) {
            return redirect()->route('app.onboarding');
        }

        $goals = array_values($request->validated('goals'));

        $user->update(['goals' => $goals]);

        $postHog->identify($user->id, [
            'goals' => $goals,
        ]);

        return redirect()->route('app.onboarding.referral-source');
    }

    public function referralSource(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();

        if ($user->account?->subscribed(Account::SUBSCRIPTION_NAME)) {
            return redirect()->route('app.calendar');
        }

        if (! $user->persona) {
            return redirect()->route('app.onboarding');
        }

        if (! $user->goals) {
            return redirect()->route('app.onboarding.goals');
        }

        return Inertia::render('onboarding/ReferralSource', [
            'sources' => array_map(fn (ReferralSource $source): string => $source->value, ReferralSource::cases()),
            'selected' => $user->referral_source?->value,
        ]);
    }

    public function storeReferralSource(StoreOnboardingReferralSourceRequest $request, PostHogService $postHog): RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();

        if ($user->account?->subscribed(Account::SUBSCRIPTION_NAME)) {
            return redirect()->route('app.calendar');
        }

        if (! $user->persona) {
            return redirect()->route('app.onboarding');
        }

        if (! $user->goals) {
            return redirect()->route('app.onboarding.goals');
        }

        $referralSource = (string) $request->validated('referral_source');

        $user->update(['referral_source' => $referralSource]);

        $postHog->identify($user->id, [
            'referral_source' => $referralSource,
        ]);

        return redirect()->route('app.onboarding.connect');
    }

    public function connect(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();

        if ($user->account?->subscribed(Account::SUBSCRIPTION_NAME)) {
            return redirect()->route('app.calendar');
        }

        if (! $user->persona) {
            return redirect()->route('app.onboarding');
        }

        if (! $user->goals) {
            return redirect()->route('app.onboarding.goals');
        }

        if (! $user->referral_source) {
            return redirect()->route('app.onboarding.referral-source');
        }

        $workspace = $user->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $accounts = $workspace->socialAccounts()->orderBy('id')->get();

        $platforms = collect(SocialPlatform::cases())
            ->filter(fn (SocialPlatform $platform): bool => $platform->isConnectable())
            ->map(fn (SocialPlatform $platform): array => [
                'value' => $platform->value,
                'label' => $platform->label(),
                'color' => $platform->color(),
                'network' => $platform->network(),
            ])->values();

        $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();

        return Inertia::render('onboarding/Connect', [
            'platforms' => $platforms,
            'accounts' => SocialAccountResource::collection($accounts)->resolve(),
            'plan' => [
                'name' => $plan->name,
                'interval' => 'monthly',
            ],
        ]);
    }

    public function checkout(Request $request, StartSubscriptionCheckout $checkout): SymfonyResponse|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();
        $account = $user->account;

        if ($account?->subscribed(Account::SUBSCRIPTION_NAME)) {
            return redirect()->route('app.calendar');
        }

        $workspace = $user->currentWorkspace;

        if (! $workspace || ! $workspace->socialAccounts()->exists()) {
            return redirect()->route('app.onboarding.connect')
                ->with('flash.banner', __('onboarding.connect.must_connect'))
                ->with('flash.bannerStyle', 'danger');
        }

        $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();

        return $checkout->redirect(
            $account,
            (string) $plan->stripe_monthly_price_id,
            route('app.onboarding.connect'),
        );
    }
}

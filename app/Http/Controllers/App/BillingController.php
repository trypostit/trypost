<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Billing\Interval;
use App\Http\Requests\App\Billing\ChangePlanRequest;
use App\Http\Resources\App\PlanResource;
use App\Models\Account;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BillingController extends Controller
{
    public function subscribe(): RedirectResponse
    {
        return redirect()->route('app.welcome.persona');
    }

    public function processing(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->accountOrFail();

        return Inertia::render('billing/Processing', [
            'subscriptionActive' => $account->subscribed(Account::SUBSCRIPTION_NAME),
        ]);
    }

    public function index(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->account;

        abort_unless($request->user()->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);

        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);
        $plans = Plan::active()->orderBy('sort')->get();

        return Inertia::render('settings/account/Billing', [
            'hasSubscription' => $account->subscribed(Account::SUBSCRIPTION_NAME),
            'onTrial' => $account->isOnTrial(),
            'trialEndsAt' => $account->activeTrialEndsAt(),
            'subscription' => $subscription?->only([
                'stripe_status',
                'ends_at',
            ]),
            'plan' => $account->plan ? PlanResource::make($account->plan)->resolve() : null,
            'plans' => PlanResource::collection($plans)->resolve(),
            'deniedPlanIds' => $plans
                ->filter(fn (Plan $candidate): bool => Gate::inspect('swapPlan', [$account, $candidate])->denied())
                ->pluck('id')
                ->values()
                ->all(),
            'workspaceCount' => $account->workspaces()->count(),
            'invoices' => $account->invoices()->map(fn ($invoice) => [
                'id' => $invoice->id,
                'date' => $invoice->date(),
                'total' => $invoice->total(),
                'status' => $invoice->status,
                'invoice_pdf' => $invoice->invoice_pdf,
            ]),
            'defaultPaymentMethod' => $account->displayablePaymentMethod(),
        ]);
    }

    public function changePlan(ChangePlanRequest $request): RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->account;
        $plan = Plan::active()->findOrFail($request->validated('plan_id'));
        $interval = Interval::from($request->validated('interval'));

        $authorization = Gate::inspect('swapPlan', [$account, $plan]);

        if ($authorization->denied()) {
            return back()->with('flash.error', $authorization->message());
        }

        $priceId = $interval->priceIdFor($plan);

        abort_if($priceId === null, SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY, 'No price configured for this interval');

        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);

        abort_if($subscription === null, SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY, 'No active subscription');

        if ($subscription->stripe_price === $priceId) {
            return redirect()->route('app.billing.index');
        }

        $subscription->swap($priceId);

        return redirect()->route('app.billing.index')
            ->with('flash.success', __('billing.flash.plan_changed', ['plan' => $plan->name]));
    }

    public function portal(Request $request): RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->account;

        abort_unless($request->user()->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);

        return $account->redirectToBillingPortal(
            route('app.billing.index')
        );
    }
}

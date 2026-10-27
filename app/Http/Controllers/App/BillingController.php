<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Billing\ChangePlan;
use App\Actions\Billing\ListAccountCards;
use App\Actions\Billing\ShowSubscriptionDiscount;
use App\Enums\Billing\CheckoutOrigin;
use App\Enums\Billing\Interval;
use App\Http\Requests\App\Billing\ChangePlanRequest;
use App\Http\Resources\App\InvoiceResource;
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
        return redirect()->route('app.welcome.plan');
    }

    public function processing(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->accountOrFail();
        $returnsToBilling = $request->query('from') === CheckoutOrigin::Billing->value;

        return Inertia::render('billing/Processing', [
            'ready' => $returnsToBilling ? $account->planMatchesSubscription() : $account->hasAppAccess(),
            'returnsToBilling' => $returnsToBilling,
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
        $hasSubscription = $account->subscribed(Account::SUBSCRIPTION_NAME);

        return Inertia::render('settings/account/Billing', [
            'hasSubscription' => $hasSubscription,
            'onTrial' => $account->isOnTrial(),
            'trialEndsAt' => $account->activeTrialEndsAt(),
            'subscription' => $subscription?->valid() ? $subscription->only([
                'stripe_status',
                'ends_at',
            ]) : null,
            'plan' => $account->plan ? PlanResource::make($account->plan)->resolve() : null,
            'workspaceCount' => $account->workspaces()->count(),
            'invoices' => Inertia::scroll(
                fn () => InvoiceResource::collection($account->cursorPaginateInvoices((int) config('app.pagination.default'), ['status' => 'paid'])),
            )->matchOn('data.id'),
            'paymentMethods' => fn (): array => $hasSubscription ? ListAccountCards::execute($account, $subscription) : [],
            'discount' => fn (): ?array => ShowSubscriptionDiscount::execute($account, $subscription),
        ]);
    }

    public function changePlan(ChangePlanRequest $request, ChangePlan $changePlan): SymfonyResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        return $this->moveToPlan(
            $request->user()->account,
            Plan::active()->findOrFail($request->validated('plan_id')),
            Interval::from($request->validated('interval')),
            $changePlan,
        );
    }

    /**
     * Subscribe now on billing: a generic trial subscribes to its plan's
     * monthly price.
     */
    public function checkout(Request $request, ChangePlan $changePlan): SymfonyResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->accountOrFail();

        $plan = Plan::active()->find($account->plan_id);

        if ($account->subscribed(Account::SUBSCRIPTION_NAME) || blank($plan)) {
            return redirect()->route('app.billing.index');
        }

        return $this->moveToPlan($account, $plan, Interval::Monthly, $changePlan);
    }

    /**
     * Every plan move answers to `swapPlan`, then the action moves the account.
     */
    private function moveToPlan(Account $account, Plan $plan, Interval $interval, ChangePlan $changePlan): SymfonyResponse
    {
        $authorization = Gate::inspect('swapPlan', [$account, $plan]);

        if ($authorization->denied()) {
            return back()->with('flash.error', $authorization->message());
        }

        $priceId = $interval->priceIdFor($plan);

        abort_if(blank($priceId), SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY, 'No price configured for this interval');

        return $changePlan->execute($account, $plan, $priceId);
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

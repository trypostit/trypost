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

        $subscription = $account->validSubscription();

        return Inertia::render('settings/account/Billing', [
            'hasSubscription' => filled($subscription),
            'onTrial' => $account->isOnTrial(),
            'trialEndsAt' => $account->activeTrialEndsAt(),
            'subscription' => $subscription?->only(['stripe_status', 'ends_at']),
            'plan' => transform($account->plan, fn (Plan $plan): array => PlanResource::make($plan)->resolve()),
            'workspaceCount' => $account->workspaces()->count(),
            'invoices' => Inertia::scroll(fn () => InvoiceResource::collection(
                $account->cursorPaginateInvoices((int) config('app.pagination.default'), ['status' => 'paid']),
            ))->matchOn('data.id'),
            'paymentMethods' => fn (): array => ListAccountCards::execute($account, $subscription),
            'discount' => fn (): ?array => ShowSubscriptionDiscount::execute($account, $subscription),
        ]);
    }

    public function changePlan(ChangePlanRequest $request, ChangePlan $changePlan): SymfonyResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        return $changePlan->execute(
            $request->user()->account,
            Plan::active()->findOrFail($request->validated('plan_id')),
            Interval::from($request->validated('interval')),
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

        return $changePlan->execute($account, $plan, Interval::Monthly);
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

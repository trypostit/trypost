<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Billing\Interval;
use App\Http\Requests\App\Billing\ChangePlanRequest;
use App\Http\Resources\App\InvoiceResource;
use App\Http\Resources\App\PaymentMethodResource;
use App\Http\Resources\App\PlanResource;
use App\Http\Resources\App\SubscriptionDiscountResource;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\Billing\SubscriptionPlanSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\PaymentMethod;
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

        return Inertia::render('settings/account/Billing', [
            'hasSubscription' => $account->subscribed(Account::SUBSCRIPTION_NAME),
            'onTrial' => $account->isOnTrial(),
            'trialEndsAt' => $account->activeTrialEndsAt(),
            'subscription' => $subscription?->only([
                'stripe_status',
                'ends_at',
            ]),
            'plan' => $account->plan ? PlanResource::make($account->plan)->resolve() : null,
            'workspaceCount' => $account->workspaces()->count(),
            'invoices' => Inertia::scroll(
                fn () => InvoiceResource::collection($account->cursorPaginateInvoices((int) config('app.pagination.default'), ['status' => 'paid'])),
            )->matchOn('data.id'),
            'paymentMethods' => $this->paymentMethods($account),
            'discount' => $this->discount($account, $subscription),
        ]);
    }

    /**
     * The customer's cards, the default one first. Stripe Checkout trials anchor
     * the card to the subscription, not the customer, so without
     * `invoice_settings.default_payment_method` the first card is the default.
     *
     * @return list<array{id: string, brand: string, last4: string, exp_month: int, exp_year: int, is_default: bool}>
     */
    private function paymentMethods(Account $account): array
    {
        $paymentMethods = $account->paymentMethods('card');

        if ($paymentMethods->isEmpty()) {
            return [];
        }

        $defaultId = $account->defaultPaymentMethod()?->id ?? $paymentMethods->first()->id;

        return $paymentMethods
            ->sortByDesc(fn (PaymentMethod $paymentMethod): bool => $paymentMethod->id === $defaultId)
            ->map(fn (PaymentMethod $paymentMethod): array => PaymentMethodResource::make($paymentMethod, $paymentMethod->id === $defaultId)->resolve())
            ->values()
            ->all();
    }

    /**
     * @return array{value: string, duration: string, ends_at: string|null}|null
     */
    private function discount(Account $account, ?Subscription $subscription): ?array
    {
        if (blank($subscription) || ! $account->hasStripeId()) {
            return null;
        }

        $discount = $subscription->discount();

        return filled($discount) ? SubscriptionDiscountResource::make($discount)->resolve() : null;
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

        try {
            if ($subscription->stripe_price !== $priceId) {
                $subscription->swap($priceId);
            }
        } catch (IncompletePayment $exception) {
            return redirect()->route('cashier.payment', [
                $exception->payment->id,
                'redirect' => route('app.billing.index'),
            ]);
        }

        $subscription->refresh();

        if (! SubscriptionPlanSync::allows($subscription->stripe_status)) {
            return redirect()->route('app.billing.index');
        }

        $account->update(['plan_id' => $plan->id]);

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

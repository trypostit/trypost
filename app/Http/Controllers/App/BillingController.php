<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Billing\CheckoutOrigin;
use App\Enums\Billing\Interval;
use App\Enums\Plan\Slug;
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
        $from = $request->query('from');
        $returnsToBilling = is_string($from) && CheckoutOrigin::tryFrom($from) === CheckoutOrigin::Billing;

        return Inertia::render('billing/Processing', [
            'ready' => $returnsToBilling ? $account->subscribed(Account::SUBSCRIPTION_NAME) : $account->hasAppAccess(),
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
            'subscription' => $subscription?->only([
                'stripe_status',
                'ends_at',
            ]),
            'plan' => $account->plan ? PlanResource::make($account->plan)->resolve() : null,
            'workspaceCount' => $account->workspaces()->count(),
            'invoices' => Inertia::scroll(
                fn () => InvoiceResource::collection($account->cursorPaginateInvoices((int) config('app.pagination.default'), ['status' => 'paid'])),
            )->matchOn('data.id'),
            'paymentMethods' => fn (): array => $hasSubscription ? $this->paymentMethods($account, $subscription) : [],
            'discount' => fn (): ?array => $this->discount($account, $subscription),
        ]);
    }

    /**
     * The customer's cards, the default one first: the customer's default, else
     * the subscription's (Stripe Checkout anchors the card there), else the
     * first card.
     *
     * @return list<array{id: string, brand: string, last4: string, exp_month: int, exp_year: int, is_default: bool}>
     */
    private function paymentMethods(Account $account, Subscription $subscription): array
    {
        $paymentMethods = $account->paymentMethods('card');

        if ($paymentMethods->isEmpty()) {
            return [];
        }

        $defaultId = $account->defaultPaymentMethod()?->id
            ?? $this->subscriptionPaymentMethodId($subscription)
            ?? $paymentMethods->first()->id;

        return $paymentMethods
            ->sortByDesc(fn (PaymentMethod $paymentMethod): bool => $paymentMethod->id === $defaultId)
            ->map(fn (PaymentMethod $paymentMethod): array => PaymentMethodResource::make($paymentMethod, $paymentMethod->id === $defaultId)->resolve())
            ->values()
            ->all();
    }

    /**
     * The card Stripe Checkout attached to the subscription, which renewals
     * charge when the customer has no default of its own.
     */
    private function subscriptionPaymentMethodId(Subscription $subscription): ?string
    {
        $paymentMethod = $subscription->asStripeSubscription()->default_payment_method;

        return is_string($paymentMethod) ? $paymentMethod : $paymentMethod?->id;
    }

    /**
     * @return array{value: string, duration: string, ends_at: string|null}|null
     */
    private function discount(Account $account, ?Subscription $subscription): ?array
    {
        if (blank($subscription) || ! $subscription->valid() || ! $account->hasStripeId()) {
            return null;
        }

        $discount = $subscription->discount();

        return filled($discount) ? SubscriptionDiscountResource::make($discount)->resolve() : null;
    }

    public function changePlan(ChangePlanRequest $request, StartSubscriptionCheckout $checkout): SymfonyResponse
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

        if (! $account->subscribed(Account::SUBSCRIPTION_NAME)) {
            return $this->checkoutFromTrial($account, $plan, $priceId, $checkout);
        }

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

    /**
     * Subscribe now on billing: a generic trial subscribes to its plan's
     * monthly price.
     */
    public function checkout(Request $request, StartSubscriptionCheckout $checkout): SymfonyResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->account;

        abort_unless($request->user()->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);

        if ($account->subscribed(Account::SUBSCRIPTION_NAME)) {
            return redirect()->route('app.billing.index');
        }

        $plan = $account->plan ?? Plan::active()->where('slug', Slug::Socials)->firstOrFail();
        $priceId = Interval::Monthly->priceIdFor($plan);

        abort_if($priceId === null, SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR, 'Price is not configured.');

        return $this->checkoutFromTrial($account, $plan, $priceId, $checkout);
    }

    /**
     * A generic trial subscribes through Stripe Checkout, charged at once,
     * with a coupon offer the owner claimed meanwhile.
     */
    private function checkoutFromTrial(Account $account, Plan $plan, string $priceId, StartSubscriptionCheckout $checkout): SymfonyResponse
    {
        return $checkout->redirect(
            $account,
            $priceId,
            route('app.billing.index'),
            $plan,
            $account->currentOffer(withTrial: false),
            CheckoutOrigin::Billing,
        );
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

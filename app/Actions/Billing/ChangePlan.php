<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Enums\Billing\CheckoutOrigin;
use App\Models\Account;
use App\Models\Plan;
use App\Support\Billing\SubscriptionPlanSync;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Symfony\Component\HttpFoundation\Response;

class ChangePlan
{
    public function __construct(private StartSubscriptionCheckout $checkout) {}

    /**
     * Moves the account to a plan's price. A subscription swaps its price; a
     * generic trial subscribes through Stripe Checkout, charged at once, with
     * a coupon offer the owner claimed meanwhile.
     */
    public function execute(Account $account, Plan $plan, string $priceId): Response
    {
        if (! $account->subscribed(Account::SUBSCRIPTION_NAME)) {
            return $this->checkout->redirect(
                $account,
                $priceId,
                route('app.billing.index'),
                $plan,
                $account->currentOffer(withTrial: false),
                CheckoutOrigin::Billing,
            );
        }

        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);

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
}

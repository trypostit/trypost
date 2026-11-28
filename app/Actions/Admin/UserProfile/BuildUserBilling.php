<?php

declare(strict_types=1);

namespace App\Actions\Admin\UserProfile;

use App\Actions\Billing\ListAccountCards;
use App\Http\Resources\App\InvoiceResource;
use App\Models\Account;
use App\Models\Offer;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Admin\StripeDashboard;
use App\Support\Admin\SubscriptionPlans;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Discount;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\PaymentMethod;
use Throwable;

class BuildUserBilling
{
    private const int INVOICES = 12;

    /**
     * The billing of the account the user belongs to. What TryPost stores
     * (plan, subscription status, trial, scheduled cancellation,
     * offers) comes from the database; what only Stripe knows (every card, the
     * discount, the invoices) is read from Stripe when the tab opens. A Stripe
     * failure leaves the stored part on screen with the error.
     *
     * @return array<string, mixed>
     */
    public static function execute(User $user): array
    {
        $account = $user->account?->loadMissing(['plan', 'subscriptions', 'offers']);

        if ($account === null) {
            return ['account' => null];
        }

        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);

        return [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'is_owner' => $account->owner_id === $user->id,
                'stripe_id' => $account->stripe_id,
                'stripe_url' => StripeDashboard::customerUrl($account->stripe_id),
                'has_app_access' => $account->hasAppAccess(),
                'generic_trial_ends_at' => $account->trial_ends_at?->toIso8601String(),
            ],
            'plan' => $account->plan?->name,
            'subscription' => self::subscription($subscription),
            'offers' => $account->offers->map(fn (Offer $offer): array => [
                'slug' => $offer->slug,
                'claimed_at' => data_get($offer, 'pivot.claimed_at'),
                'redeemed_at' => data_get($offer, 'pivot.redeemed_at'),
            ])->values()->all(),
            'stripe' => self::fromStripe($account, $subscription),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function subscription(?Subscription $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        return [
            'status' => $subscription->stripe_status,
            'interval' => SubscriptionPlans::intervalOf($subscription->stripe_price),
            'started_at' => $subscription->created_at?->toIso8601String(),
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'cancels_at' => $subscription->ends_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fromStripe(Account $account, ?Subscription $subscription): ?array
    {
        if (blank($account->stripe_id)) {
            return null;
        }

        try {
            $invoices = $account->invoicesIncludingPending(['limit' => self::INVOICES, 'expand' => ['data.discounts']]);

            return [
                'cards' => self::cards($account, $subscription),
                'discount' => self::discount($subscription?->discount() ?? $account->discount()),
                'used_discounts' => $invoices->flatMap(fn (Invoice $invoice): array => self::usedDiscounts($invoice))->values()->all(),
                'invoices' => $invoices
                    ->map(fn (Invoice $invoice): array => InvoiceResource::make($invoice)->resolve())
                    ->values()
                    ->all(),
                'error' => null,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['cards' => [], 'discount' => null, 'used_discounts' => [], 'invoices' => [], 'error' => $exception->getMessage()];
        }
    }

    /**
     * The coupons an invoice took off, with what each took: a coupon used
     * once leaves the subscription after its first invoice, so the invoices
     * are where it still shows. Stripe sends a discount as its id unless
     * expanded, so only the expanded objects are read.
     *
     * @return list<array{name: string|null, amount_off: string, invoice_date: string}>
     */
    private static function usedDiscounts(Invoice $invoice): array
    {
        $stripeInvoice = $invoice->asStripeInvoice();
        $amounts = collect($stripeInvoice->total_discount_amounts ?? [])
            ->mapWithKeys(fn (object $amount): array => [(string) data_get($amount, 'discount') => (int) data_get($amount, 'amount')]);

        return collect($stripeInvoice->discounts ?? [])
            ->filter(fn (mixed $discount): bool => is_object($discount))
            ->map(fn (object $discount): array => [
                'name' => data_get($discount, 'coupon.name') ?? data_get($discount, 'coupon.id'),
                'amount_off' => Cashier::formatAmount((int) $amounts->get((string) data_get($discount, 'id'), 0), $stripeInvoice->currency),
                'invoice_date' => $invoice->date()->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Every card saved on the customer, the default one first. Not only the
     * cards of a live subscription: a former customer's cards show too.
     *
     * @return list<array<string, mixed>>
     */
    private static function cards(Account $account, ?Subscription $subscription): array
    {
        if ($subscription !== null) {
            return ListAccountCards::execute($account, $subscription);
        }

        return $account->paymentMethods('card')
            ->map(fn (PaymentMethod $card): array => [
                'id' => $card->id,
                'brand' => $card->card->brand,
                'last4' => $card->card->last4,
                'exp_month' => $card->card->exp_month,
                'exp_year' => $card->card->exp_year,
                'is_default' => false,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{name: string|null, percent_off: float|null, amount_off: string|null, duration: string|null, ends_at: string|null, promotion_code: string|null}|null
     */
    private static function discount(?Discount $discount): ?array
    {
        if ($discount === null) {
            return null;
        }

        $coupon = $discount->coupon();

        return [
            'name' => $coupon->name(),
            'percent_off' => $coupon->percentOff(),
            'amount_off' => $coupon->amountOff(),
            'duration' => data_get($coupon->asStripeCoupon(), 'duration'),
            'ends_at' => $discount->end()?->toIso8601String(),
            'promotion_code' => $discount->promotionCode()?->code,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Http\Resources\App\PaymentMethodResource;
use App\Models\Account;
use App\Models\Subscription;
use Laravel\Cashier\PaymentMethod;

class ListAccountCards
{
    /**
     * The customer's cards from Stripe, the default one first: the customer's
     * default, else the subscription's (Stripe Checkout anchors the card
     * there), whichever is a card in the list, else the first card.
     *
     * @return list<array{id: string, brand: string, last4: string, exp_month: int, exp_year: int, is_default: bool}>
     */
    public static function execute(Account $account, Subscription $subscription): array
    {
        $cards = $account->paymentMethods('card');

        if ($cards->isEmpty()) {
            return [];
        }

        $defaultId = collect([$account->defaultPaymentMethod()?->id, self::subscriptionPaymentMethodId($subscription)])
            ->first(fn (?string $id): bool => $cards->contains(fn (PaymentMethod $card): bool => $card->id === $id))
            ?? $cards->first()->id;

        return $cards
            ->sortByDesc(fn (PaymentMethod $card): bool => $card->id === $defaultId)
            ->map(fn (PaymentMethod $card): array => PaymentMethodResource::make($card, $card->id === $defaultId)->resolve())
            ->values()
            ->all();
    }

    /**
     * The card Stripe Checkout attached to the subscription, which renewals
     * charge when the customer has no default of its own.
     */
    private static function subscriptionPaymentMethodId(Subscription $subscription): ?string
    {
        $paymentMethod = $subscription->asStripeSubscription()->default_payment_method;

        return is_string($paymentMethod) ? $paymentMethod : $paymentMethod?->id;
    }
}

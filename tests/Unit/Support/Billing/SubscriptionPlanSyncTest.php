<?php

declare(strict_types=1);

use App\Support\Billing\SubscriptionPlanSync;
use Stripe\Subscription as StripeSubscription;

test('only active and trialing subscriptions may sync a plan', function (mixed $status, bool $allowed) {
    expect(SubscriptionPlanSync::allows($status))->toBe($allowed);
})->with([
    'active' => [StripeSubscription::STATUS_ACTIVE, true],
    'trialing' => [StripeSubscription::STATUS_TRIALING, true],
    'past due' => [StripeSubscription::STATUS_PAST_DUE, false],
    'incomplete' => [StripeSubscription::STATUS_INCOMPLETE, false],
    'unpaid' => [StripeSubscription::STATUS_UNPAID, false],
    'canceled' => [StripeSubscription::STATUS_CANCELED, false],
    'incomplete expired' => [StripeSubscription::STATUS_INCOMPLETE_EXPIRED, false],
    'paused' => [StripeSubscription::STATUS_PAUSED, false],
    'missing' => [null, false],
]);

test('unpaid canceled and incomplete expired clear a leftover plan', function (string $status) {
    expect(SubscriptionPlanSync::clears($status))->toBeTrue();
})->with([
    'unpaid' => [StripeSubscription::STATUS_UNPAID],
    'canceled' => [StripeSubscription::STATUS_CANCELED],
    'incomplete expired' => [StripeSubscription::STATUS_INCOMPLETE_EXPIRED],
]);

test('active trialing past due and incomplete do not clear a plan', function (mixed $status) {
    expect(SubscriptionPlanSync::clears($status))->toBeFalse();
})->with([
    'active' => [StripeSubscription::STATUS_ACTIVE],
    'trialing' => [StripeSubscription::STATUS_TRIALING],
    'past due' => [StripeSubscription::STATUS_PAST_DUE],
    'incomplete' => [StripeSubscription::STATUS_INCOMPLETE],
    'paused' => [StripeSubscription::STATUS_PAUSED],
    'missing' => [null],
]);

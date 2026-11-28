<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;
use Stripe\Subscription as StripeSubscription;
use Throwable;

/**
 * The monthly revenue the paying subscriptions bring once their coupons are
 * taken off, as Stripe will charge them next: a coupon used once is already
 * gone from its subscription, one that repeats counts until it ends. Read
 * from Stripe's subscription list (a page of a hundred at a time, discounts
 * included) and kept an hour, so the page never waits on Stripe twice.
 */
final class StripeNetMrr
{
    private const string CACHE_KEY = 'admin:stripe-net-mrr';

    private const int CACHE_SECONDS = 3600;

    /** How long a failed read is kept before Stripe is asked again, in seconds. */
    public const int ERROR_CACHE_SECONDS = 60;

    /**
     * @return array{cents: int}|array{error: string}
     */
    public static function read(): array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $result = self::fromStripe(CarbonImmutable::now('UTC'));
        } catch (Throwable $exception) {
            report($exception);

            $error = ['error' => $exception->getMessage()];
            Cache::put(self::CACHE_KEY, $error, self::ERROR_CACHE_SECONDS);

            return $error;
        }

        Cache::put(self::CACHE_KEY, $result, self::CACHE_SECONDS);

        return $result;
    }

    /**
     * What one subscription charges per month, in cents, before and after
     * the discounts that still apply. A discount applies to the invoice as a
     * whole: a percentage takes its share of every item, a fixed amount is
     * taken once, however many items the invoice has.
     *
     * @return array{list: float, net: float}
     */
    public static function monthly(StripeSubscription $subscription, CarbonInterface $now): array
    {
        $discounts = collect($subscription->discounts ?? [])
            ->filter(fn (mixed $discount): bool => is_object($discount))
            ->filter(fn (object $discount): bool => blank(data_get($discount, 'end')) || (int) data_get($discount, 'end') > $now->getTimestamp());

        $items = collect($subscription->items->data ?? [])
            ->map(fn (object $item): ?array => self::itemCharge($item))
            ->filter()
            ->values();

        $perInvoice = (float) $items->sum('per_invoice');
        $list = (float) $items->sum(fn (array $item): float => data_get($item, 'per_invoice') / data_get($item, 'months'));

        return [
            'list' => $list,
            'net' => self::netShare($list, $perInvoice, self::afterDiscounts($perInvoice, $discounts)),
        ];
    }

    /**
     * What one item charges per invoice, and how many months an invoice
     * covers; null for a price that does not recur.
     *
     * @return array{per_invoice: float, months: float}|null
     */
    private static function itemCharge(object $item): ?array
    {
        $months = SubscriptionPlans::monthsPerInvoice(data_get($item, 'price'));

        if ($months === null) {
            return null;
        }

        return [
            'per_invoice' => (float) data_get($item, 'price.unit_amount', 0) * max(1, (int) data_get($item, 'quantity', 1)),
            'months' => $months,
        ];
    }

    /** The monthly amount left once the invoice's discounts are taken off, in proportion. */
    private static function netShare(float $monthly, float $perInvoice, float $netPerInvoice): float
    {
        if ($perInvoice <= 0.0) {
            return 0.0;
        }

        return $monthly * $netPerInvoice / $perInvoice;
    }

    /**
     * @return array{cents: int}
     */
    private static function fromStripe(CarbonImmutable $now): array
    {
        $net = 0.0;

        foreach (Subscription::PAYING_STATUSES as $status) {
            $page = Cashier::stripe()->subscriptions->all([
                'status' => $status,
                'limit' => 100,
                'expand' => ['data.discounts'],
            ]);

            foreach ($page->autoPagingIterator() as $subscription) {
                $net += data_get(self::monthly($subscription, $now), 'net');
            }
        }

        return ['cents' => (int) round($net)];
    }

    /**
     * @param  iterable<int, object>  $discounts
     */
    private static function afterDiscounts(float $amount, iterable $discounts): float
    {
        foreach ($discounts as $discount) {
            $percent = data_get($discount, 'coupon.percent_off');
            $off = data_get($discount, 'coupon.amount_off');

            if (is_numeric($percent)) {
                $amount *= 1 - (float) $percent / 100;
            }

            if (is_numeric($off)) {
                $amount -= (float) $off;
            }
        }

        return max(0.0, $amount);
    }
}

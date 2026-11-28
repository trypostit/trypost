<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Laravel\Cashier\Cashier;
use Stripe\Exception\InvalidRequestException;
use Throwable;

/**
 * The coupon exists in the Stripe account the app runs on (test or live), so
 * an offer never points Checkout at a coupon Stripe would refuse.
 */
class StripeCouponExists implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            Cashier::stripe()->coupons->retrieve((string) $value);
        } catch (InvalidRequestException) {
            $fail('Stripe has no coupon with this id in this account.');
        } catch (Throwable) {
            $fail("Couldn't reach Stripe to check this coupon. Try again.");
        }
    }
}

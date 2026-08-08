---
paths:
  - 'app/Support/Billing/**'
---

# Billing

## Checkout knobs are env-only
Stripe Checkout options live in ConfigureSubscriptionCheckout, driven by REQUIRE_CARD_FOR_TRIAL, CASHIER_TRIAL_DAYS, STRIPE_FIRST_MONTH_COUPON_ID, and CASHIER_ALLOW_PROMOTION_CODES. A set first-month coupon skips trialDays (coupon wins). Coupon + allow_promotion_codes on the same qualifying checkout must throw — Stripe rejects discounts and allow_promotion_codes together. Empty coupon + card required uses trialDays; do not reintroduce a required-coupon throw.

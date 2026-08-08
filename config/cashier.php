<?php

declare(strict_types=1);

use Laravel\Cashier\Console\WebhookCommand;
use Laravel\Cashier\Invoices\DompdfInvoiceRenderer;

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe Keys
    |--------------------------------------------------------------------------
    |
    | The Stripe publishable key and secret key give you access to Stripe's
    | API. The "publishable" key is typically used when interacting with
    | Stripe.js while the "secret" key accesses private API endpoints.
    |
    */

    'key' => env('STRIPE_KEY'),

    'secret' => env('STRIPE_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Cashier Path
    |--------------------------------------------------------------------------
    |
    | This is the base URI path where Cashier's views, such as the payment
    | verification screen, will be available from. You're free to tweak
    | this path according to your preferences and application design.
    |
    */

    'path' => env('CASHIER_PATH', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Stripe Webhooks
    |--------------------------------------------------------------------------
    |
    | Your Stripe webhook secret is used to prevent unauthorized requests to
    | your Stripe webhook handling controllers. The tolerance setting will
    | check the drift between the current time and the signed request's.
    |
    */

    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        'events' => WebhookCommand::DEFAULT_EVENTS,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | This is the default currency that will be used when generating charges
    | from your application. Of course, you are welcome to use any of the
    | various world currencies that are currently supported via Stripe.
    |
    */

    'currency' => env('CASHIER_CURRENCY', 'usd'),

    /*
    |--------------------------------------------------------------------------
    | Currency Locale
    |--------------------------------------------------------------------------
    |
    | This is the default locale in which your money values are formatted in
    | for display. To utilize other locales besides the default en locale
    | verify you have the "intl" PHP extension installed on the system.
    |
    */

    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'en'),

    /*
    |--------------------------------------------------------------------------
    | Payment Confirmation Notification
    |--------------------------------------------------------------------------
    |
    | If this setting is enabled, Cashier will automatically notify customers
    | whose payments require additional verification. You should listen to
    | Stripe's webhooks in order for this feature to function correctly.
    |
    */

    'payment_notification' => env('CASHIER_PAYMENT_NOTIFICATION'),

    /*
    |--------------------------------------------------------------------------
    | Invoice Settings
    |--------------------------------------------------------------------------
    |
    | The following options determine how Cashier invoices are converted from
    | HTML into PDFs. You're free to change the options based on the needs
    | of your application or your preferences regarding invoice styling.
    |
    */

    'invoices' => [
        'renderer' => env('CASHIER_INVOICE_RENDERER', DompdfInvoiceRenderer::class),

        'options' => [
            // Supported: 'letter', 'legal', 'A4'
            'paper' => env('CASHIER_PAPER', 'letter'),

            'remote_enabled' => env('CASHIER_REMOTE_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Logger
    |--------------------------------------------------------------------------
    |
    | This setting defines which logging channel will be used by the Stripe
    | library to write log messages. You are free to specify any of your
    | logging channels listed inside the "logging" configuration file.
    |
    */

    'logger' => env('CASHIER_LOGGER'),

    /*
    |--------------------------------------------------------------------------
    | Trial Period
    |--------------------------------------------------------------------------
    |
    | Days of trial. With REQUIRE_CARD_FOR_TRIAL=true and no first-month coupon
    | applied, first-time checkouts get Stripe trialDays (status trialing).
    | Re-subscribers (any prior real subscription) skip trial. With
    | REQUIRE_CARD_FOR_TRIAL=false, it is the generic signup trial length on
    | accounts.trial_ends_at. Set to 0 to disable trials. Stripe Checkout
    | rejects trials shorter than 48 hours — values of 1 are clamped to 2.
    |
    */

    'trial_days' => env('CASHIER_TRIAL_DAYS', 8),

    /*
    |--------------------------------------------------------------------------
    | Paid First Month Coupon
    |--------------------------------------------------------------------------
    |
    | Optional Stripe Coupon ID (amount_off, duration=once). When set and the
    | account qualifies (card required, single workspace, first-time), checkout
    | applies withCoupon and skips trialDays so the first invoice validates the
    | card. Empty = no coupon; card-required checkouts use trial_days instead.
    | Cannot be combined with allow_promotion_codes on the same checkout.
    |
    */

    'first_month_coupon_id' => env('STRIPE_FIRST_MONTH_COUPON_ID'),

    /*
    |--------------------------------------------------------------------------
    | Allow Promotion Codes
    |--------------------------------------------------------------------------
    |
    | When true and no first-month coupon is applied on the session, Stripe
    | Checkout shows the redeemable promotion-code field. Defaults to false
    | (SaaS recipe A). Stripe rejects a session that sets both discounts
    | (coupon) and allow_promotion_codes — ConfigureSubscriptionCheckout
    | throws if both would apply on the same checkout.
    |
    */

    'allow_promotion_codes' => (bool) env('CASHIER_ALLOW_PROMOTION_CODES', false),

];

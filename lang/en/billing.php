<?php

return [
    'title' => 'Billing',

    'past_due_notice' => [
        'title' => 'Payment past due',
        'description' => 'Update your payment method to keep your subscription active.',
        'cta' => 'Update payment',
    ],

    'annual_banner' => [
        'title' => 'Get 2 months free',
        'description' => 'Switch to annual billing and pay less every month — same plan, nothing else changes.',
        'cta' => 'Upgrade to annual',
    ],

    'subscribe' => [
        'billed_monthly' => 'Billed monthly',
        'billed_yearly' => 'Billed annually',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => 'Plans',
        'description' => 'Upgrade or downgrade at any time.',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'save_two_months' => '2 months free',
        'per_month' => '/month',
        'workspaces_one' => '1 workspace',
        'workspaces_unlimited' => 'Unlimited workspaces',
        'current' => 'Current plan',
        'select' => 'Choose :plan',
        'start_first_month' => 'Start my first month for :price',

        'billed_yearly_total' => 'Billed annually · :price (2 months free)',
        'socials_tagline' => 'Best for creators and small brands.',
        'workspaces_tagline' => 'Best for agencies and larger businesses.',
        'everything_included' => 'Everything included',
        'features' => [
            'networks_all' => 'All social networks',
            'accounts_unlimited' => 'Unlimited social accounts',
            'calendar' => 'Visual calendar with auto-publishing',
            'ai' => 'AI content: captions, images, brand voice',
            'mcp' => 'MCP: create and schedule from Claude, ChatGPT, or Grok',
            'repurpose' => 'Repurpose: turn one post into many',
            'analytics' => 'Analytics per post and account',
            'team' => 'Unlimited teammates, roles, and approvals',
        ],
    ],

    'plan' => [
        'title' => 'Plan',
        'description' => 'Manage your subscription plan.',
        'label' => 'Plan',
        'price' => 'Price',
        'month' => 'month',
        'trial' => 'Trial',
        'active' => 'Active',
        'past_due' => 'Past due',
        'cancelling' => 'Cancelling',
        'trial_ends' => 'Trial ends',
    ],

    'subscription' => [
        'title' => 'Subscription',
        'description' => 'Manage your payment method, billing details, and subscription.',
        'payment_method' => 'Payment method',
        'no_payment_method' => 'No payment method on file yet.',
        'expires_on' => 'Expires :month/:year',
        'manage_label' => 'Subscription',
        'manage_stripe' => 'Manage on Stripe',
    ],

    'invoices' => [
        'title' => 'Invoices',
        'description' => 'Download your past invoices.',
        'empty' => 'No invoices found',
        'paid' => 'Paid',
    ],

    'flash' => [
        'plan_changed' => 'You are now on the :plan plan.',
        'switched_to_yearly' => 'You\'re now on annual billing.',
        'cannot_manage' => 'Only the account owner can manage billing.',
        'too_many_workspaces' => 'You have :count workspaces. This plan includes :limit — delete the extras before switching.',
        'subscription_required' => 'An active subscription is required to use AI features.',
    ],

    'processing' => [
        'page_title' => 'Processing...',
        'title' => 'Processing your subscription',
        'description' => 'Please wait while we set up your account. This will only take a moment.',
        'success_title' => 'You\'re all set!',
        'success_description' => 'Your subscription is active. Redirecting you to your workspaces...',
        'cancelled_title' => 'Checkout cancelled',
        'cancelled_description' => 'Your checkout was cancelled. No charges were made.',
        'retry' => 'Try again',
    ],
];

<?php

return [
    'title' => 'Billing',

    'past_due_notice' => [
        'title' => 'Payment past due',
        'description' => 'Update your payment method to keep your subscription active.',
        'cta' => 'Update payment',
    ],

    'subscribe' => [
        'billed_monthly' => 'Billed monthly',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'description' => 'Upgrade or downgrade at any time.',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'save_two_months' => '2 months free',
        'per_month' => '/month',
        'workspaces_one' => 'One workspace',
        'workspaces_unlimited' => 'Unlimited workspaces',
        'workspaces_tooltip' => 'A workspace is one brand or client, kept separate from the rest: its own social accounts, signatures, labels, analytics, member permissions, and MCP connection.',
        'current' => 'Current plan',
        'switch_to_yearly' => 'Switch to yearly',
        'switch_to_monthly' => 'Switch to monthly',
        'select' => 'Choose :plan',
        'upgrade' => 'Upgrade to :plan',
        'downgrade' => 'Downgrade to :plan',
        'start_first_month' => 'Activate for :price',
        'billed_yearly_total' => 'Billed annually · :price (2 months free)',
        'socials_tagline' => 'Best for creators and small brands.',
        'workspaces_tagline' => 'Best for agencies and larger businesses.',
        'everything_included' => 'Everything included',
        'features' => [
            'networks_all' => 'All 13 social networks',
            'networks_all_tooltip' => 'Post to all of them from one place.',
            'accounts_unlimited' => 'Unlimited social accounts',
            'accounts_unlimited_tooltip' => 'Connect as many accounts as you want, even several from the same network. Three Instagrams, for example.',
            'calendar' => 'Content calendar',
            'calendar_tooltip' => 'See your whole month at a glance: what\'s planned, scheduled, and already published. Switch to week when you need the detail.',
            'ai' => 'AI writing assistant',
            'ai_tooltip' => 'Your AI assistant for writing and reviewing posts.',
            'mcp' => 'Run TryPost from Claude or ChatGPT',
            'mcp_tooltip' => 'Connect Claude, ChatGPT or another AI. Ask it to create and schedule posts, fill your queue, organize ideas and show how you\'re growing.',
            'repurpose' => 'Automatic video reposting',
            'repurpose_tooltip' => 'Pick an Instagram or Facebook account. Every new Reel or video you post there goes to your other networks on its own, without opening TryPost.',
            'analytics' => 'Advanced analytics',
            'analytics_tooltip' => 'Views, impressions, reach, likes, comments, shares, saves, clicks, watch time and follower growth for every post and account. See if you\'re growing and which posts work best.',
            'team' => 'Unlimited team members',
            'team_tooltip' => 'Invite as many people as you want to your team, at no extra cost. Set what each person can do and approve posts before they go out.',
        ],
    ],

    'current_plan' => [
        'change' => 'Change plan',
        'price_monthly' => ':price/month',
        'price_yearly' => ':price/year',
        'trial_until' => 'Trial until :date',
        'renews' => 'Renews automatically',
        'cancelled' => 'Cancelled, ends on :date',
        'workspaces_usage' => 'Workspaces: :count of :limit',
    ],

    'subscription' => [
        'title' => 'Payment method',
        'description' => 'Update your card or billing details on Stripe.',
        'no_payment_method' => 'No payment method on file yet.',
        'expires_on' => 'Expires :month/:year',
        'manage_stripe' => 'Manage on Stripe',
    ],

    'invoices' => [
        'title' => 'Invoices',
        'description' => 'Download your past invoices.',
        'paid' => 'Paid',
        'download' => 'Download invoice',
    ],

    'flash' => [
        'plan_changed' => 'You are now on the :plan plan.',
        'cannot_manage' => 'Only the account owner can manage billing.',
        'too_many_workspaces' => 'You have :count workspaces. This plan includes :limit — delete the extras before switching.',
        'subscription_required' => 'An active subscription is required to use AI features.',
    ],

    'processing' => [
        'page_title' => 'Processing...',
        'title' => 'Processing your subscription',
        'description' => 'Please wait while we set up your account. This will only take a moment.',
    ],
];

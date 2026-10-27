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
        'per_year' => '/year',
        'workspaces_one' => 'One workspace',
        'workspaces_unlimited' => 'Unlimited workspaces',
        'workspaces_tooltip' => 'A workspace is one brand or client, kept separate from the rest: its own social accounts, signatures, labels, analytics, member permissions, and MCP connection.',
        'current' => 'Current plan',
        'switch_to_yearly' => 'Switch to yearly',
        'switch_to_monthly' => 'Switch to monthly',
        'select' => 'Choose :plan',
        'upgrade' => 'Upgrade to :plan',
        'downgrade' => 'Downgrade to :plan',
        'billed_yearly_total' => 'Billed annually · :price (2 months free)',
        'socials_tagline' => 'Best for creators and small brands.',
        'workspaces_tagline' => 'Best for agencies and larger businesses.',
        'everything_included' => 'Everything included',
        'features' => [
            'networks_all' => 'All social networks included',
            'networks_all_tooltip' => 'Post to all of them from one place.',
            'accounts_unlimited' => 'Unlimited social accounts',
            'accounts_unlimited_tooltip' => 'Connect as many accounts as you want, even several from the same network. Three Instagrams, for example.',
            'calendar' => 'Content calendar',
            'ideas' => 'Content planning',
            'ideas_tooltip' => 'Jot down your ideas, keep them organized in one place and turn the best ones into content whenever you want.',
            'calendar_tooltip' => 'See every scheduled and published post in one place, by month or by week.',
            'ai' => 'AI writing assistant',
            'ai_tooltip' => 'Write, rewrite and adjust the tone of your posts with AI, in your language.',
            'mcp' => 'Run TryPost from Claude or ChatGPT',
            'mcp_tooltip' => 'Connect Claude, ChatGPT or another AI. Ask it to create and schedule posts, fill your queue, organize ideas and show how you\'re growing.',
            'repurpose' => 'Automatic video reposting',
            'repurpose_tooltip' => 'Posted a video on Instagram? TryPost automatically reposts it to TikTok, YouTube, Facebook and your other networks.',
            'analytics' => 'Advanced analytics',
            'analytics_tooltip' => 'See all your networks together, like your total followers, or filter by network and by post. Views, reach, likes, comments and more, so you know what works.',
            'team' => 'Unlimited team members',
            'team_tooltip' => 'Invite as many people as you want to your team, at no extra cost. Set what each person can do and approve posts before they go out.',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => 'Active',
            'trial' => 'Free trial',
            'cancelling' => 'Cancelled',
            'past_due' => 'Past due',
        ],
        'change' => 'Change plan',
        'price_monthly' => ':price/month',
        'price_yearly' => ':price/year',
        'trial_until' => 'Trial until :date',
        'cancelled' => 'Cancelled, ends on :date',
        'discount_until' => ':discount off until :date',
        'discount_forever' => ':discount off on every payment',
        'discount_once' => ':discount off your next payment',
    ],

    'subscription' => [
        'title' => 'Payment methods',
        'default' => 'Default',
        'description' => 'Update your card or billing details on Stripe.',
        'no_payment_method' => 'No payment method on file yet.',
        'expires_on' => 'Expires :month/:year',
        'manage_stripe' => 'Manage on Stripe',
    ],

    'tabs' => [
        'plan' => 'Plan',
        'invoices' => 'Invoices',
    ],

    'invoices' => [
        'columns' => [
            'date' => 'Date',
            'amount' => 'Amount',
            'status' => 'Status',
        ],
        'empty_title' => 'No invoices yet',
        'empty_description' => 'Your invoices show up here after your first payment.',
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

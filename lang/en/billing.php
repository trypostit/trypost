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
        'title' => 'Plans',
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
        'start_first_month' => 'Start for :price',
        'per_first_month' => '/first month',
        'then_monthly' => 'Then :price/month',
        'billed_yearly_total' => 'Billed annually · :price (2 months free)',
        'socials_tagline' => 'Best for creators and small brands.',
        'workspaces_tagline' => 'Best for agencies and larger businesses.',
        'everything_included' => 'Everything included',
        'features' => [
            'networks_all' => 'All social networks included',
            'networks_all_tooltip' => 'You can post to every one of these.',
            'accounts_unlimited' => 'Unlimited social accounts',
            'accounts_unlimited_tooltip' => 'Connect as many accounts as you want, even several from the same network. Three Instagrams, for example.',
            'calendar' => 'Calendar: month, week, and day views',
            'calendar_tooltip' => 'See your whole month at a glance: what\'s planned, scheduled, and already published. Switch to week or day when you need the detail.',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => 'Your AI assistant for writing and reviewing posts.',
            'mcp' => 'MCP: post from Claude, ChatGPT, or Grok',
            'mcp_tooltip' => 'Connect Claude, ChatGPT, or Grok to your workspace. Ask it to create and schedule posts, pull metrics, spot what performed best, and plan what comes next from your own data.',
            'repurpose' => 'Repurpose: turn one post into many',
            'repurpose_tooltip' => 'Choose a source account. Every new post you publish there is automatically republished to your other networks. You don\'t need to open TryPost.',
            'analytics' => 'Analytics',
            'analytics_tooltip' => 'Get metrics like impressions, reach, likes, and comments for every post and every account, all in one place.',
            'team' => 'Unlimited members',
            'team_tooltip' => 'Invite as many people as you want to your team, at no extra cost. Set what each person can do and approve posts before they go out.',
        ],
    ],

    'plan' => [
        'trial' => 'Trial',
        'cancelling' => 'Cancelling',
        'trial_ends' => 'Trial ends',
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

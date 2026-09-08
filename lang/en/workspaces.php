<?php

declare(strict_types=1);

return [
    'title' => 'Workspaces',
    'select_title' => 'Your workspaces',
    'select_description' => 'Select a workspace to continue',
    'current' => 'Current',
    'connections' => ':count connections',
    'posts' => ':count posts',
    'subscription_required' => 'Subscribe to create more workspaces.',
    'limit_reached' => 'Your plan includes one workspace. Upgrade to add more.',

    'create' => [
        'page_title' => 'Create your workspace',
        'title' => 'Set up your workspace',
        'description' => 'Tell us a bit about you or your project. We\'ll use it to tailor AI-generated posts to your voice.',
        'website' => 'Website',
        'website_placeholder' => 'https://yourbrand.com',
        'autofill' => 'Autofill from website',
        'autofill_missing_url' => 'Enter a URL first.',
        'autofill_success' => 'Brand info loaded.',
        'autofill_error' => 'Could not autofill. You can fill the fields manually.',
        'autofill_errors' => [
            'unreachable' => 'We could not reach that website (:reason).',
            'http_status' => 'The website returned an unexpected status (:status).',
            'invalid_scheme' => 'Only http and https URLs are supported.',
            'missing_host' => 'The URL is missing a host.',
            'unresolvable_host' => 'We could not resolve the host (:host).',
            'private_network' => 'URLs pointing to private networks are not allowed.',
        ],
        'logo_captured' => 'Logo captured from your website.',
        'name' => 'Workspace name',
        'name_placeholder' => 'e.g. Acme Inc',
        'brand_description' => 'Brand description',
        'brand_description_placeholder' => 'What does your brand do?',
        'content_language' => 'Content language',
        'content_language_description' => 'AI-generated captions will be written in this language.',
        'brand_color' => 'Brand color',
        'background_color' => 'Background color',
        'text_color' => 'Text color',
        'submit' => 'Create workspace',
        'success' => 'Workspace created. Connect a social account to start posting.',
    ],

    'cannot_delete_last' => 'You cannot delete your only workspace. Cancel your subscription in billing settings to close your account.',

    'flash' => [
        'deleted' => 'Workspace deleted successfully.',
    ],
];

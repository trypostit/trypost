<?php

declare(strict_types=1);

return [
    'title' => 'Getting started',
    'welcome' => 'Welcome to TryPost, :name',
    'welcome_anonymous' => 'Welcome to TryPost',
    'description' => 'Follow the steps below to see how TryPost works and publish your first post.',
    'skip_step' => 'Skip this step',
    'continue' => 'Continue to TryPost',
    'status' => [
        'complete' => 'Complete',
        'todo' => 'To do',
        'skipped' => 'Skipped',
    ],
    'mcp' => [
        'title' => 'Connect your AI assistant',
        'description' => 'Add TryPost as an MCP server so your assistant can create and manage social posts for you.',
        'copy_step' => 'Copy your TryPost server URL',
        'open_step' => 'Open your AI assistant',
        'copy' => 'Copy URL',
        'copied' => 'MCP URL copied.',
        'connect' => 'Connect with :client',
        'clients' => [
            'claude' => 'Open Settings → Connectors, add a custom connector, then paste the URL above.',
            'chatgpt' => 'Open Settings → Apps & Connectors, create a custom connector, then paste the URL above.',
        ],
    ],
    'social' => [
        'title' => 'Connect a social account',
        'description' => 'Choose at least one network where TryPost can publish your content.',
        'connected_elsewhere' => 'You already connected an account in another workspace, so this step is done.',
    ],
    'first_post' => [
        'title' => 'Create your first post',
        'description' => 'Try this starter prompt with your connected assistant, or create the post directly in TryPost.',
        'prompt_label' => 'Sample prompt',
        'sample_prompt' => 'Create a friendly social post introducing my brand and adapt it for each connected network.',
        'copy_prompt' => 'Copy prompt',
        'copied' => 'Sample prompt copied.',
        'create_button' => 'Create your first post',
        'or' => 'or',
    ],
    'ready' => [
        'title' => 'You are ready to publish',
        'description' => 'You are set. Continue to TryPost and start planning your content.',
    ],
];

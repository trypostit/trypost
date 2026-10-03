<?php

declare(strict_types=1);

return [
    'title' => 'Command palette',
    'description' => 'Search channels, pages and actions.',
    'placeholder' => 'Search channels, pages...',
    'path' => ':parent → :child',
    'empty' => 'No results for ":query". Try different keywords.',
    'groups' => [
        'recent' => 'Recent',
        'quick_actions' => 'Quick actions',
        'navigation' => 'Navigation',
        'channels' => 'Channels',
        'settings' => 'Settings',
        'insights' => 'Insights',
    ],
    'actions' => [
        'create_post' => 'Create new post',
        'create_post_description' => 'Start creating a new post',
        'create_idea' => 'Create idea',
        'create_idea_description' => 'Save a content idea for later',
        'invite_member' => 'Invite team member',
        'invite_member_description' => 'Add people to your team',
        'connect_channel' => 'Connect new channel',
        'connect_channel_description' => 'Add a new social media account',
    ],
    'navigation' => [
        'settings' => 'Settings',
        'settings_description' => 'Open settings',
    ],
    'footer' => [
        'navigate' => 'Navigate',
        'select' => 'Select',
        'close' => 'Close',
    ],
];

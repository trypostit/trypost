<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => 'New',
    ],
    'actions' => [
        'got_it' => 'Got it',
        'back' => 'Back',
        'next' => 'Next',
        'done' => 'Done',
        'skip' => 'Skip tour',
    ],
    'progress' => ':current of :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'Pick how you see your posts',
                    'description' => 'Switch between list, calendar and board to plan your posts your way.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'Filter by period',
                    'description' => 'Filter your data by recent days or by specific dates.',
                ],
                'channels' => [
                    'title' => 'Filter by channel',
                    'description' => 'See one channel\'s numbers or compare several.',
                ],
                'labels' => [
                    'title' => 'Filter by label',
                    'description' => 'Follow a campaign or a series through its labels.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'You haven\'t connected an AI yet',
                    'description' => 'Connect Claude or ChatGPT to create, schedule and analyze your posts just by asking.',
                    'not_now' => 'Not now',
                ],
            ],
        ],
    ],
];

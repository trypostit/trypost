<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => 'Nieuw',
    ],
    'actions' => [
        'got_it' => 'Begrepen',
        'back' => 'Terug',
        'next' => 'Volgende',
        'done' => 'Klaar',
        'skip' => 'Tour overslaan',
    ],
    'progress' => ':current van :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'Kies hoe je je posts ziet',
                    'description' => 'Wissel tussen lijst, kalender en bord om je posts op jouw manier te plannen.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'Filter op periode',
                    'description' => 'Filter je gegevens op de afgelopen dagen of op specifieke datums.',
                ],
                'channels' => [
                    'title' => 'Filter op kanaal',
                    'description' => 'Bekijk de cijfers van één kanaal of vergelijk er meerdere.',
                ],
                'labels' => [
                    'title' => 'Filter op label',
                    'description' => 'Volg een campagne of reeks via de labels.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'Je hebt nog geen AI gekoppeld',
                    'description' => 'Koppel Claude of ChatGPT om posts te maken, te plannen en te analyseren door het gewoon te vragen.',
                    'not_now' => 'Niet nu',
                ],
            ],
        ],
    ],
];

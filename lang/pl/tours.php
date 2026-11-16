<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => 'Nowość',
    ],
    'actions' => [
        'got_it' => 'Rozumiem',
        'back' => 'Wstecz',
        'next' => 'Dalej',
        'done' => 'Gotowe',
        'skip' => 'Pomiń przewodnik',
    ],
    'progress' => ':current z :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'Wybierz, jak widzieć posty',
                    'description' => 'Przełączaj się między listą, kalendarzem i tablicą, by planować posty po swojemu.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'Filtruj według okresu',
                    'description' => 'Filtruj dane według ostatnich dni lub konkretnych dat.',
                ],
                'channels' => [
                    'title' => 'Filtruj według kanału',
                    'description' => 'Zobacz liczby jednego kanału lub porównaj kilka.',
                ],
                'labels' => [
                    'title' => 'Filtruj według etykiety',
                    'description' => 'Śledź kampanię lub serię po jej etykietach.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'Nie masz jeszcze połączonej AI',
                    'description' => 'Połącz Claude lub ChatGPT, aby tworzyć, planować i analizować posty, po prostu o to prosząc.',
                    'not_now' => 'Nie teraz',
                ],
            ],
        ],
    ],
];

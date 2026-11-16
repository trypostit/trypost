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

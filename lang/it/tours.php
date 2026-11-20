<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => 'Novità',
    ],
    'actions' => [
        'got_it' => 'Ho capito',
        'back' => 'Indietro',
        'next' => 'Avanti',
        'done' => 'Fatto',
        'skip' => 'Salta il tour',
    ],
    'progress' => ':current di :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'Cambia vista',
                    'description' => 'Pianifica i tuoi post nel formato che funziona meglio per te.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'Filtra per periodo',
                    'description' => 'Filtra i tuoi dati per gli ultimi giorni o per date specifiche.',
                ],
                'channels' => [
                    'title' => 'Filtra per canale',
                    'description' => 'Guarda i numeri di un canale o confrontane diversi.',
                ],
                'labels' => [
                    'title' => 'Filtra per etichetta',
                    'description' => 'Segui una campagna o una serie tramite le sue etichette.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'Non hai ancora collegato un\'IA',
                    'description' => 'Collega Claude o ChatGPT per creare, programmare e analizzare i post semplicemente chiedendolo.',
                    'not_now' => 'Non ora',
                ],
            ],
        ],
    ],
];

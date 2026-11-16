<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => 'Nuevo',
    ],
    'actions' => [
        'got_it' => 'Entendido',
        'back' => 'Atrás',
        'next' => 'Siguiente',
        'done' => 'Listo',
        'skip' => 'Saltar tour',
    ],
    'progress' => ':current de :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'Cambia la vista',
                    'description' => 'Planifica tus publicaciones en el formato que mejor te funcione.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'Filtra por periodo',
                    'description' => 'Filtra tus datos por los últimos días o por fechas concretas.',
                ],
                'channels' => [
                    'title' => 'Filtra por canal',
                    'description' => 'Mira los números de un canal o compara varios.',
                ],
                'labels' => [
                    'title' => 'Filtra por etiqueta',
                    'description' => 'Sigue una campaña o una serie por sus etiquetas.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'Aún no has conectado ninguna IA',
                    'description' => 'Conecta Claude o ChatGPT para crear, programar y analizar tus posts con solo pedirlo.',
                    'not_now' => 'Ahora no',
                ],
            ],
        ],
    ],
];

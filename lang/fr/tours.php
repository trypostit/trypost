<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => 'Nouveau',
    ],
    'actions' => [
        'got_it' => 'Compris',
        'back' => 'Retour',
        'next' => 'Suivant',
        'done' => 'Terminé',
        'skip' => 'Passer',
    ],
    'progress' => ':current sur :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'Changez de vue',
                    'description' => 'Planifiez vos publications dans le format qui vous convient le mieux.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'Filtrez par période',
                    'description' => 'Filtrez vos données sur les derniers jours ou sur des dates précises.',
                ],
                'channels' => [
                    'title' => 'Filtrez par canal',
                    'description' => 'Affichez les chiffres d\'un canal ou comparez-en plusieurs.',
                ],
                'labels' => [
                    'title' => 'Filtrez par étiquette',
                    'description' => 'Suivez une campagne ou une série grâce à ses étiquettes.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'Vous n\'avez pas encore connecté d\'IA',
                    'description' => 'Connectez Claude ou ChatGPT pour créer, planifier et analyser vos posts simplement en le demandant.',
                    'not_now' => 'Plus tard',
                ],
            ],
        ],
    ],
];

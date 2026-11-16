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
        'skip' => 'Passer la visite',
    ],
    'progress' => ':current sur :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'Choisissez comment voir vos publications',
                    'description' => 'Passez de la liste au calendrier ou au tableau pour planifier vos publications à votre façon.',
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

<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => 'Novo',
    ],
    'actions' => [
        'got_it' => 'Entendi',
        'back' => 'Voltar',
        'next' => 'Próximo',
        'done' => 'Concluir',
        'skip' => 'Pular tour',
    ],
    'progress' => ':current de :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'Escolha como ver seus posts',
                    'description' => 'Alterne entre lista, calendário e quadro para planejar seus posts do seu jeito.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'Filtre por período',
                    'description' => 'Filtre seus dados pelos últimos dias ou por datas específicas.',
                ],
                'channels' => [
                    'title' => 'Filtre por canal',
                    'description' => 'Veja os números de um canal ou compare vários.',
                ],
                'labels' => [
                    'title' => 'Filtre por etiqueta',
                    'description' => 'Acompanhe uma campanha ou série pelas etiquetas.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'Você ainda não conectou uma IA',
                    'description' => 'Conecte o Claude ou o ChatGPT para criar, agendar e analisar seus posts só pedindo.',
                    'not_now' => 'Agora não',
                ],
            ],
        ],
    ],
];

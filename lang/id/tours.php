<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => 'Baru',
    ],
    'actions' => [
        'got_it' => 'Mengerti',
        'back' => 'Kembali',
        'next' => 'Lanjut',
        'done' => 'Selesai',
        'skip' => 'Lewati tur',
    ],
    'progress' => ':current dari :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'Ganti tampilan',
                    'description' => 'Rencanakan post kamu dengan tampilan yang paling cocok untukmu.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'Filter per periode',
                    'description' => 'Filter datamu berdasarkan hari terakhir atau tanggal tertentu.',
                ],
                'channels' => [
                    'title' => 'Filter per channel',
                    'description' => 'Lihat angka satu channel atau bandingkan beberapa sekaligus.',
                ],
                'labels' => [
                    'title' => 'Filter per label',
                    'description' => 'Ikuti kampanye atau seri post lewat labelnya.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'Kamu belum menghubungkan AI',
                    'description' => 'Hubungkan Claude atau ChatGPT untuk membuat, menjadwalkan, dan menganalisis post kamu hanya dengan bertanya.',
                    'not_now' => 'Nanti saja',
                ],
            ],
        ],
    ],
];

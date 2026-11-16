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
                    'description' => 'Rencanakan postinganmu dengan tampilan yang paling cocok untukmu.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'Filter menurut periode',
                    'description' => 'Filter datamu berdasarkan beberapa hari terakhir atau tanggal tertentu.',
                ],
                'channels' => [
                    'title' => 'Filter menurut channel',
                    'description' => 'Lihat angka satu channel atau bandingkan beberapa channel sekaligus.',
                ],
                'labels' => [
                    'title' => 'Filter menurut label',
                    'description' => 'Pantau kampanye atau seri postingan lewat labelnya.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'Kamu belum menghubungkan AI',
                    'description' => 'Hubungkan Claude atau ChatGPT untuk membuat, menjadwalkan, dan menganalisis postinganmu cukup dengan meminta.',
                    'not_now' => 'Nanti saja',
                ],
            ],
        ],
    ],
];

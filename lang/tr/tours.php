<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => 'Yeni',
    ],
    'actions' => [
        'got_it' => 'Anladım',
        'back' => 'Geri',
        'next' => 'İleri',
        'done' => 'Bitti',
        'skip' => 'Turu atla',
    ],
    'progress' => ':current / :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'Gönderilerini nasıl göreceğini seç',
                    'description' => 'Gönderilerini kendi tarzında planlamak için liste, takvim ve pano arasında geçiş yap.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'Henüz bir yapay zekâ bağlamadın',
                    'description' => 'Gönderilerini sadece isteyerek oluşturmak, planlamak ve analiz etmek için Claude veya ChatGPT\'yi bağla.',
                    'not_now' => 'Şimdi değil',
                ],
            ],
        ],
    ],
];

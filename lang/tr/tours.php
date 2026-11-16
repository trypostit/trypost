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
                    'title' => 'Görünümü değiştir',
                    'description' => 'Gönderilerini sana en uygun düzende planla.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'Döneme göre filtrele',
                    'description' => 'Verilerini son günlere veya belirli tarihlere göre filtrele.',
                ],
                'channels' => [
                    'title' => 'Kanala göre filtrele',
                    'description' => 'Bir kanalın rakamlarını gör veya birkaçını karşılaştır.',
                ],
                'labels' => [
                    'title' => 'Etikete göre filtrele',
                    'description' => 'Bir kampanyayı veya seriyi etiketleriyle takip et.',
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

<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => 'جديد',
    ],
    'actions' => [
        'got_it' => 'فهمت',
        'back' => 'رجوع',
        'next' => 'التالي',
        'done' => 'تم',
        'skip' => 'تخطي الجولة',
    ],
    'progress' => ':current من :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'اختر طريقة عرض منشوراتك',
                    'description' => 'بدّل بين القائمة والتقويم واللوحة لتخطط منشوراتك بطريقتك.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'لم يتم ربط أي ذكاء اصطناعي بعد',
                    'description' => 'اربط Claude أو ChatGPT لإنشاء منشوراتك وجدولتها وتحليلها بمجرد طلب ذلك.',
                    'not_now' => 'ليس الآن',
                ],
            ],
        ],
    ],
];

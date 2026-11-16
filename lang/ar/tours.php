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
                    'title' => 'غيّر طريقة العرض',
                    'description' => 'خطط لمنشوراتك بالشكل الذي يناسبك أكثر.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'صفِّ حسب الفترة',
                    'description' => 'صفِّ بياناتك حسب الأيام الأخيرة أو تواريخ محددة.',
                ],
                'channels' => [
                    'title' => 'صفِّ حسب القناة',
                    'description' => 'اعرض أرقام قناة واحدة أو قارن بين عدة قنوات.',
                ],
                'labels' => [
                    'title' => 'صفِّ حسب التسمية',
                    'description' => 'تابع حملة أو سلسلة من خلال تسمياتها.',
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

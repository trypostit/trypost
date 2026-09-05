<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'أعد نشر مقاطع الفيديو التي تنشرها خارج TryPost على شبكاتك الأخرى تلقائيًا.',
    'new' => 'repurpose جديد',

    'empty' => [
        'title' => 'لم يتم إعداد أي repurpose بعد',
        'description' => 'اختر نقطة بداية بالأسفل. يراقب TryPost الحساب الذي تختاره ويعيد نشر كل فيديو جديد على الشبكات التي تحددها.',
    ],

    'table' => [
        'source' => 'المصدر',
        'destinations' => 'الوجهات',
        'status' => 'الحالة',
        'published' => 'تم النسخ',
        'last_polled' => 'آخر فحص',
    ],

    'status' => [
        'draft' => 'مسودة',
        'active' => 'نشط',
        'paused' => 'متوقف مؤقتًا',
        'disabled' => 'معطّل',
    ],

    'templates' => [
        'use' => 'استخدام هذا القالب',
        'instagram_everywhere' => [
            'title' => 'Instagram في كل مكان',
            'description' => 'انشر Reel على Instagram وسيعيد TryPost نشره على TikTok وYouTube Shorts وFacebook.',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook في كل مكان',
            'description' => 'انشر فيديو على صفحتك في Facebook وسيعيد TryPost نشره على Instagram وTikTok وYouTube Shorts.',
        ],
    ],

    'create' => [
        'title' => 'repurpose جديد',
        'description' => 'اختر الحساب الذي يجب أن يراقبه TryPost. تختار الوجهات في الشاشة التالية.',
        'source_label' => 'حساب المصدر',
        'source_placeholder' => 'اختر حسابًا',
        'no_accounts' => 'اربط أولًا حساب Instagram أو Facebook. هذان فقط يصلحان كمصدر، لأنهما الشبكتان الوحيدتان اللتان تسمحان بتنزيل الفيديو.',
        'submit' => 'إنشاء',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'تُنسخ مقاطع الفيديو المنشورة على هذا الحساب خارج TryPost إلى الوجهات أدناه.',
    ],

    'tabs' => [
        'configuration' => 'الإعداد',
        'activity' => 'النشاط',
    ],

    'destinations' => [
        'title' => 'الوجهات',
        'description' => 'يُنشر كل فيديو جديد من المصدر على كل حساب تختاره هنا.',
        'hint' => 'يُعدَّل النص لكل شبكة فقط عندما يتجاوز حد تلك الشبكة.',
        'none_available' => 'لا يوجد حساب آخر متصل في مساحة العمل هذه بعد.',
    ],

    'status_card' => [
        'title' => 'الحالة',
        'activate' => 'تفعيل',
        'pause' => 'إيقاف مؤقت',
        'resume' => 'استئناف',
        'disable' => 'تعطيل',
        'watermark' => 'المراقبة منذ',
        'last_polled' => 'آخر فحص',
        'draft_hint' => 'اختر وجهة واحدة على الأقل ثم فعّل. تُنسخ فقط مقاطع الفيديو المنشورة بعد التفعيل.',
        'active_hint' => 'يفحص TryPost هذا الحساب بانتظام وينسخ كل فيديو جديد.',
        'paused_hint' => 'الفحوصات متوقفة. الاستئناف يكمل من حيث توقف ولا يضيع شيء نُشر في الأثناء.',
        'disabled_hint' => 'معطّل. التفعيل من جديد يبدأ من الصفر: ما نشرته أثناء التعطيل يبقى خارجًا.',
    ],

    'items' => [
        'source' => 'الأصل',
        'published_at' => 'نُشر',
        'status' => 'الحالة',
        'detail' => 'التفاصيل',
        'posts' => 'نُسخ إلى',
        'view_original' => 'عرض الأصل',
        'open_post' => 'فتح المنشور',
        'statuses' => [
            'pending' => 'في الانتظار',
            'processing' => 'قيد المعالجة',
            'published' => 'تم النسخ',
            'skipped' => 'تم التخطي',
            'failed' => 'فشل',
        ],
        'reasons' => [
            'published_via_trypost' => 'تم نشره بالفعل عبر TryPost',
            'not_video' => 'ليس فيديو',
            'media_url_missing' => 'لم توفّر الشبكة ملفًا قابلًا للتنزيل، عادةً بسبب صوت محمي بحقوق النشر',
            'download_failed' => 'تعذّر تنزيل الفيديو',
            'post_creation_failed' => 'لا توجد وجهة متاحة',
        ],
    ],

    'danger' => [
        'title' => 'حذف هذا الـ repurpose',
        'description' => 'تتوقف الفحوصات فورًا. تبقى المنشورات التي أُنشئت في تقويمك.',
        'delete' => 'حذف الـ repurpose',
    ],

    'errors' => [
        'source_already_used' => 'هذا الحساب يغذّي بالفعل repurpose آخر. عدّل ذلك بدلًا منه.',
        'destinations_required' => 'اختر وجهة واحدة على الأقل قبل التفعيل.',
    ],
];

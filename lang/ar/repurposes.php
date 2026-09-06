<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'أعد نشر مقاطع الفيديو التي تنشرها خارج TryPost على شبكاتك الأخرى تلقائيًا.',
    'new' => 'repurpose جديد',

    'flow' => [
        'no_destinations' => 'لا توجد وجهة بعد',
    ],

    'publish_mode' => [

        'title' => 'النشر',

        'description' => 'ما الذي يحدث عند ظهور فيديو جديد.',

    ],

    'publish_modes' => [

        'publish' => 'النشر تلقائيًا',

        'publish_hint' => 'تتم جدولة كل فيديو جديد فور العثور عليه.',

        'draft' => 'الإنشاء كمسودة',

        'draft_hint' => 'يصبح كل فيديو جديد مسودة هنا لمراجعتها ونشرها.',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'مقاطع الفيديو',
        'story' => 'Stories',
    ],

    'source' => [
        'title' => 'المصدر',
        'description' => 'يراقب TryPost هذا الحساب بحثًا عن مقاطع فيديو جديدة بالصيغة أدناه.',
        'account_label' => 'الحساب',
        'watch_label' => 'المراقبة',
    ],

    'summary' => [
        'sentence' => 'كل :format جديد تنشره على :source يُعاد نشره على :destinations.',
        'no_destinations' => 'كل :format جديد تنشره على :source ما زال بانتظار وجهة.',
    ],

    'empty' => [
        'title' => 'لم يتم إعداد أي repurpose بعد',
        'description' => 'اختر نقطة بداية بالأسفل. يراقب TryPost الحساب الذي تختاره ويعيد نشر كل فيديو جديد على الشبكات التي تحددها.',
    ],

    'table' => [
        'flow' => 'التدفق',
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
        'source_search' => 'البحث عن الحسابات',
        'source_empty' => 'لم يتم العثور على حساب.',
        'source_placeholder' => 'اختر حسابًا',
        'no_accounts' => 'اربط أولًا حساب Instagram أو Facebook. هذان فقط يصلحان كمصدر، لأنهما الشبكتان الوحيدتان اللتان تسمحان بتنزيل الفيديو.',
        'submit' => 'إنشاء',
        'connect' => 'ربط حساب',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'تُنسخ مقاطع الفيديو المنشورة على هذا الحساب خارج TryPost إلى الوجهات أدناه.',
    ],

    'tabs' => [
        'configuration' => 'الإعداد',
        'activity' => 'النشاط',
        'settings' => 'الإعدادات',
    ],

    'destinations' => [
        'title' => 'الوجهات',
        'description' => 'اختر الحسابات التي ستستقبله. ينشر كل حساب بالصيغة التي تحددها.',
        'hint' => 'يُعدَّل النص لكل شبكة فقط عندما يتجاوز حد تلك الشبكة.',
        'none_available' => 'لا يوجد حساب آخر متصل في مساحة العمل هذه بعد.',
        'save' => 'حفظ التغييرات',
        'saved' => 'تم حفظ الوجهات',
        'publish_as' => 'النشر كـ',
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
        'original_from' => 'الأصل بتاريخ :date',
        'empty' => [
            'title' => 'لا شيء بعد',
            'description' => 'ستظهر هنا مقاطع الفيديو التي ينشرها هذا الحساب خارج TryPost.',
        ],
        'open_post' => 'فتح المنشور',
        'statuses' => [
            'pending' => 'في الانتظار',
            'processing' => 'قيد المعالجة',
            'published' => 'تم النسخ',
            'drafted' => 'مسودة',
            'skipped' => 'تم التخطي',
            'failed' => 'فشل',
        ],
        'reasons' => [
            'published_via_trypost' => 'تم نشره بالفعل عبر TryPost',
            'media_url_missing' => 'لم توفّر الشبكة ملفًا قابلًا للتنزيل، عادةً بسبب صوت محمي بحقوق النشر',
            'download_failed' => 'تعذّر تنزيل الفيديو',
            'post_creation_failed' => 'تعذّر إنشاء المنشورات',
            'no_usable_destinations' => 'تمت إزالة جميع الوجهات أو إيقافها',
        ],
    ],

    'menu' => [

        'label' => 'إجراءات أخرى',

    ],

    'danger' => [
        'title' => 'حذف هذا الـ repurpose',
        'description' => 'تتوقف الفحوصات فورًا. تبقى المنشورات التي أُنشئت في تقويمك.',
        'delete' => 'حذف الـ repurpose',
    ],

    'errors' => [
        'source_already_used' => 'هذا الحساب يغذّي بالفعل repurpose آخر. عدّل ذلك بدلًا منه.',
        'source_missing' => 'اختر حسابًا للمراقبة قبل بدء هذه الأتمتة.',
        'source_unusable' => 'أعد ربط الحساب الذي تراقبه هذه الأتمتة قبل بدئها.',
        'destinations_required' => 'اختر وجهة واحدة على الأقل قبل التفعيل.',
        'destination_needs_video' => 'هذه الصيغة لا تقبل الفيديو.',
        'only_paused_resumes' => 'لا يمكن استئناف سوى repurpose متوقف مؤقتًا.',
        'only_active_pauses' => 'لا يمكن إيقاف سوى إعادة توظيف نشطة مؤقتًا.',
        'only_running_disables' => 'لا يمكن تعطيل سوى إعادة توظيف قيد التشغيل.',
        'only_idle_activates' => 'لا يمكن تفعيل سوى مسودة أو إعادة توظيف معطّلة.',
        'destination_unavailable' => 'لم يعد حساب الوجهة هذا متاحًا.',
        'destination_is_source' => 'هذه الوجهة هي الحساب نفسه الذي تراقبه إعادة التوظيف هذه.',
        'source_unavailable' => 'لم يعد حساب المصدر هذا متاحًا.',
        'action_failed' => 'حدث خطأ ما. راجع النموذج وحاول مرة أخرى.',
    ],
];

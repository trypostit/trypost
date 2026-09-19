<?php

declare(strict_types=1);

return [
    'title' => 'مساحات العمل',
    'select_title' => 'مساحات العمل الخاصة بك',
    'select_description' => 'اختر مساحة عمل للمتابعة',
    'current' => 'الحالية',
    'connections' => ':count اتصال',
    'posts' => ':count منشور',
    'subscription_required' => 'اشترك لإنشاء المزيد من مساحات العمل.',
    'limit_reached' => 'تتضمن خطتك مساحة عمل واحدة. قم بالترقية لإضافة المزيد.',

    'upgrade_dialog' => [
        'title' => 'رقِّ خطتك لإضافة مساحات عمل',
        'description' => 'خطتك تتضمن مساحة عمل واحدة. رقِّ إلى Workspaces للحصول على مساحات عمل غير محدودة — واحدة لكل علامة أو عميل.',
    ],

    'create' => [
        'page_title' => 'أنشئ مساحة عملك',
        'title' => 'إعداد مساحة عملك',
        'description' => 'أخبرنا قليلًا عنك أو عن مشروعك. سنستخدم ذلك لتخصيص المنشورات المُنشأة بالذكاء الاصطناعي لتتناسب مع أسلوبك.',
        'website' => 'الموقع الإلكتروني',
        'website_placeholder' => 'https://yourbrand.com',
        'autofill' => 'تعبئة تلقائية من الموقع',
        'autofill_missing_url' => 'أدخل رابطًا أولًا.',
        'autofill_success' => 'تم تحميل معلومات العلامة التجارية.',
        'autofill_error' => 'تعذرت التعبئة التلقائية. يمكنك ملء الحقول يدويًا.',
        'autofill_errors' => [
            'unreachable' => 'تعذر الوصول إلى ذلك الموقع (:reason).',
            'http_status' => 'أعاد الموقع حالة غير متوقعة (:status).',
            'invalid_scheme' => 'يتم دعم روابط http وhttps فقط.',
            'missing_host' => 'الرابط يفتقد إلى المضيف.',
            'unresolvable_host' => 'تعذر التعرف على المضيف (:host).',
            'private_network' => 'الروابط التي تشير إلى الشبكات الخاصة غير مسموح بها.',
        ],
        'logo_captured' => 'تم التقاط الشعار من موقعك الإلكتروني.',
        'name' => 'اسم مساحة العمل',
        'name_placeholder' => 'مثال: Acme Inc',
        'brand_description' => 'وصف العلامة التجارية',
        'brand_description_placeholder' => 'ماذا تفعل علامتك التجارية؟',
        'content_language' => 'لغة المحتوى',
        'content_language_description' => 'ستُكتب التسميات التوضيحية المُنشأة بالذكاء الاصطناعي بهذه اللغة.',
        'brand_color' => 'لون العلامة التجارية',
        'background_color' => 'لون الخلفية',
        'text_color' => 'لون النص',
        'submit' => 'إنشاء مساحة عمل',
        'success' => 'تم إنشاء مساحة العمل. اربط حسابًا اجتماعيًا لبدء النشر.',
    ],

    'cannot_delete_last' => 'لا يمكنك حذف مساحة العمل الوحيدة لديك. ألغِ اشتراكك من إعدادات الفوترة لإغلاق حسابك.',

    'flash' => [
        'deleted' => 'تم حذف مساحة العمل بنجاح.',
    ],
];

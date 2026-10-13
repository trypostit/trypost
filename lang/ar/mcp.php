<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'اربط مساعدي الذكاء الاصطناعي بمساحتك.',
    'copy' => 'نسخ',
    'connect' => 'اتصال :client',
    'name_label' => 'الاسم',
    'url_label' => 'رابط الخادم',
    'config_label' => 'الإعداد',
    'tabs' => [
        'connected' => 'التطبيقات المتصلة',
        'connect' => 'ربط تطبيق',
    ],
    'connect_other' => 'ربط',
    'other_hint' => 'أي تطبيق يدعم MCP.',
    'other_dialog_title' => 'اربط TryPost بتطبيق آخر',
    'connected_description' => 'المساعدون الذين سجّلت الدخول إليهم. يمكنك قطع اتصال ما لم تعد تستخدمه.',
    'connected_empty_title' => 'لا توجد تطبيقات متصلة',
    'connected_empty' => 'اربط Claude أو ChatGPT أو تطبيقًا آخر لتراه هنا.',
    'disconnect' => 'قطع الاتصال',
    'disconnect_title' => 'قطع اتصال التطبيق',
    'disconnect_confirm' => 'يؤدي هذا إلى تسجيل خروج التطبيق من TryPost. سيحتاج إلى إعادة الاتصال قبل استخدام MCP مجددًا.',
    'disconnected' => 'تم قطع اتصال التطبيق.',
    'copied' => 'تم النسخ',
    'last_used' => 'آخر استخدام',
    'never' => 'أبدًا',
    'connector_name' => 'TryPost',

    'authorize' => [
        'page_title' => 'تفويض التطبيق - TryPost',
        'app_title' => 'تفويض MCP',
        'heading' => 'تفويض :client',
        'intro' => 'سيتمكن هذا التطبيق من:',
        'intro_capability' => 'استخدام وظائف MCP المتاحة.',
        'logged_in_as' => 'تم تسجيل الدخول باسم:',
        'workspace' => 'مساحة العمل:',
        'workspace_scope' => 'سيصل هذا الاتصال إلى مساحة العمل المحددة فقط.',
        'permissions' => 'الأذونات:',
        'cancel' => 'إلغاء',
        'approve' => 'تفويض',
        'approving' => 'جارٍ التفويض...',
        'select_workspace' => 'اختر مساحة عمل',
        'search_workspace' => 'ابحث في مساحات العمل...',
        'no_workspace_found' => 'لم يتم العثور على مساحة عمل',
        'scope_mcp_use' => 'استخدام خادم MCP',
        'error_page_title' => 'فشل التفويض - TryPost',
        'error_title' => 'تعذّر الاتصال',
        'error_body' => 'طلب التفويض هذا غير صالح أو منتهي الصلاحية. أغلق هذه النافذة وحاول الاتصال مجددًا من عميل MCP.',
        'error_code' => 'خطأ: :error',
    ],

    'other_clients_title' => 'تطبيقات أخرى',

    'dialog' => [
        'title' => 'اربط TryPost بـ :client',
        'description' => 'يستغرق الأمر دقيقة تقريبًا. ستتحدّث هذه الصفحة فور اتصالك.',
        'open' => 'افتح :client',
        'steps' => [
            'claude' => [
                'paste' => 'الصق الرابط في حقل رابط الموصل',
                'add' => 'انقر على إضافة ثم على اتصال',
                'login' => 'سجّل الدخول إلى TryPost واختر مساحة العمل',
            ],
            'chatgpt' => [
                'developer' => 'فعّل وضع المطوّر في إعدادات ChatGPT',
                'create' => 'أنشئ تطبيقًا باسم TryPost والصق الرابط',
                'login' => 'سجّل الدخول إلى TryPost واختر مساحة العمل',
            ],
            'other' => [
                'add' => 'أضف خادم MCP مخصصًا في تطبيقك',
                'paste' => 'الصق الاسم والرابط، أو الإعدادات',
                'login' => 'سجّل الدخول إلى TryPost واختر مساحة العمل',
            ],
        ],
        'waiting' => 'بانتظار اتصال الذكاء الاصطناعي…',
    ],

];

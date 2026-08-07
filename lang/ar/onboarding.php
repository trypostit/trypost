<?php

declare(strict_types=1);

return [
    'title' => 'البدء',
    'welcome' => 'مرحبًا بك في TryPost، :name',
    'welcome_anonymous' => 'مرحبًا بك في TryPost',
    'description' => 'اتبع الخطوات أدناه لمعرفة كيف يعمل TryPost ونشر منشورك الأول.',
    'skip_step' => 'تخطي هذه الخطوة',
    'continue' => 'المتابعة إلى TryPost',
    'status' => [
        'complete' => 'مكتمل',
        'todo' => 'مطلوب',
        'skipped' => 'تم تخطيه',
    ],
    'mcp' => [
        'title' => 'اربط مساعد الذكاء الاصطناعي',
        'description' => 'أضِف TryPost كخادم MCP ليتمكّن مساعدك من إنشاء منشورات التواصل وإدارتها نيابةً عنك.',
        'copy_step' => 'انسخ عنوان خادم TryPost',
        'open_step' => 'افتح مساعد الذكاء الاصطناعي',
        'copy' => 'نسخ الرابط',
        'copied' => 'تم نسخ رابط MCP.',
        'connect' => 'الاتصال عبر :client',
        'clients' => [
            'claude' => 'افتح Settings → Connectors، أضِف موصلًا مخصصًا، ثم الصق الرابط أعلاه.',
            'chatgpt' => 'افتح Settings → Apps & Connectors، أنشئ موصلًا مخصصًا، ثم الصق الرابط أعلاه.',
        ],
    ],
    'social' => [
        'title' => 'اربط حسابًا اجتماعيًا',
        'description' => 'اختر شبكة واحدة واحدة على الأقل يمكن لـ TryPost النشر عليها.',
        'connected_elsewhere' => 'لقد ربطت حسابًا في مساحة عمل أخرى بالفعل، لذا اكتملت هذه الخطوة.',
    ],
    'first_post' => [
        'title' => 'أنشئ منشورك الأول',
        'description' => 'جرّب هذا الموجّه مع مساعدك المتصل، أو أنشئ المنشور مباشرة في TryPost.',
        'prompt_label' => 'موجّه نموذجي',
        'sample_prompt' => 'أنشئ منشورًا اجتماعيًا ودّيًا يعرّف بعلامتي التجارية وكيّفه لكل شبكة متصلة.',
        'copy_prompt' => 'نسخ الموجّه',
        'copied' => 'تم نسخ الموجّه النموذجي.',
        'create_button' => 'إنشاء منشورك الأول',
        'or' => 'أو',
    ],
    'ready' => [
        'title' => 'أنت جاهز للنشر',
        'description' => 'كل شيء جاهز. تابع إلى TryPost وابدأ بتخطيط محتواك.',
    ],
];

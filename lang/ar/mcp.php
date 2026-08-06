<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'اربط مساعدي الذكاء الاصطناعي لإنشاء المنشورات وإدارتها بحساب TryPost الخاص بك.',
    'copy_step' => 'انسخ عنوان خادم TryPost',
    'open_step' => 'افتح مساعد الذكاء الاصطناعي',
    'copy' => 'نسخ الرابط',
    'connect' => 'الاتصال عبر :client',
    'step_add' => 'الصق الاسم أو الرابط أو الإعداد أدناه في تطبيقك. يفتح تسجيل الدخول في المتصفح عند أول اتصال.',
    'name_label' => 'الاسم',
    'url_label' => 'رابط الخادم',
    'config_label' => 'الإعداد',
    'connected_title' => 'التطبيقات المتصلة',
    'connected_description' => 'المساعدون الذين سجّلت الدخول إليهم. يمكنك قطع اتصال ما لم تعد تستخدمه.',
    'connected_empty' => 'لا يوجد اتصال بعد. استخدم Claude أو ChatGPT أو عميلًا آخر أعلاه.',
    'disconnect' => 'قطع الاتصال',
    'disconnect_title' => 'قطع اتصال التطبيق',
    'disconnect_confirm' => 'يؤدي هذا إلى تسجيل خروج التطبيق من TryPost. سيحتاج إلى إعادة الاتصال قبل استخدام MCP مجددًا.',
    'disconnected' => 'تم قطع اتصال التطبيق.',
    'copied' => 'تم النسخ',
    'last_used' => 'آخر استخدام',
    'never' => 'أبدًا',
    'documentation_title' => 'التوثيق',
    'documentation_description' => 'أدلة الإعداد لكل عميل، والأدوات المتاحة، وحل المشكلات.',
    'view_docs' => 'عرض التوثيق',
    'connector_name' => 'TryPost',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => 'تطبيقات أخرى',
    'other_clients_description' => 'Cursor وVS Code وClaude Code وأي تطبيق يدعم MCP.',

    'clients' => [
        'claude' => 'افتح Settings → Connectors، أضِف موصلًا مخصصًا، ثم الصق الرابط أعلاه.',
        'chatgpt' => 'افتح Settings → Apps & Connectors، أنشئ موصلًا مخصصًا، ثم الصق الرابط أعلاه.',
        'cursor' => 'أضف TryPost كخادم MCP بعيد في Cursor.',
        'cursor_name' => 'Cursor',
        'vscode' => 'الصق الإعداد أدناه في إعدادات MCP في VS Code.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'الصق الإعداد أدناه في إعدادات MCP في Claude Code.',
        'claude_code_name' => 'Claude Code',
        'other' => 'يعمل مع أي عميل يقرأ إعداد mcpServers.',
        'other_name' => 'أخرى',
    ],
];

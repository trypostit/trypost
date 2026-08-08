<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => 'منشورك جاهز',
        'body' => 'أنهى الذكاء الاصطناعي عمله للتو. انقر للمراجعة والنشر.',
    ],
    'account_disconnected' => [
        'title' => 'تم فصل حساب :platform',
        'body' => 'يحتاج :account إلى إعادة الربط',
    ],
    'account_token_expired' => [
        'title' => 'يحتاج حساب :platform إلى إعادة الربط',
        'body' => 'انتهت جلسة :account — يرجى إعادة الربط لمواصلة النشر',
    ],
    'post_at_risk' => [
        'title' => '{1} منشور واحد قادم معرض للخطر|{2} منشوران قادمان معرضان للخطر|[3,10] :count منشورات قادمة معرضة للخطر|[11,*] :count منشورًا قادمًا معرضًا للخطر',
    ],
];

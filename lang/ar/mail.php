<?php

return [
    'mentioned' => [
        'subject' => 'أشار إليك :name على TryPost',
        'title' => 'أشار إليك :name',
        'intro' => 'أشار إليك :name في تعليق على منشور.',
        'cta' => 'عرض التعليق',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} حساب واحد يحتاج إلى إعادة الربط في :workspace|{2} حسابان يحتاجان إلى إعادة الربط في :workspace|[3,10] :count حسابات تحتاج إلى إعادة الربط في :workspace|[11,*] :count حسابًا يحتاج إلى إعادة الربط في :workspace',
        'title' => 'حسابات تحتاج إلى إعادة الربط',
        'intro' => 'تم فصل الحسابات الاجتماعية التالية في مساحة العمل <strong>:workspace</strong> وتحتاج إلى إعادة الربط:',
        'reasons_title' => 'قد يكون هذا قد حدث بسبب:',
        'reason_expired' => 'انتهاء صلاحية رموز الوصول',
        'reason_revoked' => 'قيامك بإلغاء وصول TryPost على المنصة',
        'reason_changed' => 'قيام المنصة بتغيير متطلبات المصادقة الخاصة بها',
        'reconnect_cta' => 'يرجى إعادة ربط هذه الحسابات لمواصلة جدولة المنشورات ونشرها.',
        'button' => 'إعادة ربط الحسابات',
    ],
];

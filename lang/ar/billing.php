<?php

return [
    'title' => 'الفوترة',

    'past_due_notice' => [
        'title' => 'دفعة متأخرة',
        'description' => 'حدّث طريقة الدفع للحفاظ على اشتراكك نشطًا.',
        'cta' => 'تحديث الدفع',
    ],

    'annual_banner' => [
        'title' => 'احصل على شهرين مجانًا',
        'description' => 'انتقل إلى الفوترة السنوية وادفع أقل كل شهر — الخطة نفسها، لا شيء آخر يتغير.',
        'cta' => 'الترقية إلى السنوية',
    ],

    'subscribe' => [
        'billed_monthly' => 'فوترة شهرية',
        'billed_yearly' => 'فوترة سنوية',
        'prices' => [
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
        ],
    ],

    'plan' => [
        'title' => 'الخطة',
        'description' => 'إدارة خطة اشتراكك.',
        'label' => 'الخطة',
        'workspaces' => '{1}مساحة عمل واحدة|{2}مساحتا عمل|[3,10]:count مساحات عمل|[11,*]:count مساحة عمل',
        'per_workspace' => 'لكل مساحة عمل',
        'price' => 'السعر',
        'month' => 'شهر',
        'trial' => 'تجريبي',
        'active' => 'نشط',
        'past_due' => 'متأخر',
        'cancelling' => 'قيد الإلغاء',
        'trial_ends' => 'تنتهي الفترة التجريبية',
    ],

    'subscription' => [
        'title' => 'الاشتراك',
        'description' => 'إدارة طريقة الدفع وتفاصيل الفوترة والاشتراك.',
        'payment_method' => 'طريقة الدفع',
        'no_payment_method' => 'لا توجد طريقة دفع مسجّلة بعد.',
        'expires_on' => 'تنتهي في :month/:year',
        'manage_label' => 'الاشتراك',
        'manage_stripe' => 'الإدارة عبر Stripe',
    ],

    'invoices' => [
        'title' => 'الفواتير',
        'description' => 'نزّل فواتيرك السابقة.',
        'empty' => 'لم يتم العثور على فواتير',
        'paid' => 'مدفوعة',
    ],

    'flash' => [
        'plan_changed' => 'أنت الآن على خطة :plan.',
        'switched_to_yearly' => 'أنت الآن على الفوترة السنوية.',
        'cannot_manage' => 'يمكن لمالك الحساب فقط إدارة الفوترة.',
        'credits_exhausted' => 'نفد رصيد الذكاء الاصطناعي — تم استخدام مخصصك الشهري البالغ :limit. رقِّ خطتك أو انتظر حتى الشهر المقبل.',
        'subscription_required' => 'يلزم وجود اشتراك نشط لاستخدام ميزات الذكاء الاصطناعي.',
    ],

    'processing' => [
        'page_title' => 'جارٍ المعالجة...',
        'title' => 'جارٍ معالجة اشتراكك',
        'description' => 'يرجى الانتظار بينما نُعِدّ حسابك. لن يستغرق هذا سوى لحظة.',
        'success_title' => 'كل شيء جاهز!',
        'success_description' => 'اشتراكك نشط. جارٍ إعادة توجيهك إلى مساحات عملك...',
        'cancelled_title' => 'تم إلغاء الدفع',
        'cancelled_description' => 'تم إلغاء عملية الدفع. لم تُجرَ أي رسوم.',
        'retry' => 'إعادة المحاولة',
    ],
];

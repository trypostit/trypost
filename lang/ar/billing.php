<?php

return [
    'title' => 'الفوترة',

    'past_due_notice' => [
        'title' => 'دفعة متأخرة',
        'description' => 'حدّث طريقة الدفع للحفاظ على اشتراكك نشطًا.',
        'cta' => 'تحديث الدفع',
    ],

    'subscribe' => [
        'billed_monthly' => 'فوترة شهرية',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'description' => 'يمكنك الترقية أو التخفيض في أي وقت.',
        'monthly' => 'شهري',
        'yearly' => 'سنوي',
        'save_two_months' => 'شهران مجانًا',
        'per_month' => '/شهر',
        'per_year' => '/سنة',
        'workspaces_one' => 'مساحة عمل واحدة',
        'workspaces_unlimited' => 'مساحات عمل غير محدودة',
        'workspaces_tooltip' => 'مساحة العمل هي علامة تجارية أو عميل واحد، منفصلة عن البقية: بحساباتها الاجتماعية وتوقيعاتها وتصنيفاتها وتحليلاتها وصلاحيات أعضائها واتصال MCP الخاص بها.',
        'current' => 'الخطة الحالية',
        'switch_to_yearly' => 'التبديل إلى السنوي',
        'switch_to_monthly' => 'التبديل إلى الشهري',
        'select' => 'اختر :plan',
        'upgrade' => 'الترقية إلى :plan',
        'downgrade' => 'الرجوع إلى :plan',
        'start_first_month' => 'فعّل مقابل :price',
        'billed_yearly_total' => 'فوترة سنوية · :price (شهران مجانًا)',
        'socials_tagline' => 'مناسب لمنشئي المحتوى والعلامات الصغيرة.',
        'workspaces_tagline' => 'مناسب للوكالات والأعمال الكبيرة.',
        'everything_included' => 'كل شيء مشمول',
        'features' => [
            'networks_all' => 'جميع الشبكات الاجتماعية مشمولة',
            'networks_all_tooltip' => 'انشر عليها كلها من مكان واحد.',
            'accounts_unlimited' => 'حسابات اجتماعية غير محدودة',
            'accounts_unlimited_tooltip' => 'اربط أي عدد من الحسابات، حتى عدة حسابات من الشبكة نفسها. ثلاثة حسابات Instagram مثلًا.',
            'calendar' => 'تقويم المحتوى',
            'ideas' => 'تخطيط المحتوى',
            'ideas_tooltip' => 'دوّن أفكارك ونظّمها في مكان واحد وحوّل أفضلها إلى محتوى متى شئت.',
            'calendar_tooltip' => 'شاهد كل منشوراتك المجدولة والمنشورة في مكان واحد، حسب الشهر أو الأسبوع.',
            'ai' => 'مساعد كتابة بالذكاء الاصطناعي',
            'ai_tooltip' => 'اكتب منشوراتك وأعد صياغتها واضبط نبرتها بالذكاء الاصطناعي، بلغتك.',
            'mcp' => 'استخدم TryPost من Claude أو ChatGPT',
            'mcp_tooltip' => 'اربط Claude أو ChatGPT أو ذكاءً اصطناعيًا آخر. اطلب منه إنشاء المنشورات وجدولتها وملء قائمة الانتظار وتنظيم الأفكار وعرض نموك.',
            'repurpose' => 'إعادة نشر الفيديوهات تلقائيًا',
            'repurpose_tooltip' => 'نشرت فيديو على Instagram؟ يعيد TryPost نشره تلقائيًا على TikTok وYouTube وFacebook وشبكاتك الأخرى.',
            'analytics' => 'تحليلات متقدمة',
            'analytics_tooltip' => 'شاهد كل شبكاتك معًا، مثل إجمالي المتابعين، أو صفِّ حسب الشبكة والمنشور. المشاهدات والوصول والإعجابات والتعليقات وغيرها، لتعرف ما الذي ينجح.',
            'team' => 'أعضاء فريق غير محدودين',
            'team_tooltip' => 'ادعُ أي عدد من الأشخاص إلى فريقك دون تكلفة إضافية. حدّد ما يمكن لكل شخص فعله ووافق على المنشورات قبل نشرها.',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => 'نشطة',
            'trial' => 'تجربة مجانية',
            'cancelling' => 'ملغاة',
            'past_due' => 'متأخرة',
        ],
        'change' => 'تغيير الخطة',
        'price_monthly' => ':price/شهر',
        'price_yearly' => ':price/سنة',
        'trial_until' => 'فترة تجريبية حتى :date',
        'cancelled' => 'تم الإلغاء، ينتهي في :date',
        'discount_until' => 'خصم :discount حتى :date',
        'discount_forever' => 'خصم :discount على كل دفعة',
        'discount_once' => 'خصم :discount على دفعتك القادمة',
    ],

    'subscription' => [
        'title' => 'طرق الدفع',
        'default' => 'افتراضية',
        'description' => 'حدّث بطاقتك أو بيانات الفوترة على Stripe.',
        'no_payment_method' => 'لا توجد طريقة دفع مسجّلة بعد.',
        'expires_on' => 'تنتهي في :month/:year',
        'manage_stripe' => 'الإدارة عبر Stripe',
    ],

    'tabs' => [
        'plan' => 'الخطة',
        'invoices' => 'الفواتير',
    ],

    'invoices' => [
        'columns' => [
            'date' => 'التاريخ',
            'amount' => 'المبلغ',
            'status' => 'الحالة',
        ],
        'empty_title' => 'لا توجد فواتير بعد',
        'empty_description' => 'ستظهر فواتيرك هنا بعد أول دفعة.',
        'paid' => 'مدفوعة',
        'download' => 'تنزيل الفاتورة',
    ],

    'flash' => [
        'plan_changed' => 'أنت الآن على خطة :plan.',
        'cannot_manage' => 'يمكن لمالك الحساب فقط إدارة الفوترة.',
        'too_many_workspaces' => 'لديك :count من مساحات العمل. تتضمن هذه الخطة :limit — احذف الزائدة قبل التبديل.',
        'subscription_required' => 'يلزم وجود اشتراك نشط لاستخدام ميزات الذكاء الاصطناعي.',
    ],

    'processing' => [
        'page_title' => 'جارٍ المعالجة...',
        'title' => 'جارٍ معالجة اشتراكك',
        'description' => 'يرجى الانتظار بينما نُعِدّ حسابك. لن يستغرق هذا سوى لحظة.',
    ],
];

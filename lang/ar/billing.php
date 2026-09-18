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
        'title' => 'الخطط',
        'description' => 'يمكنك الترقية أو التخفيض في أي وقت.',
        'monthly' => 'شهري',
        'yearly' => 'سنوي',
        'save_two_months' => 'شهران مجانًا',
        'per_month' => '/شهر',
        'workspaces_one' => 'مساحة عمل واحدة',
        'workspaces_unlimited' => 'مساحات عمل غير محدودة',
        'workspaces_tooltip' => 'مساحة العمل هي علامة تجارية أو عميل واحد، منفصلة عن البقية: بحساباتها الاجتماعية وتوقيعاتها وتصنيفاتها وتحليلاتها وصلاحيات أعضائها واتصال MCP الخاص بها.',
        'current' => 'الخطة الحالية',
        'switch_to_yearly' => 'التبديل إلى السنوي',
        'switch_to_monthly' => 'التبديل إلى الشهري',
        'select' => 'اختر :plan',
        'upgrade' => 'الترقية إلى :plan',
        'downgrade' => 'الرجوع إلى :plan',
        'start_first_month' => 'ابدأ مقابل :price',
        'per_first_month' => '/الشهر الأول',
        'then_monthly' => 'ثم :price/شهر',
        'billed_yearly_total' => 'فوترة سنوية · :price (شهران مجانًا)',
        'socials_tagline' => 'مناسب لمنشئي المحتوى والعلامات الصغيرة.',
        'workspaces_tagline' => 'مناسب للوكالات والأعمال الكبيرة.',
        'everything_included' => 'كل شيء مشمول',
        'features' => [
            'networks_all' => 'جميع الشبكات الاجتماعية مشمولة',
            'networks_all_tooltip' => 'يمكنك النشر على كل هذه الشبكات.',
            'accounts_unlimited' => 'حسابات اجتماعية غير محدودة',
            'accounts_unlimited_tooltip' => 'اربط أي عدد من الحسابات، حتى عدة حسابات من الشبكة نفسها. ثلاثة حسابات Instagram مثلًا.',
            'calendar' => 'تقويم شهري وأسبوعي ويومي',
            'calendar_tooltip' => 'شاهد شهرك كاملاً بنظرة واحدة: ما هو مخطط، ومجدول، وما نُشر بالفعل. انتقل إلى عرض الأسبوع أو اليوم عندما تحتاج إلى التفاصيل.',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => 'مساعدك بالذكاء الاصطناعي لكتابة المنشورات ومراجعتها.',
            'mcp' => 'MCP: انشر من Claude أو ChatGPT أو Grok',
            'mcp_tooltip' => 'اربط Claude أو ChatGPT أو Grok بمساحة عملك. اطلب منه إنشاء المنشورات وجدولتها، وجلب المقاييس، ومعرفة الأفضل أداءً، والتخطيط للمحتوى التالي بناءً على بياناتك.',
            'repurpose' => 'Repurpose: حوّل منشورًا واحدًا إلى عدة منشورات',
            'repurpose_tooltip' => 'اختر حسابًا مصدرًا. كل منشور جديد تنشره هناك يُعاد نشره تلقائيًا على شبكاتك الأخرى. لا تحتاج إلى فتح TryPost.',
            'analytics' => 'التحليلات',
            'analytics_tooltip' => 'احصل على مقاييس مثل مرات الظهور والوصول والإعجابات والتعليقات لكل منشور ولكل حساب، كلها في مكان واحد.',
            'team' => 'أعضاء بلا حدود',
            'team_tooltip' => 'ادعُ أي عدد من الأشخاص إلى فريقك دون تكلفة إضافية. حدّد ما يمكن لكل شخص فعله ووافق على المنشورات قبل نشرها.',
        ],
    ],

    'plan' => [
        'trial' => 'تجريبي',
        'cancelling' => 'قيد الإلغاء',
        'trial_ends' => 'تنتهي الفترة التجريبية',
    ],

    'subscription' => [
        'title' => 'طريقة الدفع',
        'description' => 'حدّث بطاقتك أو بيانات الفوترة على Stripe.',
        'no_payment_method' => 'لا توجد طريقة دفع مسجّلة بعد.',
        'expires_on' => 'تنتهي في :month/:year',
        'manage_stripe' => 'الإدارة عبر Stripe',
    ],

    'invoices' => [
        'title' => 'الفواتير',
        'description' => 'نزّل فواتيرك السابقة.',
        'paid' => 'مدفوعة',
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

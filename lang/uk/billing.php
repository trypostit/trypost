<?php

return [
    'title' => 'Оплата',

    'past_due_notice' => [
        'title' => 'Прострочений платіж',
        'description' => 'Оновіть спосіб оплати, щоб зберегти активну підписку.',
        'cta' => 'Оновити оплату',
    ],

    'annual_banner' => [
        'title' => '2 місяці безкоштовно',
        'description' => 'Перейдіть на річну оплату та платіть менше щомісяця — той самий план, нічого більше не змінюється.',
        'cta' => 'Перейти на річну оплату',
    ],

    'subscribe' => [
        'billed_monthly' => 'Щомісячна оплата',
        'billed_yearly' => 'Річна оплата',
        'prices' => [
            'first_month' => '$1',
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => 'Плани',
        'description' => 'Підвищуйте або знижуйте план будь-коли.',
        'monthly' => 'Щомісяця',
        'yearly' => 'Щороку',
        'save_two_months' => '2 місяці безкоштовно',
        'per_month' => '/місяць',
        'workspaces_one' => '1 workspace',
        'workspaces_unlimited' => 'Необмежена кількість workspace',
        'current' => 'Поточний план',
        'select' => 'Обрати :plan',
        'start_first_month' => 'Почати перший місяць за :price',
        'first_month_then' => 'Перший місяць :first, далі :price/місяць',

        'billed_yearly_total' => 'Оплата раз на рік · :price (2 місяці безкоштовно)',
        'socials_tagline' => 'Один робочий простір. Публікуйте скрізь.',
        'workspaces_tagline' => 'Робочий простір для кожного бренду чи клієнта.',
        'features' => [
            'accounts_unlimited' => 'Необмежені акаунти',
            'calendar' => 'Візуальний календар з автопублікацією',
            'ai' => 'ШІ: тексти, зображення та голос бренду',
            'mcp' => 'MCP: створюйте й плануйте в Claude, ChatGPT або Grok',
            'repurpose' => 'Repurpose: з одного поста зробіть багато',
            'analytics' => 'Аналітика за постами та акаунтами',
            'team' => 'Необмежена команда, ролі та погодження',
        ],
    ],

    'plan' => [
        'title' => 'План',
        'description' => 'Керуйте своїм тарифним планом.',
        'label' => 'План',
        'price' => 'Ціна',
        'month' => 'місяць',
        'trial' => 'Пробний період',
        'active' => 'Активний',
        'past_due' => 'Прострочено',
        'cancelling' => 'Скасовується',
        'trial_ends' => 'Пробний період закінчується',
    ],

    'subscription' => [
        'title' => 'Підписка',
        'description' => 'Керуйте способом оплати, реквізитами та підпискою.',
        'payment_method' => 'Спосіб оплати',
        'no_payment_method' => 'Спосіб оплати ще не додано.',
        'expires_on' => 'Діє до :month/:year',
        'manage_label' => 'Підписка',
        'manage_stripe' => 'Керувати в Stripe',
    ],

    'invoices' => [
        'title' => 'Рахунки',
        'description' => 'Завантажуйте минулі рахунки.',
        'empty' => 'Рахунків не знайдено',
        'paid' => 'Оплачено',
    ],

    'flash' => [
        'plan_changed' => 'Ви перейшли на план :plan.',
        'switched_to_yearly' => 'Тепер у вас річна оплата.',
        'cannot_manage' => 'Лише власник облікового запису може керувати оплатою.',
        'too_many_workspaces' => 'У вас :count workspace. Цей план включає :limit — видаліть зайві перед зміною.',
        'subscription_required' => 'Для використання AI-функцій потрібна активна підписка.',
    ],

    'processing' => [
        'page_title' => 'Обробка...',
        'title' => 'Обробка вашої підписки',
        'description' => 'Зачекайте, поки ми налаштуємо ваш обліковий запис. Це займе лише мить.',
        'success_title' => 'Усе готово!',
        'success_description' => 'Ваша підписка активна. Перенаправляємо до ваших робочих просторів...',
        'cancelled_title' => 'Оформлення скасовано',
        'cancelled_description' => 'Оформлення підписки скасовано. Кошти не списано.',
        'retry' => 'Спробувати ще раз',
    ],
];

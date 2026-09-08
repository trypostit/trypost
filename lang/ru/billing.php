<?php

return [
    'title' => 'Оплата',

    'past_due_notice' => [
        'title' => 'Просроченный платёж',
        'description' => 'Обновите способ оплаты, чтобы сохранить подписку активной.',
        'cta' => 'Обновить оплату',
    ],

    'annual_banner' => [
        'title' => 'Получите 2 месяца бесплатно',
        'description' => 'Перейдите на годовую оплату и платите меньше каждый месяц — тот же тариф, ничего больше не меняется.',
        'cta' => 'Перейти на годовую оплату',
    ],

    'subscribe' => [
        'billed_monthly' => 'Ежемесячная оплата',
        'billed_yearly' => 'Годовая оплата',
        'prices' => [
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => 'Планы',
        'description' => 'Повышайте или понижайте план в любое время.',
        'monthly' => 'Ежемесячно',
        'yearly' => 'Ежегодно',
        'save_two_months' => '2 месяца бесплатно',
        'per_month' => '/месяц',
        'workspaces_one' => '1 workspace',
        'workspaces_unlimited' => 'Неограниченное число workspace',
        'current' => 'Текущий план',
        'select' => 'Выбрать :plan',
    ],

    'plan' => [
        'title' => 'Тариф',
        'description' => 'Управляйте своим тарифом подписки.',
        'label' => 'Тариф',
        'workspaces' => '{1}:count рабочее пространство|[2,4]:count рабочих пространства|[5,*]:count рабочих пространств',
        'per_workspace' => 'за рабочее пространство',
        'price' => 'Цена',
        'month' => 'месяц',
        'trial' => 'Пробный период',
        'active' => 'Активна',
        'past_due' => 'Просрочена',
        'cancelling' => 'Отменяется',
        'trial_ends' => 'Пробный период заканчивается',
    ],

    'subscription' => [
        'title' => 'Подписка',
        'description' => 'Управляйте способом оплаты, платёжными данными и подпиской.',
        'payment_method' => 'Способ оплаты',
        'no_payment_method' => 'Способ оплаты пока не добавлен.',
        'expires_on' => 'Истекает :month/:year',
        'manage_label' => 'Подписка',
        'manage_stripe' => 'Управлять в Stripe',
    ],

    'invoices' => [
        'title' => 'Счета',
        'description' => 'Скачивайте прошлые счета.',
        'empty' => 'Счета не найдены',
        'paid' => 'Оплачено',
    ],

    'flash' => [
        'plan_changed' => 'Вы перешли на тариф :plan.',
        'switched_to_yearly' => 'Вы перешли на годовую оплату.',
        'cannot_manage' => 'Управлять оплатой может только владелец аккаунта.',
        'too_many_workspaces' => 'У вас :count workspace. Этот план включает :limit — удалите лишние перед сменой.',
        'subscription_required' => 'Для использования функций ИИ требуется активная подписка.',
    ],

    'processing' => [
        'page_title' => 'Обработка...',
        'title' => 'Обрабатываем вашу подписку',
        'description' => 'Подождите, пока мы настраиваем ваш аккаунт. Это займёт всего мгновение.',
        'success_title' => 'Всё готово!',
        'success_description' => 'Ваша подписка активна. Перенаправляем вас к рабочим пространствам...',
        'cancelled_title' => 'Оформление отменено',
        'cancelled_description' => 'Оформление отменено. Списаний не было.',
        'retry' => 'Попробовать снова',
    ],
];

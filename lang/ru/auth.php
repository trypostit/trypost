<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'Эти учётные данные не совпадают с нашими записями.',
    'password' => 'Указанный пароль неверен.',
    'throttle' => 'Слишком много попыток входа. Повторите попытку через :seconds сек.',

    'flash' => [
        'welcome' => 'Добро пожаловать в TryPost!',
        'welcome_trial' => 'Добро пожаловать в TryPost! Ваш пробный период начался.',
    ],

    'legal' => 'Продолжая, вы соглашаетесь с нашими <a href=":terms_url" target="_blank">Условиями использования</a> и <a href=":privacy_url" target="_blank">Политикой конфиденциальности</a>.',

    'reviews' => [
        'eyebrow' => '5/5 на G2',
        'heading' => 'Выбор тех, кто публикует',
        'paulo_dantas' => [
            'role' => 'Основатель, chatadv.com.br',
            'quote' => 'Простота создания, организации и распространения контента во всех социальных сетях. С MCP мы можем использовать любой ИИ — Claude или ChatGPT, — чтобы создавать контент и сразу планировать публикации.',
        ],
        'diego' => [
            'role' => 'CEO, Globalfy.com',
            'quote' => 'Мне нравится, насколько TryPost прост. Я создаю пост прямо в Claude, а затем через MCP публикую его и планирую на будущее. Настройка заняла пять минут.',
        ],
        'luiz' => [
            'role' => 'Контент-креатор',
            'quote' => 'Обожаю, насколько легко подключить мои ИИ-инструменты и агентов и запланировать посты в 9 социальных сетях за пару минут.',
        ],
        'pedro' => [
            'role' => 'Основатель, templated.io',
            'quote' => 'Очень легко использовать и интегрировать. С MCP интерфейс нужен мне только для подключения аккаунтов соцсетей.',
        ],
        'paulo_castellano' => [
            'role' => 'Основатель, changelogfy.com',
            'quote' => 'Обожаю интеграцию с MCP: она позволяет управлять всеми моими аккаунтами в соцсетях прямо из Claude или ChatGPT.',
        ],
    ],

    'or_continue_with' => 'Или продолжите через',
    'or_continue_with_email' => 'Или продолжите через email',
    'google_login' => 'Войти через Google',
    'google_signup' => 'Зарегистрироваться через Google',
    'github_login' => 'Войти через GitHub',
    'github_signup' => 'Зарегистрироваться через GitHub',
    'github_email_unavailable' => 'Не удалось получить ваш email из GitHub. Сделайте email в GitHub публичным или предоставьте доступ к email, затем попробуйте снова.',

    'login' => [
        'title' => 'Войдите в свой аккаунт',
        'description' => 'Введите email и пароль, чтобы войти',
        'page_title' => 'Вход',
        'email' => 'Адрес email',
        'password' => 'Пароль',
        'show_password' => 'Показать пароль',
        'hide_password' => 'Скрыть пароль',
        'forgot_password' => 'Забыли пароль?',
        'remember_me' => 'Запомнить меня',
        'submit' => 'Войти',
        'no_account' => 'Нет аккаунта?',
        'sign_up' => 'Зарегистрироваться',
    ],

    'register' => [
        'title' => 'Весь ваш контент-календарь в одном месте',
        'description' => 'Создайте аккаунт и начните планировать посты во всех сетях.',
        'page_title' => 'Регистрация',
        'signup_with_email' => 'Зарегистрироваться через email',
        'name' => 'Имя',
        'name_placeholder' => 'Полное имя',
        'email' => 'Адрес email',
        'password' => 'Пароль',
        'show_password' => 'Показать пароль',
        'hide_password' => 'Скрыть пароль',
        'submit' => 'Создать аккаунт',
        'has_account' => 'Уже есть аккаунт?',
        'log_in' => 'Войти',
    ],

    'forgot_password' => [
        'title' => 'Восстановление пароля',
        'description' => 'Введите email, чтобы получить ссылку для сброса пароля',
        'page_title' => 'Восстановление пароля',
        'email' => 'Адрес email',
        'submit' => 'Отправить ссылку для сброса',
        'return_to' => 'Или вернитесь ко',
        'log_in' => 'входу',
    ],

    'reset_password' => [
        'title' => 'Сброс пароля',
        'description' => 'Введите новый пароль ниже',
        'page_title' => 'Сброс пароля',
        'email' => 'Email',
        'password' => 'Пароль',
        'confirm_password' => 'Подтвердите пароль',
        'confirm_placeholder' => 'Подтвердите пароль',
        'submit' => 'Сбросить пароль',
    ],

    'verify_email' => [
        'title' => 'Подтверждение email',
        'description' => 'Пожалуйста, подтвердите свой email, перейдя по ссылке, которую мы только что вам отправили.',
        'page_title' => 'Подтверждение email',
        'link_sent' => 'Новая ссылка для подтверждения отправлена на email, указанный при регистрации.',
        'resend' => 'Отправить письмо повторно',
        'log_out' => 'Выйти',
    ],

    'accept_invite' => [
        'page_title' => 'Принять приглашение',
        'title' => 'Вас пригласили!',
        'description' => 'Вас пригласили присоединиться к рабочему пространству :workspace.',
        'workspace' => 'Рабочее пространство',
        'your_role' => 'Ваша роль',
        'email' => 'Email',
        'accept' => 'Принять приглашение',
        'decline' => 'Отклонить приглашение',
        'login_prompt' => 'Войдите или создайте аккаунт, чтобы принять приглашение.',
        'log_in' => 'Войти',
        'create_account' => 'Создать аккаунт',
        'expired_title' => 'Это приглашение больше недействительно',
        'expired_description' => 'Рабочее пространство для этого приглашения было удалено. Попросите владельца аккаунта прислать новое приглашение, если доступ всё ещё нужен.',
        'expired_action' => 'На главную',
    ],

];

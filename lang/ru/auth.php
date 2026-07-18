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

    'legal' => 'Продолжая, вы соглашаетесь с нашими <a href="https://trypost.it/terms" target="_blank">Условиями использования</a> и <a href="https://trypost.it/privacy" target="_blank">Политикой конфиденциальности</a>.',

    'slides' => [
        'calendar' => [
            'title' => 'Наглядный календарь',
            'description' => 'Планируйте и назначайте контент с помощью удобного календаря с перетаскиванием для всех ваших социальных аккаунтов.',
        ],
        'scheduling' => [
            'title' => 'Умное планирование',
            'description' => 'Планируйте посты в LinkedIn, X, Instagram, TikTok, YouTube и других сетях — всё в одном месте.',
        ],
        'media' => [
            'title' => 'Богатый медиаконтент',
            'description' => 'Публикуйте изображения, карусели, истории и Reels. Для каждой платформы автоматически подбирается нужный формат.',
        ],
        'video' => [
            'title' => 'Публикация видео',
            'description' => 'Загрузите видео один раз и публикуйте в TikTok, YouTube Shorts, Instagram Reels и Facebook Reels.',
        ],
        'team' => [
            'title' => 'Командные пространства',
            'description' => 'Приглашайте команду, назначайте роли и управляйте несколькими брендами из отдельных рабочих пространств.',
        ],
        'signatures' => [
            'title' => 'Подписи',
            'description' => 'Сохраняйте многократно используемые подписи (хэштеги, ссылки, завершающие фразы) и добавляйте их к постам в один клик.',
        ],
    ],

    'or_continue_with' => 'Или продолжите через',
    'or_continue_with_email' => 'Или продолжите через email',
    'google_login' => 'Войти через Google',
    'google_signup' => 'Зарегистрироваться через Google',
    'github_login' => 'Войти через GitHub',
    'github_signup' => 'Зарегистрироваться через GitHub',
    'github_email_unavailable' => 'Не удалось получить ваш email из GitHub. Сделайте email в GitHub публичным или предоставьте доступ к email, затем попробуйте снова.',

    'signup_success' => [
        'page_title' => 'Добро пожаловать',
        'title' => 'Настраиваем ваш аккаунт',
        'description' => 'Обычно это занимает всего несколько секунд...',
    ],

    'login' => [
        'title' => 'Войдите в свой аккаунт',
        'description' => 'Введите email и пароль, чтобы войти',
        'page_title' => 'Вход',
        'email' => 'Адрес email',
        'password' => 'Пароль',
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
    ],

];

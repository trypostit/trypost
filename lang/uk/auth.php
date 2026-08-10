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

    'failed' => 'Ці облікові дані не відповідають нашим записам.',
    'password' => 'Наданий пароль неправильний.',
    'throttle' => 'Забагато спроб входу. Спробуйте ще раз через :seconds сек.',

    'flash' => [
        'welcome' => 'Ласкаво просимо до TryPost!',
        'welcome_trial' => 'Ласкаво просимо до TryPost! Ваш пробний період розпочато.',
    ],

    'legal' => 'Продовжуючи, ви погоджуєтеся з нашими <a href="https://trypost.it/terms" target="_blank">Умовами використання</a> та <a href="https://trypost.it/privacy" target="_blank">Політикою конфіденційності</a>.',

    'slides' => [
        'calendar' => [
            'title' => 'Візуальний календар',
            'description' => 'Плануйте та плануйте контент за допомогою інтуїтивного календаря з перетягуванням для всіх ваших соціальних акаунтів.',
        ],
        'scheduling' => [
            'title' => 'Розумне планування',
            'description' => 'Плануйте пости в LinkedIn, X, Instagram, TikTok, YouTube та інших — усе в одному місці.',
        ],
        'media' => [
            'title' => 'Багатий медіаконтент',
            'description' => 'Публікуйте зображення, каруселі, stories та reels. Кожна платформа автоматично отримує правильний формат.',
        ],
        'video' => [
            'title' => 'Публікація відео',
            'description' => 'Завантажуйте відео один раз і публікуйте в TikTok, YouTube Shorts, Instagram Reels та Facebook Reels.',
        ],
        'team' => [
            'title' => 'Командні робочі простори',
            'description' => 'Запрошуйте команду, призначайте ролі та керуйте кількома брендами в окремих робочих просторах.',
        ],
        'signatures' => [
            'title' => 'Підписи',
            'description' => 'Зберігайте багаторазові підписи (хештеги, посилання, підписи) і додавайте їх до постів одним кліком.',
        ],
    ],

    'or_continue_with' => 'Або продовжити через',
    'or_continue_with_email' => 'Або продовжити через email',
    'google_login' => 'Увійти через Google',
    'google_signup' => 'Зареєструватися через Google',
    'github_login' => 'Увійти через GitHub',
    'github_signup' => 'Зареєструватися через GitHub',
    'github_email_unavailable' => 'Не вдалося отримати ваш email з GitHub. Зробіть email публічним або надайте доступ до email, потім спробуйте ще раз.',

    'signup_success' => [
        'page_title' => 'Ласкаво просимо',
        'title' => 'Налаштовуємо ваш обліковий запис',
        'description' => 'Зазвичай це займає лише кілька секунд...',
    ],

    'login' => [
        'title' => 'Увійдіть до облікового запису',
        'description' => 'Введіть email і пароль нижче, щоб увійти',
        'page_title' => 'Вхід',
        'email' => 'Адреса email',
        'password' => 'Пароль',
        'show_password' => 'Показати пароль',
        'hide_password' => 'Приховати пароль',
        'forgot_password' => 'Забули пароль?',
        'remember_me' => 'Запам’ятати мене',
        'submit' => 'Увійти',
        'no_account' => 'Немає облікового запису?',
        'sign_up' => 'Зареєструватися',
    ],

    'register' => [
        'title' => 'Увесь ваш соціальний календар в одному місці',
        'description' => 'Створіть обліковий запис і почніть планувати пости в усіх мережах.',
        'page_title' => 'Реєстрація',
        'signup_with_email' => 'Зареєструватися через email',
        'name' => 'Ім’я',
        'name_placeholder' => 'Повне ім’я',
        'email' => 'Адреса email',
        'password' => 'Пароль',
        'show_password' => 'Показати пароль',
        'hide_password' => 'Приховати пароль',
        'submit' => 'Створити обліковий запис',
        'has_account' => 'Уже маєте обліковий запис?',
        'log_in' => 'Увійти',
    ],

    'forgot_password' => [
        'title' => 'Забули пароль',
        'description' => 'Введіть email, щоб отримати посилання для скидання пароля',
        'page_title' => 'Забули пароль',
        'email' => 'Адреса email',
        'submit' => 'Надіслати посилання для скидання',
        'return_to' => 'Або повернутися до',
        'log_in' => 'входу',
    ],

    'reset_password' => [
        'title' => 'Скинути пароль',
        'description' => 'Введіть новий пароль нижче',
        'page_title' => 'Скинути пароль',
        'email' => 'Email',
        'password' => 'Пароль',
        'confirm_password' => 'Підтвердіть пароль',
        'confirm_placeholder' => 'Підтвердіть пароль',
        'submit' => 'Скинути пароль',
    ],

    'verify_email' => [
        'title' => 'Підтвердіть email',
        'description' => 'Підтвердіть адресу email, натиснувши на посилання, яке ми щойно надіслали.',
        'page_title' => 'Підтвердження email',
        'link_sent' => 'Нове посилання для підтвердження надіслано на email, який ви вказали під час реєстрації.',
        'resend' => 'Надіслати лист підтвердження ще раз',
        'log_out' => 'Вийти',
    ],

    'accept_invite' => [
        'page_title' => 'Прийняти запрошення',
        'title' => 'Вас запросили!',
        'description' => 'Вас запросили приєднатися до робочого простору :workspace.',
        'workspace' => 'Робочий простір',
        'your_role' => 'Ваша роль',
        'email' => 'Email',
        'accept' => 'Прийняти запрошення',
        'decline' => 'Відхилити запрошення',
        'login_prompt' => 'Увійдіть або створіть обліковий запис, щоб прийняти це запрошення.',
        'log_in' => 'Увійти',
        'create_account' => 'Створити обліковий запис',
        'expired_title' => 'Це запрошення більше не дійсне',
        'expired_description' => 'Робочий простір для цього запрошення було видалено. Попросіть власника облікового запису надіслати нове запрошення, якщо вам ще потрібен доступ.',
        'expired_action' => 'На головну',
    ],

];

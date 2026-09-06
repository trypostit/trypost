<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'Автоматично публікуйте в інших мережах відео, які ви викладаєте поза TryPost.',
    'new' => 'Новий repurpose',

    'flow' => [
        'no_destinations' => 'Ще немає призначення',
    ],

    'publish_mode' => [

        'title' => 'Публікація',

        'description' => 'Що відбувається, коли з\'являється нове відео.',

    ],

    'publish_modes' => [

        'publish' => 'Публікувати автоматично',

        'publish_hint' => 'Кожне нове відео планується одразу після виявлення.',

        'draft' => 'Створювати чернетку',

        'draft_hint' => 'Кожне нове відео стає тут чернеткою для перевірки та публікації.',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Відео',
        'story' => 'Stories',
    ],

    'source' => [
        'title' => 'Джерело',
        'description' => 'TryPost стежить за цим акаунтом і шукає нові відео обраного нижче формату.',
        'watch_label' => 'Відстежувати',
    ],

    'summary' => [
        'sentence' => 'Кожне нове :format, опубліковане в :source, повторюється в :destinations.',
        'no_destinations' => 'Кожне нове :format у :source чекає на призначення.',
    ],

    'empty' => [
        'title' => 'Repurpose ще не налаштовано',
        'description' => 'Оберіть відправну точку нижче. TryPost стежить за обраним акаунтом і повторно публікує кожне нове відео в позначених мережах.',
    ],

    'table' => [
        'flow' => 'Потік',
        'source' => 'Джерело',
        'destinations' => 'Призначення',
        'status' => 'Статус',
        'published' => 'Скопійовано',
        'last_polled' => 'Остання перевірка',
    ],

    'status' => [
        'draft' => 'Чернетка',
        'active' => 'Активний',
        'paused' => 'Призупинено',
        'disabled' => 'Вимкнено',
    ],

    'templates' => [
        'use' => 'Використати шаблон',
        'instagram_everywhere' => [
            'title' => 'Instagram усюди',
            'description' => 'Опублікуйте Reel в Instagram, і TryPost повторить його в TikTok, YouTube Shorts і Facebook.',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook усюди',
            'description' => 'Опублікуйте відео на своїй сторінці Facebook, і TryPost повторить його в Instagram, TikTok і YouTube Shorts.',
        ],
    ],

    'create' => [
        'title' => 'Новий repurpose',
        'description' => 'Оберіть акаунт, за яким стежитиме TryPost. Призначення обираються на наступному екрані.',
        'source_label' => 'Акаунт-джерело',
        'source_placeholder' => 'Виберіть обліковий запис',
        'source_search' => 'Пошук облікових записів',
        'source_empty' => 'Обліковий запис не знайдено.',
        'source_placeholder' => 'Оберіть акаунт',
        'no_accounts' => 'Спершу підключіть акаунт Instagram або Facebook. Лише вони можуть бути джерелом, бо тільки ці мережі дозволяють завантажити відео.',
        'submit' => 'Створити',
        'connect' => 'Підключити акаунт',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'Відео, опубліковані на цьому акаунті поза TryPost, копіюються в призначення нижче.',
    ],

    'tabs' => [
        'configuration' => 'Налаштування',
        'activity' => 'Активність',
        'settings' => 'Налаштування',
    ],

    'destinations' => [
        'title' => 'Призначення',
        'description' => 'Оберіть акаунти-отримувачі. Кожен публікує в обраному вами форматі.',
        'hint' => 'Підпис адаптується під мережу лише тоді, коли перевищує її ліміт.',
        'none_available' => 'У цьому робочому просторі поки немає інших підключених акаунтів.',
        'save' => 'Зберегти призначення',
        'saved' => 'Призначення збережено',
        'publish_as' => 'Публікувати як',
    ],

    'status_card' => [
        'title' => 'Статус',
        'activate' => 'Активувати',
        'pause' => 'Призупинити',
        'resume' => 'Відновити',
        'disable' => 'Вимкнути',
        'watermark' => 'Відстежується з',
        'last_polled' => 'Остання перевірка',
        'draft_hint' => 'Оберіть щонайменше одне призначення та активуйте. Копіюються лише відео, опубліковані після активації.',
        'active_hint' => 'TryPost регулярно перевіряє цей акаунт і копіює кожне нове відео.',
        'paused_hint' => 'Перевірки призупинено. Відновлення продовжить з місця зупинки, нічого не втратиться.',
        'disabled_hint' => 'Вимкнено. Повторна активація почне з нуля: опубліковане за цей час залишиться осторонь.',
    ],

    'items' => [
        'source' => 'Оригінал',
        'published_at' => 'Опубліковано',
        'status' => 'Статус',
        'detail' => 'Деталі',
        'posts' => 'Скопійовано в',
        'view_original' => 'Відкрити оригінал',
        'open_post' => 'Відкрити допис',
        'statuses' => [
            'pending' => 'У черзі',
            'processing' => 'Обробка',
            'published' => 'Скопійовано',
            'drafted' => 'Чернетка',
            'skipped' => 'Пропущено',
            'failed' => 'Помилка',
        ],
        'reasons' => [
            'published_via_trypost' => 'Уже опубліковано через TryPost',
            'media_url_missing' => 'Мережа не надала файл для завантаження, зазвичай через захищене авторським правом аудіо',
            'download_failed' => 'Не вдалося завантажити відео',
            'post_creation_failed' => 'Немає доступного призначення',
        ],
    ],

    'danger' => [
        'title' => 'Видалити цей repurpose',
        'description' => 'Перевірки припиняться одразу. Уже створені дописи залишаться в календарі.',
        'delete' => 'Видалити repurpose',
    ],

    'errors' => [
        'source_already_used' => 'Цей акаунт уже живить інший repurpose. Відредагуйте його.',
        'destinations_required' => 'Оберіть щонайменше одне призначення перед активацією.',
        'destination_needs_video' => 'Цей формат не приймає відео.',
        'only_paused_resumes' => 'Відновити можна лише призупинений repurpose.',
        'only_active_pauses' => 'Призупинити можна лише активний repurpose.',
        'only_running_disables' => 'Вимкнути можна лише той repurpose, що працює.',
        'only_idle_activates' => 'Активувати можна лише чернетку або вимкнений repurpose.',
        'destination_unavailable' => 'Цей акаунт-отримувач більше недоступний.',
        'destination_is_source' => 'Це призначення — той самий обліковий запис, за яким стежить цей repurpose.',
        'source_unavailable' => 'Цей обліковий запис-джерело більше недоступний.',
        'action_failed' => 'Щось пішло не так. Перевірте форму та спробуйте ще раз.',
    ],
];

<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'Автоматически публикуйте в других сетях видео, которые вы выкладываете вне TryPost.',
    'new' => 'Новый repurpose',

    'flow' => [
        'no_destinations' => 'Пока нет назначения',
    ],

    'publish_mode' => [

        'title' => 'Публикация',

        'description' => 'Что происходит, когда появляется новое видео.',

    ],

    'publish_modes' => [

        'publish' => 'Публиковать автоматически',

        'publish_hint' => 'Каждое новое видео планируется сразу после обнаружения.',

        'draft' => 'Создавать черновик',

        'draft_hint' => 'Каждое новое видео станет здесь черновиком для проверки и публикации.',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Видео',
        'story' => 'Stories',
    ],

    'source' => [
        'title' => 'Источник',
        'description' => 'TryPost следит за этим аккаунтом и ищет новые видео выбранного ниже формата.',
        'watch_label' => 'Отслеживать',
    ],

    'summary' => [
        'sentence' => 'Каждое новое :format, опубликованное в :source, повторяется в :destinations.',
        'no_destinations' => 'Каждое новое :format в :source ждёт назначения.',
    ],

    'empty' => [
        'title' => 'Repurpose ещё не настроен',
        'description' => 'Выберите отправную точку ниже. TryPost следит за выбранным аккаунтом и заново публикует каждое новое видео в отмеченных сетях.',
    ],

    'table' => [
        'flow' => 'Поток',
        'source' => 'Источник',
        'destinations' => 'Назначения',
        'status' => 'Статус',
        'published' => 'Скопировано',
        'last_polled' => 'Последняя проверка',
    ],

    'status' => [
        'draft' => 'Черновик',
        'active' => 'Активен',
        'paused' => 'На паузе',
        'disabled' => 'Отключён',
    ],

    'templates' => [
        'use' => 'Использовать шаблон',
        'instagram_everywhere' => [
            'title' => 'Instagram везде',
            'description' => 'Опубликуйте Reel в Instagram, и TryPost повторит его в TikTok, YouTube Shorts и Facebook.',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook везде',
            'description' => 'Опубликуйте видео на своей странице Facebook, и TryPost повторит его в Instagram, TikTok и YouTube Shorts.',
        ],
    ],

    'create' => [
        'title' => 'Новый repurpose',
        'description' => 'Выберите аккаунт, за которым будет следить TryPost. Назначения выбираются на следующем экране.',
        'source_label' => 'Аккаунт-источник',
        'source_placeholder' => 'Выберите аккаунт',
        'source_search' => 'Поиск аккаунтов',
        'source_empty' => 'Аккаунт не найден.',
        'source_placeholder' => 'Выберите аккаунт',
        'no_accounts' => 'Сначала подключите аккаунт Instagram или Facebook. Только они могут быть источником, потому что только эти сети позволяют скачать видео.',
        'submit' => 'Создать',
        'connect' => 'Подключить аккаунт',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'Видео, опубликованные на этом аккаунте вне TryPost, копируются в назначения ниже.',
    ],

    'tabs' => [
        'configuration' => 'Настройка',
        'activity' => 'Активность',
        'settings' => 'Настройки',
    ],

    'destinations' => [
        'title' => 'Назначения',
        'description' => 'Выберите аккаунты-получатели. Каждый публикует в выбранном вами формате.',
        'hint' => 'Подпись адаптируется под сеть только тогда, когда превышает её лимит.',
        'none_available' => 'В этом рабочем пространстве пока нет других подключённых аккаунтов.',
        'save' => 'Сохранить назначения',
        'saved' => 'Назначения сохранены',
        'publish_as' => 'Публиковать как',
    ],

    'status_card' => [
        'title' => 'Статус',
        'activate' => 'Активировать',
        'pause' => 'Пауза',
        'resume' => 'Возобновить',
        'disable' => 'Отключить',
        'watermark' => 'Отслеживается с',
        'last_polled' => 'Последняя проверка',
        'draft_hint' => 'Выберите хотя бы одно назначение и активируйте. Копируются только видео, опубликованные после активации.',
        'active_hint' => 'TryPost регулярно проверяет этот аккаунт и копирует каждое новое видео.',
        'paused_hint' => 'Проверки приостановлены. Возобновление продолжит с места остановки, ничего не потеряется.',
        'disabled_hint' => 'Выключено. Повторная активация начнёт с нуля: опубликованное в это время останется в стороне.',
    ],

    'items' => [
        'source' => 'Оригинал',
        'published_at' => 'Опубликовано',
        'status' => 'Статус',
        'detail' => 'Детали',
        'posts' => 'Скопировано в',
        'view_original' => 'Открыть оригинал',
        'open_post' => 'Открыть пост',
        'statuses' => [
            'pending' => 'В очереди',
            'processing' => 'Обработка',
            'published' => 'Скопировано',
            'drafted' => 'Черновик',
            'skipped' => 'Пропущено',
            'failed' => 'Ошибка',
        ],
        'reasons' => [
            'published_via_trypost' => 'Уже опубликовано через TryPost',
            'media_url_missing' => 'Сеть не предоставила файл для скачивания, обычно из-за защищённого авторским правом аудио',
            'download_failed' => 'Не удалось скачать видео',
            'post_creation_failed' => 'Нет доступного назначения',
        ],
    ],

    'danger' => [
        'title' => 'Удалить этот repurpose',
        'description' => 'Проверки прекратятся сразу. Уже созданные посты останутся в календаре.',
        'delete' => 'Удалить repurpose',
    ],

    'errors' => [
        'source_already_used' => 'Этот аккаунт уже используется в другом repurpose. Отредактируйте его.',
        'destinations_required' => 'Выберите хотя бы одно назначение перед активацией.',
        'destination_needs_video' => 'Этот формат не принимает видео.',
        'only_paused_resumes' => 'Возобновить можно только приостановленный repurpose.',
        'only_active_pauses' => 'Приостановить можно только активный repurpose.',
        'only_running_disables' => 'Отключить можно только работающий repurpose.',
        'only_idle_activates' => 'Активировать можно только черновик или отключённый repurpose.',
        'destination_unavailable' => 'Этот аккаунт-получатель больше недоступен.',
        'destination_is_source' => 'Это назначение — тот же аккаунт, за которым следит этот repurpose.',
        'source_unavailable' => 'Этот исходный аккаунт больше недоступен.',
        'action_failed' => 'Что-то пошло не так. Проверьте форму и повторите.',
    ],
];

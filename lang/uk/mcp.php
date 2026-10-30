<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Підключіть ШІ-асистентів до простору.',
    'copy' => 'Копіювати',
    'connect' => 'Підключити :client',
    'name_label' => 'Назва',
    'url_label' => 'URL сервера',
    'config_label' => 'Config',
    'tabs' => [
        'connected' => 'Підключені застосунки',
        'connect' => 'Підключити застосунок',
    ],
    'connect_other' => 'Підключити',
    'other_hint' => 'Будь-який застосунок із підтримкою MCP.',
    'other_dialog_title' => 'Підключіть TryPost до іншого застосунку',
    'connected_description' => 'Асистенти, у які ви увійшли. Від’єднайте ті, якими більше не користуєтесь.',
    'connected_empty_title' => 'Немає підключених застосунків',
    'connected_empty' => 'Підключіть Claude, ChatGPT або інший застосунок, щоб побачити його тут.',
    'disconnect' => 'Від’єднати',
    'disconnect_title' => 'Від’єднати застосунок',
    'disconnect_confirm' => 'Це вийде з TryPost у застосунку. Потрібно буде підключитися знову, щоб знову використовувати MCP.',
    'disconnected' => 'Застосунок від’єднано.',
    'copied' => 'Скопійовано',
    'last_used' => 'Востаннє використано',
    'never' => 'Ніколи',
    'connector_name' => 'TryPost',

    'authorize' => [
        'page_title' => 'Авторизація застосунку - TryPost',
        'app_title' => 'Авторизація MCP',
        'heading' => 'Авторизувати :client',
        'intro' => 'Цей застосунок зможе:',
        'intro_capability' => 'Використовувати доступні функції MCP.',
        'logged_in_as' => 'Ви увійшли як:',
        'workspace' => 'Робочий простір:',
        'workspace_scope' => 'Це підключення матиме доступ лише до вибраного простору.',
        'permissions' => 'Дозволи:',
        'cancel' => 'Скасувати',
        'approve' => 'Дозволити',
        'approving' => 'Авторизація...',
        'select_workspace' => 'Виберіть робочий простір',
        'search_workspace' => 'Пошук просторів...',
        'no_workspace_found' => 'Простір не знайдено',
        'scope_mcp_use' => 'Використовувати сервер MCP',
        'error_page_title' => 'Помилка авторизації - TryPost',
        'error_title' => 'Не вдалося підключитися',
        'error_body' => 'Цей запит на авторизацію недійсний або прострочений. Закрийте вікно і спробуйте підключитися знову з клієнта MCP.',
        'error_code' => 'Помилка: :error',
    ],

    'other_clients_title' => 'Інші застосунки',

    'dialog' => [
        'title' => 'Підключіть TryPost до :client',
        'description' => 'Це займе близько хвилини. Сторінка оновиться, щойно ви підключитеся.',
        'open' => 'Відкрити :client',
        'steps' => [
            'claude' => [
                'paste' => 'Вставте URL у поле URL конектора',
                'add' => 'Натисніть «Додати», потім «Підключити»',
                'login' => 'Увійдіть у TryPost і виберіть workspace',
            ],
            'chatgpt' => [
                'open' => 'Відкрийте TryPost у ChatGPT і натисніть «Підключити»',
                'login' => 'Увійдіть у TryPost і виберіть workspace',
            ],
            'other' => [
                'add' => 'Додайте власний MCP-сервер у застосунку',
                'paste' => 'Вставте назву й URL або конфігурацію',
                'login' => 'Увійдіть у TryPost і виберіть workspace',
            ],
        ],
        'waiting' => 'Чекаємо на підключення вашого ШІ…',
    ],

];

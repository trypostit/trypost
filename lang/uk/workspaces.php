<?php

declare(strict_types=1);

return [
    'title' => 'Робочі простори',
    'select_title' => 'Ваші робочі простори',
    'select_description' => 'Виберіть робочий простір, щоб продовжити',
    'current' => 'Поточний',
    'connections' => ':count підключень',
    'posts' => ':count постів',

    'create' => [
        'page_title' => 'Створіть свій робочий простір',
        'title' => 'Налаштуйте робочий простір',
        'description' => 'Розкажіть трохи про себе або свій проєкт. Ми використаємо це, щоб адаптувати AI-пости під ваш стиль.',
        'website' => 'Вебсайт',
        'website_placeholder' => 'https://yourbrand.com',
        'autofill' => 'Заповнити з вебсайту',
        'autofill_missing_url' => 'Спочатку введіть URL.',
        'autofill_success' => 'Інформацію про бренд завантажено.',
        'autofill_error' => 'Не вдалося автозаповнити. Ви можете заповнити поля вручну.',
        'autofill_errors' => [
            'unreachable' => 'Не вдалося отримати доступ до цього вебсайту (:reason).',
            'http_status' => 'Вебсайт повернув неочікуваний статус (:status).',
            'invalid_scheme' => 'Підтримуються лише URL з http та https.',
            'missing_host' => 'У URL відсутній хост.',
            'unresolvable_host' => 'Не вдалося розв’язати хост (:host).',
            'private_network' => 'URL, що вказують на приватні мережі, не дозволені.',
        ],
        'logo_captured' => 'Логотип отримано з вашого вебсайту.',
        'name' => 'Назва робочого простору',
        'name_placeholder' => 'напр. Acme Inc',
        'brand_description' => 'Опис бренду',
        'brand_description_placeholder' => 'Чим займається ваш бренд?',
        'content_language' => 'Мова контенту',
        'content_language_description' => 'AI-підписи будуть написані цією мовою.',
        'brand_color' => 'Колір бренду',
        'background_color' => 'Колір фону',
        'text_color' => 'Колір тексту',
        'submit' => 'Створити робочий простір',
        'success' => 'Робочий простір створено. Підключіть соціальний акаунт, щоб почати публікувати.',
    ],

    'cannot_delete_last' => 'Ви не можете видалити свій єдиний робочий простір. Скасуйте підписку в налаштуваннях оплати, щоб закрити обліковий запис.',

    'flash' => [
        'deleted' => 'Робочий простір успішно видалено.',
    ],
];

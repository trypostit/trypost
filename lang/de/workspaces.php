<?php

declare(strict_types=1);

return [
    'title' => 'Workspaces',
    'select_title' => 'Deine Workspaces',
    'select_description' => 'Wähle einen Workspace, um fortzufahren',
    'current' => 'Aktuell',
    'connections' => ':count Verbindungen',
    'posts' => ':count Beiträge',

    'create' => [
        'page_title' => 'Erstelle deinen Workspace',
        'title' => 'Richte deinen Workspace ein',
        'description' => 'Erzähl uns ein wenig über dich oder dein Projekt. Wir nutzen das, um KI-generierte Beiträge an deinen Ton anzupassen.',
        'website' => 'Website',
        'website_placeholder' => 'https://yourbrand.com',
        'autofill' => 'Von Website automatisch ausfüllen',
        'autofill_missing_url' => 'Gib zuerst eine URL ein.',
        'autofill_success' => 'Markeninformationen geladen.',
        'autofill_error' => 'Automatisches Ausfüllen nicht möglich. Du kannst die Felder manuell ausfüllen.',
        'autofill_errors' => [
            'unreachable' => 'Wir konnten diese Website nicht erreichen (:reason).',
            'http_status' => 'Die Website hat einen unerwarteten Status zurückgegeben (:status).',
            'invalid_scheme' => 'Nur http- und https-URLs werden unterstützt.',
            'missing_host' => 'Der URL fehlt ein Host.',
            'unresolvable_host' => 'Wir konnten den Host nicht auflösen (:host).',
            'private_network' => 'URLs, die auf private Netzwerke verweisen, sind nicht zulässig.',
        ],
        'logo_captured' => 'Logo von deiner Website übernommen.',
        'name' => 'Workspace-Name',
        'name_placeholder' => 'z. B. Acme Inc',
        'brand_description' => 'Markenbeschreibung',
        'brand_description_placeholder' => 'Was macht deine Marke?',
        'content_language' => 'Inhaltssprache',
        'content_language_description' => 'KI-generierte Bildunterschriften werden in dieser Sprache verfasst.',
        'brand_color' => 'Markenfarbe',
        'background_color' => 'Hintergrundfarbe',
        'text_color' => 'Textfarbe',
        'submit' => 'Workspace erstellen',
        'success' => 'Workspace erstellt. Verbinde ein Social-Media-Konto, um mit dem Posten zu beginnen.',
    ],

    'cannot_delete_last' => 'Du kannst deinen einzigen Workspace nicht löschen. Kündige dein Abonnement in den Abrechnungseinstellungen, um dein Konto zu schließen.',

    'flash' => [
        'deleted' => 'Workspace erfolgreich gelöscht.',
    ],
];

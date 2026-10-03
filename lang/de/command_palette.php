<?php

declare(strict_types=1);

return [
    'title' => 'Befehlspalette',
    'description' => 'Kanäle, Seiten und Aktionen durchsuchen.',
    'placeholder' => 'Kanäle, Seiten suchen...',
    'path' => ':parent → :child',
    'empty' => 'Keine Ergebnisse für „:query“. Versuche andere Suchbegriffe.',
    'groups' => [
        'recent' => 'Zuletzt verwendet',
        'quick_actions' => 'Schnellaktionen',
        'navigation' => 'Navigation',
        'channels' => 'Kanäle',
        'settings' => 'Einstellungen',
        'insights' => 'Insights',
    ],
    'actions' => [
        'create_post' => 'Neuen Beitrag erstellen',
        'create_post_description' => 'Beginne mit einem neuen Beitrag',
        'create_idea' => 'Idee erstellen',
        'create_idea_description' => 'Speichere eine Content-Idee für später',
        'invite_member' => 'Teammitglied einladen',
        'invite_member_description' => 'Füge Personen zu deinem Team hinzu',
        'connect_channel' => 'Neuen Kanal verbinden',
        'connect_channel_description' => 'Füge ein neues Social-Media-Konto hinzu',
    ],
    'navigation' => [
        'settings' => 'Einstellungen',
        'settings_description' => 'Einstellungen öffnen',
    ],
    'footer' => [
        'navigate' => 'Navigieren',
        'select' => 'Auswählen',
        'close' => 'Schließen',
    ],
];

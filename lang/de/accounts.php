<?php

declare(strict_types=1);

return [
    'bluesky' => [
        'title' => 'Bluesky verbinden',
        'description' => 'Gib deine Zugangsdaten ein, um dich zu verbinden',
        'email' => 'E-Mail',
        'email_placeholder' => 'yourhandle.bsky.social',
        'app_password' => 'App-Passwort',
        'app_password_placeholder' => 'xxxx-xxxx-xxxx-xxxx',
        'app_password_hint' => 'Verwende aus Sicherheitsgründen ein <strong>App-Passwort</strong>. Erstelle eines unter <a href="https://bsky.app/settings/app-passwords" target="_blank" class="underline">bsky.app/settings</a>.',
        'submit' => 'Bluesky verbinden',
        'submitting' => 'Verbindung wird hergestellt...',
    ],

    'mastodon' => [
        'title' => 'Mastodon verbinden',
        'description' => 'Gib deine Mastodon-Instanz ein',
        'instance_url' => 'Instanz-URL',
        'instance_placeholder' => 'https://mastodon.social',
        'instance_hint' => 'Gib die URL deiner Mastodon-Instanz ein (z. B. mastodon.social, techhub.social)',
        'submit' => 'Mit Mastodon fortfahren',
        'submitting' => 'Verbindung wird hergestellt...',
    ],

    'telegram' => [
        'title' => 'Telegram verbinden',
        'description' => 'Einen Kanal oder eine Gruppe verknüpfen',
        'steps' => 'Schritte',
        'step_admin' => 'Füge :bot als Administrator zu deinem Telegram-Kanal oder deiner Telegram-Gruppe hinzu.',
        'open_bot' => 'In Telegram öffnen',
        'step_command' => 'Poste diesen Befehl im Kanal oder in der Gruppe:',
        'waiting' => 'Warten auf die Verbindung des Kanals…',
        'copy_command' => 'Befehl kopieren',
        'expired' => 'Dieser Befehl ist abgelaufen. Erstelle einen neuen, um es erneut zu versuchen.',
        'new_command' => 'Neuen Befehl erstellen',
        'error_generic' => 'Die Verbindung konnte nicht gestartet werden. Bitte versuche es erneut.',
        'network_taken' => 'Dieser Workspace hat bereits einen verbundenen Telegram-Kanal. Trenne ihn zuerst.',
        'wrong_chat' => 'Poste den Befehl in dem Kanal, den du neu verbindest.',
        'busy' => 'Eine andere Verbindung wird noch abgeschlossen. Sende den Befehl gleich erneut.',
        'help' => [
            'channel_admins' => 'Administratoren zu einem Kanal hinzufügen',
            'group_admins' => 'Administratoren zu einer Gruppe hinzufügen',
            'bot_privacy' => 'Was Bots in Gruppen lesen können',
        ],
    ],

    'facebook' => [
        'title' => 'Facebook-Seite auswählen',
        'description' => 'Wähle die Seite aus, die du verbinden möchtest',
        'no_pages' => 'Keine Seiten gefunden',
        'no_pages_description' => 'Du bist kein Administrator einer Facebook-Seite.',
        'page_label' => 'Facebook-Seite',
        'view' => 'Ansehen',
        'choose' => 'Auswählen',
    ],

    'instagram_facebook' => [
        'title' => 'Instagram-Konto auswählen',
        'description' => 'Wähle das Instagram-Konto aus, das du verbinden möchtest',
        'no_pages' => 'Keine Instagram-Konten gefunden',
        'no_pages_description' => 'Es wurden keine Facebook-Seiten mit verknüpften Instagram-Business-Konten gefunden.',
        'view' => 'Ansehen',
        'choose' => 'Auswählen',
    ],

    'instagram_connect' => [
        'title' => 'Wie möchtest du dein Instagram-Konto verbinden?',
        'description' => 'Die Funktionen hängen von der Art deines Instagram-Kontos und der gewählten Verbindung ab.',
        'professional_title' => 'Professionell',
        'professional_types' => '(Unternehmen & Creator)',
        'badge' => 'Automatisches Posten',
        'features' => [
            'automatic' => [
                'title' => 'Automatisches Posten',
                'description' => 'Du planst, wir posten',
            ],
            'metrics' => [
                'title' => 'Metriken gesendeter Beiträge',
                'description' => 'Leistung früherer Beiträge ansehen',
            ],
        ],
        'connect' => 'Mit Instagram verbinden',
        'convert_hint' => 'Instagram fordert dich bei Bedarf auf, ganz einfach zu einem professionellen Konto zu wechseln.',
        'facebook_link' => 'Instagram über Facebook verbinden',
        'facebook_suffix' => '(wenn dein Instagram-Konto derzeit mit Facebook verknüpft ist).',
        'help' => [
            'account_type' => 'Den Typ deines Instagram-Kontos herausfinden',
            'convert' => 'Dein Instagram-Konto in ein professionelles Konto umwandeln',
        ],
    ],

    'instagram_facebook_requirements' => [
        'title' => 'Instagram über Facebook verbinden',
        'subtitle' => 'Das solltest du wissen 👇',
        'heading' => 'Voraussetzungen',
        'items' => [
            'account_type' => [
                'lead' => 'Unternehmens- oder Creator-Konto',
                'rest' => 'auf Instagram, kein privates Instagram-Profil.',
            ],
            'page' => [
                'lead' => 'Mit einer Facebook-Seite verknüpft,',
                'rest' => 'nicht mit einem Facebook-Profil. Musst du Instagram bei Meta mit Facebook verknüpfen?',
            ],
            'admin' => [
                'lead' => 'Als Admin der Facebook-Seite angemeldet',
                'rest' => 'mit „voller Kontrolle“.',
            ],
            'permissions' => [
                'lead' => 'Alle Berechtigungen für alle Seiten und Instagram-Konten ausgewählt',
                'rest' => 'beim Verbinden, auch für die, die du nicht mit TryPost verbindest.',
            ],
        ],
        'learn_how' => 'So geht’s.',
        'note' => 'Die Verbindung funktioniert nicht, wenn eine dieser Voraussetzungen nicht erfüllt ist.',
        'connect' => 'Über Facebook verbinden',
    ],

    'linkedin' => [
        'title' => 'LinkedIn-Seite auswählen',
        'description' => 'Wähle die Seite aus, die du verbinden möchtest',
        'no_pages' => 'Keine Seiten gefunden',
        'no_pages_description' => 'Du bist kein Administrator einer LinkedIn-Seite.',
        'page_label' => 'LinkedIn-Seite',
        'select_title' => 'Wo möchtest du posten?',
        'select_subtitle' => 'Poste als du selbst oder wähle eine Unternehmensseite, die du verwaltest.',
        'person_tag' => 'Person',
        'organization_tag' => 'Organisation',
        'view' => 'Ansehen',
        'choose' => 'Auswählen',
    ],

    'flash' => [
        'disconnected_paused_repurposes' => 'Konto getrennt. :count Automatisierung pausiert.|Konto getrennt. :count Automatisierungen pausiert.',
        'disconnected' => 'Konto erfolgreich getrennt!',
        'session_expired' => 'Sitzung abgelaufen. Bitte versuche es erneut.',
        'workspace_not_found' => 'Workspace nicht gefunden.',
        'already_connected' => 'Diese Plattform ist bereits verbunden.',
        'no_youtube_channels' => 'Keine YouTube-Kanäle gefunden. Bitte erstelle zuerst einen Kanal.',
    ],

    'popup_callback' => [
        'title_error' => 'Fehler',
        'closing' => 'Dieses Fenster wird automatisch geschlossen...',
        'manual_close' => 'Du kannst dieses Fenster schließen.',
        'popup_blocked' => 'Das Verbindungsfenster konnte nicht geöffnet werden. Bitte erlaube Pop-ups und versuche es erneut.',
        'error_connecting' => 'Fehler beim Verbinden des Kontos. Bitte versuche es erneut.',
        'network_taken' => 'Dieser Workspace hat bereits ein Konto für dieses Netzwerk. Trenne es zuerst.',
        'wrong_account' => 'Das ist ein anderes Konto. Autorisiere das Konto, das du neu verbindest.',
        'all_connected' => 'Alle Konten dieses Logins sind bereits verbunden.',
        'busy' => 'Eine andere Verbindung wird noch abgeschlossen. Bitte versuche es gleich erneut.',
        'error_connecting_page' => 'Fehler beim Verbinden der Seite. Bitte versuche es erneut.',
        'error_connecting_channel' => 'Fehler beim Verbinden des Kanals. Bitte versuche es erneut.',
        'session_expired' => 'Sitzung abgelaufen. Bitte versuche es erneut.',
        'workspace_not_found' => 'Workspace nicht gefunden.',
        'invalid_state' => 'Ungültiger Status. Bitte versuche es erneut.',
        'failed_to_authenticate' => 'Authentifizierung fehlgeschlagen.',
        'failed_to_get_profile' => 'Profil konnte nicht abgerufen werden.',
        'page_not_found' => 'Seite nicht gefunden.',
        'channel_not_found' => 'Kanal nicht gefunden.',
        'pages_read_incomplete' => 'Wir konnten deine Seiten nicht vollständig lesen. Bitte versuche es gleich noch einmal.',
        'publish_permission_refused' => 'Diese Anmeldung hat eine zum Posten nötige Berechtigung abgelehnt. Verbinde erneut und akzeptiere alle.',
        'pages_missing_permission' => 'Wir haben Seiten gefunden, aber keine zum Posten. Du brauchst eine Rolle auf der Seite selbst und alle Berechtigungen.',
        'no_facebook_pages' => 'Keine Facebook-Seiten gefunden. Du musst Administrator mindestens einer Seite sein.',
        'no_facebook_instagram_pages' => 'Keine Facebook-Seiten mit verknüpften Instagram-Konten gefunden.',
        'no_youtube_channels' => 'Keine YouTube-Kanäle gefunden. Bitte erstelle zuerst einen Kanal.',
        'not_linkedin_admin' => 'Du bist kein Administrator einer LinkedIn-Seite.',
        'no_google_business_locations' => 'Keine Google Unternehmensprofil-Standorte gefunden. Bestätige zuerst dein Unternehmen.',
        'location_not_found' => 'Standort nicht gefunden.',
        'error_connecting_location' => 'Fehler beim Verbinden des Standorts. Bitte versuche es erneut.',
    ],

    'google_business' => [
        'title' => 'Standort auswählen',
        'description' => 'Wähle aus, welchen Standort du verbinden möchtest',
        'no_locations' => 'Keine Standorte gefunden',
        'no_locations_description' => 'Du bist kein Manager eines verifizierten Google Unternehmensprofil-Standorts.',
        'choose' => 'Auswählen',
    ],
];

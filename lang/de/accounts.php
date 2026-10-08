<?php

declare(strict_types=1);

return [
    'bluesky' => [
        'title' => 'Bluesky verbinden',
        'description' => 'Gib deine Zugangsdaten ein, um dich zu verbinden',
        'email' => 'Handle oder E-Mail',
        'email_placeholder' => 'yourhandle.bsky.social',
        'app_password' => 'App-Passwort',
        'app_password_placeholder' => 'xxxx-xxxx-xxxx-xxxx',
        'app_password_hint' => 'Verwende aus Sicherheitsgründen ein <strong>App-Passwort</strong>. Erstelle eines unter <a href="https://bsky.app/settings/app-passwords" target="_blank" class="underline">bsky.app/settings</a>.',
        'submit' => 'Bluesky verbinden',
        'submitting' => 'Verbindung wird hergestellt...',
        'invalid_credentials' => 'Ungültige Zugangsdaten.',
        'connection_error' => 'Fehler bei der Verbindung zu Bluesky. Bitte versuche es erneut.',
    ],

    'mastodon' => [
        'title' => 'Mastodon verbinden',
        'description' => 'Gib deine Mastodon-Instanz ein',
        'instance_url' => 'Instanz-URL',
        'instance_placeholder' => 'https://mastodon.social',
        'instance_hint' => 'Zum Beispiel: mastodon.social oder techhub.social.',
        'submit' => 'Mit Mastodon fortfahren',
        'submitting' => 'Verbindung wird hergestellt...',
        'instance_unreachable' => 'Verbindung zu dieser Mastodon-Instanz nicht möglich.',
        'connection_error' => 'Fehler bei der Verbindung zur Mastodon-Instanz.',
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

    'flash' => [
        'disconnected_paused_repurposes' => 'Konto getrennt. :count Automatisierung pausiert.|Konto getrennt. :count Automatisierungen pausiert.',
        'disconnected' => 'Konto erfolgreich getrennt!',
        'session_expired' => 'Sitzung abgelaufen. Bitte versuche es erneut.',
        'workspace_not_found' => 'Workspace nicht gefunden.',
        'already_connected' => 'Diese Plattform ist bereits verbunden.',
        'no_youtube_channels' => 'Keine YouTube-Kanäle gefunden. Bitte erstelle zuerst einen Kanal.',
    ],

    'connect' => [
        'label' => 'Kanal verbinden',
        'close' => 'Schließen',
        'title_single' => 'Konto verbinden',
        'title_select' => 'Konten auswählen',
        'subtitle_single' => 'Wähle das Konto, auf dem TryPost bei :network veröffentlicht.',
        'subtitle_select' => 'Wähle die Konten, auf denen TryPost bei :network veröffentlicht.',
        'selected' => ':count ausgewählt',
        'select_all' => 'Alle wählen',
        'connected' => 'Verbunden',
        'all_connected' => 'Alle deine :network-Konten sind bereits verbunden.',
        'finish' => 'Fertigstellen',
        'switch' => [
            'button' => 'Konto wechseln',
            'button_short' => 'Wechseln',
            'title' => 'Anderes Konto verbinden',
            'description' => 'Öffne :site, wechsle zum gewünschten Konto und klicke dann unten auf „Mit :network verbinden“.',
            'connect' => 'Mit :network verbinden',
        ],
        'types' => [
            'profile' => 'Profil',
            'page' => 'Seite',
            'channel' => 'Kanal',
            'location' => 'Standort',
            'server' => 'Server',
        ],
        'help' => [
            'label' => 'Hilfe',
            'missing' => 'Fehlt ein Konto? Prüfe, ob du es verwaltest, versuche es erneut und erlaube alle Berechtigungen.',
        ],
        'states' => [
            'cancelled' => [
                'title' => 'Verbindung abgebrochen',
                'description' => 'Es wurde nichts verbunden. Du kannst es jederzeit erneut versuchen.',
            ],
            'missing_permission' => [
                'title' => 'Berechtigung nötig',
                'description' => 'TryPost braucht die Berechtigung, deine Beiträge zu veröffentlichen. Verbinde erneut und lass diese Berechtigung aktiviert.',
            ],
            'expired' => [
                'title' => 'Diese Verbindung ist abgelaufen',
                'description' => 'Aus Sicherheitsgründen läuft eine Verbindung nach 15 Minuten ab. Starte neu, um dein Konto zu verbinden.',
            ],
            'error' => [
                'title' => 'Verbindung fehlgeschlagen',
            ],
        ],
        'actions' => [
            'try_again' => 'Erneut versuchen',
            'back' => 'Zurück',
            'connect_again' => 'Neu verbinden',
            'start_again' => 'Neu starten',
        ],
        'errors' => [
            'bluesky_email_unconfirmed' => 'Bestätige deine E-Mail-Adresse in den Bluesky-Einstellungen und verbinde dein Konto erneut. TryPost benötigt eine bestätigte E-Mail-Adresse, um Videos zu veröffentlichen.',
            'error_connecting' => 'Fehler beim Verbinden des Kontos. Bitte versuche es erneut.',
            'network_taken' => 'Dieser Workspace hat bereits ein Konto für dieses Netzwerk. Trenne es zuerst.',
            'wrong_account' => 'Das ist ein anderes Konto. Autorisiere das Konto, das du neu verbindest.',
            'all_connected' => 'Alle Konten dieses Logins sind bereits verbunden.',
            'identity_connected' => 'Dieses Konto ist bereits verbunden.',
            'busy' => 'Eine andere Verbindung wird noch abgeschlossen. Bitte versuche es gleich erneut.',
            'session_expired' => 'Sitzung abgelaufen. Bitte versuche es erneut.',
            'workspace_not_found' => 'Workspace nicht gefunden.',
            'invalid_state' => 'Ungültiger Status. Bitte versuche es erneut.',
            'failed_to_authenticate' => 'Authentifizierung fehlgeschlagen.',
            'failed_to_get_profile' => 'Profil konnte nicht abgerufen werden.',
            'page_not_found' => 'Seite nicht gefunden.',
            'channel_not_found' => 'Kanal nicht gefunden.',
            'pages_read_incomplete' => 'Wir konnten deine Seiten nicht vollständig lesen. Bitte versuche es gleich noch einmal.',
            'publish_permission_missing' => 'TryPost braucht die Berechtigung, deine Beiträge zu veröffentlichen. Verbinde erneut und lass diese Berechtigung aktiviert.',
            'cancelled' => 'Verbindung abgebrochen.',
            'pages_missing_permission' => 'Wir haben Seiten gefunden, aber keine zum Posten. Du brauchst eine Rolle auf der Seite selbst und alle Berechtigungen.',
            'no_facebook_pages' => 'Keine Facebook-Seiten gefunden. Du musst Administrator mindestens einer Seite sein.',
            'no_facebook_instagram_pages' => 'Keine Facebook-Seiten mit verknüpften Instagram-Konten gefunden.',
            'no_youtube_channels' => 'Keine YouTube-Kanäle gefunden. Bitte erstelle zuerst einen Kanal.',
            'not_linkedin_admin' => 'Du bist kein Administrator einer LinkedIn-Seite.',
            'no_google_business_locations' => 'Keine Google Unternehmensprofil-Standorte gefunden. Bestätige zuerst dein Unternehmen.',
            'location_not_found' => 'Standort nicht gefunden.',
        ],
    ],
];

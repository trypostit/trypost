<?php

declare(strict_types=1);

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

    'failed' => 'Diese Zugangsdaten stimmen nicht mit unseren Aufzeichnungen überein.',
    'password' => 'Das angegebene Passwort ist falsch.',
    'throttle' => 'Zu viele Anmeldeversuche. Bitte versuche es in :seconds Sekunden erneut.',

    'flash' => [
        'welcome' => 'Willkommen bei TryPost!',
        'welcome_trial' => 'Willkommen bei TryPost! Deine Testphase hat begonnen.',
    ],

    'legal' => 'Indem du fortfährst, stimmst du unseren <a href="https://trypost.it/terms" target="_blank">Nutzungsbedingungen</a> und unserer <a href="https://trypost.it/privacy" target="_blank">Datenschutzerklärung</a> zu.',

    'slides' => [
        'calendar' => [
            'title' => 'Visueller Kalender',
            'description' => 'Plane und terminiere deine Inhalte mit einem intuitiven Drag-and-drop-Kalender für alle deine Social-Media-Konten.',
        ],
        'scheduling' => [
            'title' => 'Intelligente Planung',
            'description' => 'Plane Beiträge für LinkedIn, X, Instagram, TikTok, YouTube und mehr – alles von einem Ort aus.',
        ],
        'media' => [
            'title' => 'Rich Media',
            'description' => 'Veröffentliche Bilder, Karussells, Storys und Reels. Jede Plattform erhält automatisch das richtige Format.',
        ],
        'video' => [
            'title' => 'Video-Veröffentlichung',
            'description' => 'Lade Videos einmal hoch und veröffentliche sie auf TikTok, YouTube Shorts, Instagram Reels und Facebook Reels.',
        ],
        'team' => [
            'title' => 'Team-Workspaces',
            'description' => 'Lade dein Team ein, weise Rollen zu und verwalte mehrere Marken in separaten Workspaces.',
        ],
        'signatures' => [
            'title' => 'Signaturen',
            'description' => 'Speichere wiederverwendbare Signaturen (Hashtags, Links, Grußformeln) und füge sie mit einem Klick zu Beiträgen hinzu.',
        ],
    ],

    'or_continue_with' => 'Oder fortfahren mit',
    'or_continue_with_email' => 'Oder mit E-Mail fortfahren',
    'google_login' => 'Mit Google anmelden',
    'google_signup' => 'Mit Google registrieren',
    'github_login' => 'Mit GitHub anmelden',
    'github_signup' => 'Mit GitHub registrieren',
    'github_email_unavailable' => 'Deine E-Mail-Adresse konnte nicht von GitHub abgerufen werden. Mache deine GitHub-E-Mail-Adresse öffentlich oder erteile die Berechtigung für den E-Mail-Zugriff und versuche es dann erneut.',

    'signup_success' => [
        'page_title' => 'Willkommen',
        'title' => 'Dein Konto wird eingerichtet',
        'description' => 'Das dauert normalerweise nur wenige Sekunden...',
    ],

    'login' => [
        'title' => 'Melde dich bei deinem Konto an',
        'description' => 'Gib unten deine E-Mail-Adresse und dein Passwort ein, um dich anzumelden',
        'page_title' => 'Anmelden',
        'email' => 'E-Mail-Adresse',
        'password' => 'Passwort',
        'forgot_password' => 'Passwort vergessen?',
        'remember_me' => 'Angemeldet bleiben',
        'submit' => 'Anmelden',
        'no_account' => 'Noch kein Konto?',
        'sign_up' => 'Registrieren',
    ],

    'register' => [
        'title' => 'Dein gesamter Social-Media-Kalender an einem Ort',
        'description' => 'Erstelle dein Konto und beginne, Beiträge für jedes Netzwerk zu planen.',
        'page_title' => 'Registrieren',
        'signup_with_email' => 'Mit E-Mail registrieren',
        'name' => 'Name',
        'name_placeholder' => 'Vollständiger Name',
        'email' => 'E-Mail-Adresse',
        'password' => 'Passwort',
        'show_password' => 'Passwort anzeigen',
        'hide_password' => 'Passwort verbergen',
        'submit' => 'Konto erstellen',
        'has_account' => 'Hast du bereits ein Konto?',
        'log_in' => 'Anmelden',
    ],

    'forgot_password' => [
        'title' => 'Passwort vergessen',
        'description' => 'Gib deine E-Mail-Adresse ein, um einen Link zum Zurücksetzen des Passworts zu erhalten',
        'page_title' => 'Passwort vergessen',
        'email' => 'E-Mail-Adresse',
        'submit' => 'Link zum Zurücksetzen des Passworts senden',
        'return_to' => 'Oder zurück zur',
        'log_in' => 'Anmeldung',
    ],

    'reset_password' => [
        'title' => 'Passwort zurücksetzen',
        'description' => 'Bitte gib unten dein neues Passwort ein',
        'page_title' => 'Passwort zurücksetzen',
        'email' => 'E-Mail',
        'password' => 'Passwort',
        'confirm_password' => 'Passwort bestätigen',
        'confirm_placeholder' => 'Passwort bestätigen',
        'submit' => 'Passwort zurücksetzen',
    ],

    'verify_email' => [
        'title' => 'E-Mail bestätigen',
        'description' => 'Bitte bestätige deine E-Mail-Adresse, indem du auf den Link klickst, den wir dir gerade per E-Mail gesendet haben.',
        'page_title' => 'E-Mail-Bestätigung',
        'link_sent' => 'Ein neuer Bestätigungslink wurde an die E-Mail-Adresse gesendet, die du bei der Registrierung angegeben hast.',
        'resend' => 'Bestätigungs-E-Mail erneut senden',
        'log_out' => 'Abmelden',
    ],

    'accept_invite' => [
        'page_title' => 'Einladung annehmen',
        'title' => 'Du wurdest eingeladen!',
        'description' => 'Du wurdest eingeladen, dem Workspace :workspace beizutreten.',
        'workspace' => 'Workspace',
        'your_role' => 'Deine Rolle',
        'email' => 'E-Mail',
        'accept' => 'Einladung annehmen',
        'decline' => 'Einladung ablehnen',
        'login_prompt' => 'Melde dich an oder erstelle ein Konto, um diese Einladung anzunehmen.',
        'log_in' => 'Anmelden',
        'create_account' => 'Konto erstellen',
    ],

];

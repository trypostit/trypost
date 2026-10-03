<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'Open-Source-Tool zur Planung von Social-Media-Beiträgen',
        'manage_notifications' => 'Benachrichtigungen verwalten',
        'signoff' => 'Viele Grüße,',
        'team' => 'Das TryPost-Team',
    ],

    'account_disconnected' => [
        'subject' => 'Dein :platform-Konto in :workspace muss neu verbunden werden',
        'title' => 'Dein :platform-Konto muss neu verbunden werden',
        'preview' => 'Verbinde dein :platform-Konto in :workspace neu, um weiter Beiträge zu planen.',
        'heading' => 'Konto getrennt',
        'intro' => 'Dein <strong>:platform</strong>-Konto <strong>:account</strong> wurde vom Workspace <strong>:workspace</strong> getrennt.',
        'reasons_title' => 'Mögliche Gründe:',
        'reason_expired' => 'Dein Zugriffstoken ist abgelaufen',
        'reason_revoked' => 'Du hast den Zugriff von TryPost widerrufen',
        'reason_error' => 'Es gab einen Authentifizierungsfehler',
        'reconnect_cta' => 'Verbinde dein Konto neu, um weiter zu planen und zu veröffentlichen.',
        'button' => 'Konto neu verbinden',
    ],

    'email_verification' => [
        'subject' => 'Bestätige deine E-Mail-Adresse',
        'preview' => 'Bitte bestätige deine E-Mail-Adresse.',
        'greeting' => 'Hallo :name,',
        'body' => 'Bestätige deine E-Mail-Adresse über den Button unten:',
        'button' => 'E-Mail bestätigen',
        'ignore' => 'Wenn du kein Konto erstellt hast, kannst du diese E-Mail ignorieren.',
    ],

    'password_reset' => [
        'subject' => 'Setze dein Passwort zurück',
        'preview' => 'Setze dein Passwort zurück.',
        'greeting' => 'Hallo :name,',
        'body' => 'Wir haben eine Anfrage zum Zurücksetzen deines Passworts erhalten. Klicke auf den Button, um ein neues zu erstellen:',
        'button' => 'Passwort zurücksetzen',
        'expiry' => 'Dieser Link läuft in 60 Minuten ab. Wenn du die Anfrage nicht gestellt hast, kannst du diese E-Mail ignorieren.',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count Beitrag in :workspace ist gefährdet|[0,*] :count Beiträge in :workspace sind gefährdet',
        'title' => 'Beiträge könnten fehlschlagen',
        'heading' => 'Beiträge könnten fehlschlagen',
        'intro' => 'Die folgenden Konten im Workspace :workspace müssen neu verbunden werden, damit diese geplanten Beiträge veröffentlicht werden können:',
        'posts_label' => '{1} :count Beitrag geplant: :times (:timezone)|[0,*] :count Beiträge geplant: :times (:timezone)',
        'reconnect_cta' => 'Verbinde diese Konten jetzt neu, damit deine geplanten Beiträge nicht ausfallen.',
        'button' => 'Konten neu verbinden',
    ],

    'post_note_added' => [
        'subject' => ':author hat eine Notiz zu einem Beitrag hinzugefügt',
        'title' => 'Neue Notiz von :author',
        'heading' => 'Neue Notiz zu einem Beitrag',
        'body' => ':author hat im Workspace :workspace eine Notiz zu einem Beitrag hinzugefügt.',
        'post_title' => 'Beitrag',
        'post_without_text' => 'Dieser Beitrag hat noch keinen Text.',
        'button' => 'Notiz ansehen',
    ],
    'post_approval_requested' => [
        'subject' => ':name bittet dich um Freigabe eines Beitrags',
        'title' => 'Ein Beitrag braucht deine Freigabe',
        'preview' => ':name bittet in :workspace um Freigabe.',
        'heading' => 'Ein Beitrag braucht deine Freigabe',
        'body' => ':name (:email) bittet im Workspace :workspace um Freigabe.',
        'channels' => 'Kanäle',
        'requested_time' => 'Gewünschter Zeitpunkt',
        'next_queue_slot' => 'Nächster Platz in der Warteschlange',
        'as_soon_as_approved' => 'Sobald er freigegeben ist',
        'post_without_text' => 'Dieser Beitrag hat noch keinen Text.',
        'button' => 'Beiträge zur Freigabe ansehen',
    ],

    'post_approved' => [
        'subject' => ':name hat deinen Beitrag freigegeben',
        'title' => 'Dein Beitrag wurde freigegeben',
        'preview' => ':name hat deinen Beitrag in :workspace freigegeben.',
        'heading' => 'Dein Beitrag wurde freigegeben',
        'body' => ':name hat deinen Beitrag im Workspace :workspace freigegeben.',
        'channels' => 'Kanäle',
        'goes_out' => 'Wird veröffentlicht',
        'channel_time' => ':channel: :time',
        'publishing_now' => 'Wird jetzt veröffentlicht',
        'button' => 'In der Warteschlange ansehen',
    ],

    'post_rejected' => [
        'subject' => ':name hat deinen Beitrag nicht freigegeben',
        'title' => 'Dein Beitrag wurde nicht freigegeben',
        'preview' => ':name hat deinen Beitrag zurück in die Entwürfe verschoben.',
        'heading' => 'Dein Beitrag wurde nicht freigegeben',
        'body' => ':name hat deinen Beitrag im Workspace :workspace zurück in die Entwürfe verschoben.',
        'channels' => 'Kanäle',
        'button' => 'In den Entwürfen ansehen',
    ],

    'post_publish_failed' => [
        'subject' => 'Dein Beitrag in :workspace konnte nicht veröffentlicht werden',
        'title' => 'Dein Beitrag konnte nicht veröffentlicht werden',
        'preview' => 'Eine oder mehrere Plattformen konnten nicht veröffentlichen.',
        'heading' => 'Dein Beitrag konnte nicht veröffentlicht werden',
        'body' => 'Dein geplanter Beitrag im Workspace :workspace konnte auf einer oder mehreren Plattformen nicht veröffentlicht werden.',
        'platforms_title' => 'Fehlgeschlagene Plattformen:',
        'button' => 'Beitrag ansehen',
    ],

    'post_published' => [
        'subject' => 'Dein Beitrag in :workspace wurde veröffentlicht',
        'title' => 'Dein Beitrag wurde veröffentlicht',
        'preview' => 'Dein Beitrag wurde erfolgreich veröffentlicht.',
        'heading' => 'Dein Beitrag wurde veröffentlicht',
        'body' => 'Dein Beitrag im Workspace :workspace wurde erfolgreich veröffentlicht.',
        'platforms_title' => 'Veröffentlicht auf:',
        'view_post' => 'Beitrag ansehen',
        'button' => 'Beitrag ansehen',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook pausiert: :endpoint',
        'title' => 'Webhook nach wiederholten Fehlern pausiert',
        'preview' => 'Wir haben einen Webhook nach 5 aufeinanderfolgenden Zustellfehlern pausiert.',
        'heading' => 'Webhook nach wiederholten Fehlern pausiert',
        'body' => 'Wir haben den Webhook unter :endpoint nach 5 aufeinanderfolgenden Zustellfehlern pausiert. Prüfe den Endpoint und aktiviere ihn wieder auf der Webhook-Detailseite.',
        'button' => 'Webhook anzeigen',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count Konto muss in :workspace erneut verbunden werden|[0,*] :count Konten müssen in :workspace erneut verbunden werden',
        'title' => 'Konten müssen erneut verbunden werden',
        'heading' => 'Konten müssen erneut verbunden werden',
        'intro' => 'Die folgenden Social-Media-Konten in deinem Workspace <strong>:workspace</strong> wurden getrennt und müssen erneut verbunden werden:',
        'reasons_title' => 'Das kann folgende Gründe haben:',
        'reason_expired' => 'Zugriffstokens sind abgelaufen',
        'reason_revoked' => 'Du hast den Zugriff von TryPost auf der Plattform widerrufen',
        'reason_changed' => 'Die Plattform hat ihre Authentifizierungsanforderungen geändert',
        'reconnect_cta' => 'Bitte verbinde diese Konten erneut, um weiterhin Beiträge zu planen und zu veröffentlichen.',
        'button' => 'Konten erneut verbinden',
    ],

    'workspace_invite' => [
        'subject' => 'Du wurdest zu :account eingeladen',
        'title' => 'Du wurdest zu :account eingeladen',
        'preview' => 'Du wurdest zu :account eingeladen',
        'heading' => 'Du wurdest eingeladen!',
        'intro' => 'Du wurdest eingeladen, im Workspace <strong>:account</strong> mitzuarbeiten.',
        'role' => 'Du wurdest als <strong>:role</strong> eingeladen.',
        'roles' => ['admin' => 'Admin', 'member' => 'Mitglied', 'needs_approval' => 'Mitglied (Beiträge brauchen Freigabe)'],
        'button' => 'Einladung annehmen',
        'expiry' => 'Diese Einladung läuft in 7 Tagen ab.',
    ],

];

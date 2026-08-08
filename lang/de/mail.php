<?php

declare(strict_types=1);

return [
    'mentioned' => [
        'subject' => ':name hat dich auf TryPost erwähnt',
        'title' => ':name hat dich erwähnt',
        'intro' => ':name hat dich in einem Beitragskommentar erwähnt.',
        'cta' => 'Kommentar ansehen',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count Konto muss in :workspace erneut verbunden werden|[2,*] :count Konten müssen in :workspace erneut verbunden werden',
        'title' => 'Konten müssen erneut verbunden werden',
        'intro' => 'Die folgenden Social-Media-Konten in deinem Workspace <strong>:workspace</strong> wurden getrennt und müssen erneut verbunden werden:',
        'reasons_title' => 'Das kann folgende Gründe haben:',
        'reason_expired' => 'Zugriffstokens sind abgelaufen',
        'reason_revoked' => 'Du hast den Zugriff von TryPost auf der Plattform widerrufen',
        'reason_changed' => 'Die Plattform hat ihre Authentifizierungsanforderungen geändert',
        'reconnect_cta' => 'Bitte verbinde diese Konten erneut, um weiterhin Beiträge zu planen und zu veröffentlichen.',
        'button' => 'Konten erneut verbinden',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count Beitrag ist gefährdet in :workspace|[2,*] :count Beiträge sind gefährdet in :workspace',
        'title' => 'Beiträge könnten nicht veröffentlicht werden',
        'intro' => 'Die folgenden Social-Media-Konten in deinem Workspace <strong>:workspace</strong> müssen erneut verbunden werden, bevor diese geplanten Beiträge veröffentlicht werden können:',
        'reconnect_cta' => 'Bitte verbinde diese Konten jetzt erneut, um deine geplanten Beiträge nicht zu verpassen.',
        'button' => 'Konten erneut verbinden',
    ],
];

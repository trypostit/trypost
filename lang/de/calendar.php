<?php

declare(strict_types=1);

return [
    'title' => 'Kalender',
    'today' => 'Heute',
    'previous' => 'Zurück',
    'next' => 'Weiter',
    'days' => '3 Tage',
    'week' => 'Woche',
    'month' => 'Monat',
    'new_post' => 'Neuer Beitrag',
    'no_content' => 'Kein Inhalt',
    'more' => '+:count weitere',
    'less' => 'Weniger anzeigen',
    'empty_day' => 'Für diesen Tag ist nichts geplant.',
    'status' => [
        'label' => 'Status',
        'all' => 'Alle Beiträge',
        'drafts' => 'Entwürfe',
        'scheduled' => 'Geplant',
        'sent' => 'Gesendet',
    ],
    'no_date' => 'Ohne Datum',
    'undated' => [
        'title' => 'Entwürfe ohne Datum',
        'description' => 'Entwürfe ohne geplantes Datum.',
        'close' => 'Schließen',
        'empty' => 'Keine Entwürfe ohne Datum.',
        'draft' => 'Entwurf',
        'no_channel' => 'Kein Kanal',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => 'Beitrag auf eine eigene Uhrzeit verschieben',
            'description' => 'Du verschiebst diesen Beitrag von einem Platz in der Warteschlange, :from, auf eine eigene Uhrzeit, :to, die nicht zu deinen Veröffentlichungszeiten gehört.',
            'unqueued' => 'Der Beitrag wird zur neu gewählten Zeit veröffentlicht, belegt aber keinen Platz in deiner Warteschlange.',
            'dont_show_again' => 'Nicht mehr anzeigen',
            'cancel' => 'Abbrechen',
            'confirm' => 'Auf eigene Zeit verschieben',
        ],
        'error' => 'Beitrag konnte nicht verschoben werden. Versuche es erneut.',
        'drag_hint' => 'Zum Verschieben ziehen',
        'drag_hint_undated' => 'Entwurf ohne Datum für :channel zum Planen ziehen',
        'announce' => [
            'over' => 'Element geplant für :from befindet sich über :target.',
            'moved' => 'Element geplant für :from wurde auf :to verschoben.',
            'returned' => 'Element wurde an seiner ursprünglichen Position abgelegt.',
            'undated' => 'Element geplant für :from wurde auf Ohne Datum abgelegt.',
            'from_undated' => 'Entwurf ohne Datum für :channel wurde für :to geplant.',
            'date_time' => ':date um :time',
        ],
        'toast' => [
            'moved' => 'Dein Beitrag wurde auf :time verschoben.',
            'moved_draft' => 'Dein Entwurf wurde auf :time verschoben.',
            'undated' => 'Dein Entwurf wurde zu Ohne Datum verschoben.',
            'unqueued_suffix' => 'Der Platz in der Warteschlange ist wieder frei.',
        ],
    ],
];

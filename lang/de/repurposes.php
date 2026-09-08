<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'Was du außerhalb von TryPost postest, automatisch auf deinen anderen Netzwerken wiederveröffentlichen.',
    'new' => 'Neues Repurpose',

    'flow' => [
        'no_source' => 'Kein Quellkonto',
        'no_destinations' => 'Noch kein Ziel',
    ],

    'publish_mode' => [

        'title' => 'Veröffentlichung',

        'description' => 'Was passiert, wenn ein neuer Beitrag auftaucht.',

    ],

    'publish_modes' => [

        'publish' => 'Automatisch veröffentlichen',

        'publish_hint' => 'Jeder neue Beitrag wird eingeplant, sobald er gefunden wird.',

        'draft' => 'Als Entwurf anlegen',

        'draft_hint' => 'Jeder neue Beitrag wird hier zum Entwurf, den du prüfen und veröffentlichen kannst.',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Videos',
        'story' => 'Stories',
    ],

    'source' => [
        'title' => 'Quelle',
        'description' => 'TryPost beobachtet dieses Konto auf neue Beiträge im unten gewählten Format.',
        'account_label' => 'Konto',
        'watch_label' => 'Beobachten',
        'needs_reconnect' => 'Neu verbinden nötig',
    ],

    'summary' => [
        'sentence' => 'Jedes neue :format, das du auf :source postest, wird auf :destinations erneut veröffentlicht.',
        'no_destinations' => 'Jedes neue :format auf :source wartet noch auf ein Ziel.',
        'no_source' => 'Diese Automatisierung hat kein Quellkonto mehr. Wähle eines aus, um sie neu zu starten.',
    ],

    'empty' => [
        'title' => 'Noch kein Repurpose eingerichtet',
        'description' => 'TryPost beobachtet das gewählte Konto und veröffentlicht jeden neuen Beitrag auf den ausgewählten Netzwerken erneut.',
    ],

    'table' => [
        'flow' => 'Ablauf',
        'status' => 'Status',
        'published' => 'Repliziert',
        'last_polled' => 'Zuletzt geprüft',
    ],

    'status' => [
        'draft' => 'Entwurf',
        'active' => 'Aktiv',
        'paused' => 'Pausiert',
        'disabled' => 'Deaktiviert',
    ],

    'create' => [
        'title' => 'Neues Repurpose',
        'description' => 'Wähle das Konto, das TryPost beobachten soll. Die Ziele wählst du im nächsten Schritt.',
        'source_label' => 'Quellkonto',
        'source_placeholder' => 'Konto auswählen',
        'source_search' => 'Konten suchen',
        'source_empty' => 'Kein Konto gefunden.',
        'source_placeholder' => 'Konto auswählen',
        'no_accounts' => 'Verbinde zuerst ein Instagram- oder Facebook-Konto. Nur diese können Quelle sein, weil nur sie den Download des Videos erlauben.',
        'submit' => 'Erstellen',
        'connect' => 'Konto verbinden',
    ],

    'show' => [
        'title' => 'Repurpose',
        'saving' => 'Wird gespeichert...',
        'saved' => 'Gespeichert',
    ],

    'tabs' => [
        'configuration' => 'Konfiguration',
        'activity' => 'Aktivität',
        'settings' => 'Einstellungen',
    ],

    'destinations' => [
        'paused_note' => 'Deaktiviert und übersprungen, bis du sie wieder einschaltest: :accounts',
        'title' => 'Ziele',
        'description' => 'Wähle die Konten, die es erhalten. Jedes veröffentlicht im Format deiner Wahl.',
        'hint' => 'Der Text wird nur dann pro Netzwerk angepasst, wenn er dessen Limit überschreitet.',
        'none_available' => 'In diesem Workspace ist noch kein weiteres Konto verbunden.',
        'publish_as' => 'Veröffentlichen als',
    ],

    'status_card' => [
        'title' => 'Status',
        'activate' => 'Aktivieren',
        'pause' => 'Pausieren',
        'resume' => 'Fortsetzen',
        'disable' => 'Deaktivieren',
        'watermark' => 'Beobachtet seit',
        'last_polled' => 'Zuletzt geprüft',
        'draft_hint' => 'Wähle mindestens ein Ziel und aktiviere dann. Nur Beiträge nach der Aktivierung werden repliziert.',
        'active_hint' => 'TryPost prüft dieses Konto regelmäßig und repliziert jeden neuen Beitrag.',
        'paused_hint' => 'Die Prüfungen pausieren. Beim Fortsetzen geht es dort weiter, wo es aufgehört hat, nichts geht verloren.',
        'disabled_hint' => 'Ausgeschaltet. Beim erneuten Aktivieren beginnt es von vorn: Was du währenddessen gepostet hast, bleibt außen vor.',
    ],

    'items' => [
        'source' => 'Original',
        'published_at' => 'Gepostet',
        'status' => 'Status',
        'detail' => 'Detail',
        'posts' => 'Repliziert auf',
        'view_original' => 'Original ansehen',
        'original_from' => 'Original vom :date',
        'empty' => [
            'title' => 'Noch nichts',
            'description' => 'Beiträge, die dieses Konto außerhalb von TryPost veröffentlicht, erscheinen hier.',
        ],
        'open_post' => 'Beitrag öffnen',
        'statuses' => [
            'pending' => 'In Warteschlange',
            'processing' => 'Wird verarbeitet',
            'published' => 'Repliziert',
            'drafted' => 'Entwurf',
            'skipped' => 'Übersprungen',
            'failed' => 'Fehlgeschlagen',
        ],
        'reasons' => [
            'published_via_trypost' => 'Bereits über TryPost veröffentlicht',
            'media_url_missing' => 'Das Netzwerk hat keine herunterladbare Datei bereitgestellt, meist wegen urheberrechtlich geschütztem Audio',
            'download_failed' => 'Das Video konnte nicht heruntergeladen werden',
            'post_creation_failed' => 'Die Beiträge konnten nicht erstellt werden',
            'no_usable_destinations' => 'Kein Ziel war zum Veröffentlichen verfügbar',
        ],
    ],

    'menu' => [

        'label' => 'Weitere Aktionen',

    ],

    'danger' => [
        'title' => 'Dieses Repurpose löschen',
        'description' => 'Die Prüfungen stoppen sofort. Bereits erstellte Beiträge bleiben in deinem Kalender.',
        'delete' => 'Repurpose löschen',
    ],

    'health' => [
        'stopped_itself' => 'Von selbst gestoppt – öffnen, um zu sehen warum',
        'source_missing' => 'Die Replikation pausiert: Diese Automatisierung hat kein Quellkonto. Wähle eines und setze sie fort.',
        'source_unusable' => 'Die Replikation pausiert: Das überwachte Konto muss neu verbunden werden.',
        'no_destinations' => 'Die Replikation pausiert: Kein Ziel verfügbar. Füge eines hinzu und setze sie fort.',
        'ready' => 'Das Problem ist behoben. Setze diese Automatisierung fort, um wieder zu replizieren.',
    ],

    'errors' => [
        'source_already_used' => 'Dieses Konto speist bereits ein anderes Repurpose. Bearbeite stattdessen jenes.',
        'source_missing' => 'Wähle ein Konto zur Überwachung aus, bevor du diese Automatisierung startest.',
        'source_unusable' => 'Verbinde das überwachte Konto erneut, bevor du diese Automatisierung startest.',
        'destinations_required' => 'Wähle vor dem Aktivieren mindestens ein Ziel.',
        'destination_needs_video' => 'Dieses Format kann kein Video tragen.',
        'only_paused_resumes' => 'Nur ein pausiertes Repurpose kann fortgesetzt werden.',
        'only_active_pauses' => 'Nur ein aktives Repurpose kann pausiert werden.',
        'only_running_disables' => 'Nur ein laufendes Repurpose kann deaktiviert werden.',
        'only_idle_activates' => 'Nur ein Entwurf oder ein deaktiviertes Repurpose kann aktiviert werden.',
        'destination_unavailable' => 'Dieses Zielkonto ist nicht mehr verfügbar.',
        'destination_is_source' => 'Dieses Ziel ist das Konto, das dieses Repurpose beobachtet.',
        'source_unavailable' => 'Dieses Quellkonto ist nicht mehr verfügbar.',
        'action_failed' => 'Etwas ist schiefgelaufen. Prüfe das Formular und versuche es erneut.',
    ],
];

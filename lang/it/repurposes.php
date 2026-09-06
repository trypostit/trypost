<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'Ripubblica automaticamente sulle altre reti i video che pubblichi fuori da TryPost.',
    'new' => 'Nuovo repurpose',

    'flow' => [
        'no_destinations' => 'Nessuna destinazione',
    ],

    'publish_mode' => [

        'title' => 'Pubblicazione',

        'description' => 'Cosa succede quando compare un nuovo video.',

    ],

    'publish_modes' => [

        'publish' => 'Pubblica automaticamente',

        'publish_hint' => 'Ogni nuovo video viene programmato appena viene trovato.',

        'draft' => 'Crea come bozza',

        'draft_hint' => 'Ogni nuovo video diventa una bozza da rivedere e pubblicare qui.',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Video',
        'story' => 'Storie',
    ],

    'source' => [
        'title' => 'Origine',
        'description' => 'TryPost tiene d\'occhio questo account per i nuovi video del formato qui sotto.',
        'watch_label' => 'Osserva',
    ],

    'summary' => [
        'sentence' => 'Ogni nuovo :format che pubblichi su :source viene ripubblicato su :destinations.',
        'no_destinations' => 'Ogni nuovo :format che pubblichi su :source sta aspettando una destinazione.',
    ],

    'empty' => [
        'title' => 'Nessun repurpose configurato',
        'description' => 'Scegli un punto di partenza qui sotto. TryPost tiene d\'occhio l\'account scelto e ripubblica ogni nuovo video sulle reti che selezioni.',
    ],

    'table' => [
        'flow' => 'Flusso',
        'source' => 'Origine',
        'destinations' => 'Destinazioni',
        'status' => 'Stato',
        'published' => 'Replicati',
        'last_polled' => 'Ultimo controllo',
    ],

    'status' => [
        'draft' => 'Bozza',
        'active' => 'Attivo',
        'paused' => 'In pausa',
        'disabled' => 'Disattivato',
    ],

    'templates' => [
        'use' => 'Usa questo modello',
        'instagram_everywhere' => [
            'title' => 'Instagram ovunque',
            'description' => 'Pubblica un Reel su Instagram e TryPost lo ripubblica su TikTok, YouTube Shorts e Facebook.',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook ovunque',
            'description' => 'Pubblica un video sulla tua Pagina Facebook e TryPost lo ripubblica su Instagram, TikTok e YouTube Shorts.',
        ],
    ],

    'create' => [
        'title' => 'Nuovo repurpose',
        'description' => 'Scegli l\'account che TryPost deve seguire. Le destinazioni si scelgono nella schermata successiva.',
        'source_label' => 'Account di origine',
        'source_placeholder' => 'Seleziona un account',
        'no_accounts' => 'Collega prima un account Instagram o Facebook. Solo questi possono essere origine, perché sono le uniche reti che permettono di scaricare il video.',
        'submit' => 'Crea',
        'connect' => 'Collega un account',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'I video pubblicati su questo account fuori da TryPost vengono replicati sulle destinazioni qui sotto.',
    ],

    'tabs' => [
        'configuration' => 'Configurazione',
        'activity' => 'Attività',
        'settings' => 'Impostazioni',
    ],

    'destinations' => [
        'title' => 'Destinazioni',
        'description' => 'Scegli gli account che lo riceveranno. Ognuno pubblica nel formato che imposti.',
        'hint' => 'La didascalia viene adattata per rete solo quando supera il limite di quella rete.',
        'none_available' => 'Nessun altro account è collegato in questo workspace.',
        'save' => 'Salva destinazioni',
        'saved' => 'Destinazioni salvate',
        'publish_as' => 'Pubblica come',
    ],

    'status_card' => [
        'title' => 'Stato',
        'activate' => 'Attiva',
        'pause' => 'Metti in pausa',
        'resume' => 'Riprendi',
        'disable' => 'Disattiva',
        'watermark' => 'In ascolto da',
        'last_polled' => 'Ultimo controllo',
        'draft_hint' => 'Scegli almeno una destinazione, poi attiva. Vengono replicati solo i video pubblicati dopo l\'attivazione.',
        'active_hint' => 'TryPost controlla questo account con regolarità e replica ogni nuovo video.',
        'paused_hint' => 'I controlli sono sospesi. Riprendendo si riparte da dove si era fermato e non si perde nulla.',
        'disabled_hint' => 'Disattivato. Riattivandolo si riparte da zero: ciò che hai pubblicato mentre era spento resta fuori.',
    ],

    'items' => [
        'source' => 'Originale',
        'published_at' => 'Pubblicato',
        'status' => 'Stato',
        'detail' => 'Dettaglio',
        'posts' => 'Replicato su',
        'view_original' => 'Vedi originale',
        'open_post' => 'Apri post',
        'statuses' => [
            'pending' => 'In coda',
            'processing' => 'In elaborazione',
            'published' => 'Replicato',
            'drafted' => 'Bozza',
            'skipped' => 'Ignorato',
            'failed' => 'Non riuscito',
        ],
        'reasons' => [
            'published_via_trypost' => 'Già pubblicato tramite TryPost',
            'media_url_missing' => 'La rete non ha fornito un file scaricabile, di solito per audio protetto da copyright',
            'download_failed' => 'Non è stato possibile scaricare il video',
            'post_creation_failed' => 'Nessuna destinazione disponibile',
        ],
    ],

    'danger' => [
        'title' => 'Elimina questo repurpose',
        'description' => 'I controlli si fermano subito. I post già creati restano nel tuo calendario.',
        'delete' => 'Elimina repurpose',
    ],

    'errors' => [
        'source_already_used' => 'Questo account alimenta già un altro repurpose. Modifica quello.',
        'destinations_required' => 'Scegli almeno una destinazione prima di attivare.',
        'destination_needs_video' => 'Quel formato non accetta video.',
        'only_paused_resumes' => 'Solo un repurpose in pausa può essere ripreso.',
        'only_active_pauses' => 'Solo un repurpose attivo può essere messo in pausa.',
        'only_running_disables' => 'Solo un repurpose in esecuzione può essere disattivato.',
        'only_idle_activates' => 'Solo una bozza o un repurpose disattivato può essere attivato.',
        'destination_unavailable' => 'Quell\'account di destinazione non è più disponibile.',
        'destination_is_source' => 'Quella destinazione è l\'account che questo repurpose osserva.',
        'source_unavailable' => 'Quell\'account di origine non è più disponibile.',
        'action_failed' => 'Qualcosa è andato storto. Controlla il modulo e riprova.',
    ],
];

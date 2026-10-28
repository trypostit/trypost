<?php

declare(strict_types=1);

return [
    'title' => 'Calendario',
    'today' => 'Oggi',
    'previous' => 'Precedente',
    'next' => 'Successivo',
    'days' => '3 giorni',
    'week' => 'Settimana',
    'month' => 'Mese',
    'new_post' => 'Nuovo post',
    'no_content' => 'Nessun contenuto',
    'more' => '+:count in più',
    'less' => 'Mostra meno',
    'empty_day' => 'Niente in programma per questo giorno.',
    'status' => [
        'label' => 'Stato',
        'all' => 'Tutti i post',
        'drafts' => 'Bozze',
        'scheduled' => 'Programmati',
        'sent' => 'Inviati',
    ],
    'no_date' => 'Senza data',
    'undated' => [
        'title' => 'Bozze senza data',
        'description' => 'Bozze senza una data programmata.',
        'close' => 'Chiudi',
        'empty' => 'Nessuna bozza senza data.',
        'draft' => 'Bozza',
        'no_channel' => 'Nessun canale',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => 'Sposta il post a un orario personalizzato',
            'description' => 'Stai spostando questo post da uno slot della coda, <strong>:from <i>(:zone)</i></strong>, a un orario personalizzato, <strong>:to <i>(:zone)</i></strong>, che non è uno dei tuoi orari di pubblicazione.',
            'unqueued' => 'Il post verrà pubblicato al nuovo orario scelto, ma non occuperà un posto nella tua coda.',
            'dont_show_again' => 'Non mostrare più',
            'cancel' => 'Annulla',
            'confirm' => 'Sposta all\'orario scelto',
        ],
        'error' => 'Impossibile spostare questo post. Riprova.',
        'errors' => [
            'past' => 'Questo orario è già passato. Scegli un orario successivo.',
            'required' => 'Scegli un orario per questo post.',
            'pending' => 'Questo post è in attesa di approvazione. Spostalo dopo che è stato approvato o rifiutato.',
            'publishing_soon' => 'Questo post sta per essere pubblicato, quindi non può più essere spostato.',
            'too_far' => 'Scegli una data precedente al 19 gennaio 2038.',
        ],
        'drag_hint' => 'Trascina per riprogrammare',
        'drag_hint_undated' => 'Trascina la bozza senza data di :channel per programmarla',
        'announce' => [
            'over' => 'l\'elemento programmato per :from è sopra :target.',
            'over_undated' => 'La bozza senza data di :channel è sopra :target.',
            'moved' => 'l\'elemento programmato per :from è stato riprogrammato per :to.',
            'returned' => 'L\'elemento è tornato nella posizione originale.',
            'undated' => 'l\'elemento programmato per :from è stato rilasciato su Senza data.',
            'from_undated' => 'La bozza senza data di :channel è stata programmata per :to.',
            'date_time' => ':date alle :time',
        ],
        'toast' => [
            'moved' => 'Il tuo post è stato riprogrammato per :time.',
            'moved_draft' => 'La tua bozza è stata riprogrammata per :time.',
            'undated' => 'La tua bozza è stata spostata in Senza data.',
            'unqueued_suffix' => 'Lo slot della coda è di nuovo libero.',
        ],
    ],
];

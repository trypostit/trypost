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
            'description' => 'Stai spostando questo post da uno slot della coda, :from, a un orario personalizzato, :to, che non è uno dei tuoi orari di pubblicazione.',
            'unqueued' => 'Il post verrà pubblicato al nuovo orario scelto, ma non occuperà un posto nella tua coda.',
            'dont_show_again' => 'Non mostrare più',
            'cancel' => 'Annulla',
            'confirm' => 'Sposta all\'orario scelto',
        ],
        'error' => 'Impossibile spostare questo post. Riprova.',
    ],
];

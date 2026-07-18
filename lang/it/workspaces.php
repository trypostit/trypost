<?php

declare(strict_types=1);

return [
    'title' => 'Workspace',
    'select_title' => 'I tuoi workspace',
    'select_description' => 'Seleziona un workspace per continuare',
    'current' => 'Attuale',
    'connections' => ':count connessioni',
    'posts' => ':count post',

    'create' => [
        'page_title' => 'Crea il tuo workspace',
        'title' => 'Configura il tuo workspace',
        'description' => 'Raccontaci un po\' di te o del tuo progetto. Lo useremo per adattare i post generati dall\'IA al tuo tono.',
        'website' => 'Sito web',
        'website_placeholder' => 'https://iltuobrand.com',
        'autofill' => 'Compila automaticamente dal sito web',
        'autofill_missing_url' => 'Inserisci prima un URL.',
        'autofill_success' => 'Informazioni del brand caricate.',
        'autofill_error' => 'Impossibile compilare automaticamente. Puoi compilare i campi manualmente.',
        'autofill_errors' => [
            'unreachable' => 'Non siamo riusciti a raggiungere quel sito web (:reason).',
            'http_status' => 'Il sito web ha restituito uno stato inatteso (:status).',
            'invalid_scheme' => 'Sono supportati solo URL http e https.',
            'missing_host' => 'All\'URL manca un host.',
            'unresolvable_host' => 'Non siamo riusciti a risolvere l\'host (:host).',
            'private_network' => 'Gli URL che puntano a reti private non sono consentiti.',
        ],
        'logo_captured' => 'Logo acquisito dal tuo sito web.',
        'name' => 'Nome del workspace',
        'name_placeholder' => 'es. Acme Inc',
        'brand_description' => 'Descrizione del brand',
        'brand_description_placeholder' => 'Cosa fa il tuo brand?',
        'content_language' => 'Lingua dei contenuti',
        'content_language_description' => 'Le didascalie generate dall\'IA saranno scritte in questa lingua.',
        'brand_color' => 'Colore del brand',
        'background_color' => 'Colore di sfondo',
        'text_color' => 'Colore del testo',
        'submit' => 'Crea workspace',
        'success' => 'Workspace creato. Collega un account social per iniziare a pubblicare.',
    ],

    'cannot_delete_last' => 'Non puoi eliminare il tuo unico workspace. Annulla il tuo abbonamento nelle impostazioni di fatturazione per chiudere il tuo account.',

    'flash' => [
        'deleted' => 'Workspace eliminato con successo.',
    ],
];

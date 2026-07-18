<?php

declare(strict_types=1);

return [
    'title' => 'Workspaces',
    'select_title' => 'Je workspaces',
    'select_description' => 'Selecteer een workspace om door te gaan',
    'current' => 'Huidige',
    'connections' => ':count koppelingen',
    'posts' => ':count posts',

    'create' => [
        'page_title' => 'Maak je workspace aan',
        'title' => 'Stel je workspace in',
        'description' => 'Vertel ons wat over jou of je project. We gebruiken dit om AI-gegenereerde posts af te stemmen op jouw stijl.',
        'website' => 'Website',
        'website_placeholder' => 'https://jouwmerk.nl',
        'autofill' => 'Automatisch invullen vanaf website',
        'autofill_missing_url' => 'Voer eerst een URL in.',
        'autofill_success' => 'Merkinformatie geladen.',
        'autofill_error' => 'Automatisch invullen mislukt. Je kunt de velden handmatig invullen.',
        'autofill_errors' => [
            'unreachable' => 'We konden die website niet bereiken (:reason).',
            'http_status' => 'De website gaf een onverwachte status terug (:status).',
            'invalid_scheme' => 'Alleen http- en https-URL\'s worden ondersteund.',
            'missing_host' => 'De URL mist een host.',
            'unresolvable_host' => 'We konden de host niet omzetten (:host).',
            'private_network' => 'URL\'s die naar privénetwerken verwijzen zijn niet toegestaan.',
        ],
        'logo_captured' => 'Logo opgehaald van je website.',
        'name' => 'Workspacenaam',
        'name_placeholder' => 'bijv. Acme Inc',
        'brand_description' => 'Merkomschrijving',
        'brand_description_placeholder' => 'Wat doet je merk?',
        'content_language' => 'Contenttaal',
        'content_language_description' => 'AI-gegenereerde bijschriften worden in deze taal geschreven.',
        'brand_color' => 'Merkkleur',
        'background_color' => 'Achtergrondkleur',
        'text_color' => 'Tekstkleur',
        'submit' => 'Workspace aanmaken',
        'success' => 'Workspace aangemaakt. Koppel een social account om te beginnen met posten.',
    ],

    'cannot_delete_last' => 'Je kunt je enige workspace niet verwijderen. Zeg je abonnement op in de facturatie-instellingen om je account te sluiten.',

    'flash' => [
        'deleted' => 'Workspace succesvol verwijderd.',
    ],
];

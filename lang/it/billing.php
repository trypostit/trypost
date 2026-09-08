<?php

return [
    'title' => 'Fatturazione',

    'past_due_notice' => [
        'title' => 'Pagamento scaduto',
        'description' => 'Aggiorna il tuo metodo di pagamento per mantenere attivo l\'abbonamento.',
        'cta' => 'Aggiorna pagamento',
    ],

    'annual_banner' => [
        'title' => 'Ottieni 2 mesi gratis',
        'description' => 'Passa alla fatturazione annuale e paga meno ogni mese: stesso piano, nient\'altro cambia.',
        'cta' => 'Passa all\'annuale',
    ],

    'subscribe' => [
        'billed_monthly' => 'Fatturazione mensile',
        'billed_yearly' => 'Fatturazione annuale',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => 'Piani',
        'description' => 'Fai upgrade o downgrade in qualsiasi momento.',
        'monthly' => 'Mensile',
        'yearly' => 'Annuale',
        'save_two_months' => '2 mesi gratis',
        'per_month' => '/mese',
        'workspaces_one' => '1 workspace',
        'workspaces_unlimited' => 'Workspace illimitati',
        'current' => 'Piano attuale',
        'select' => 'Scegli :plan',
        'start_first_month' => 'Inizia il primo mese a :price',

        'billed_yearly_total' => 'Fatturato annualmente · :price (2 mesi gratis)',
        'socials_tagline' => 'Ideale per creator e piccole marche.',
        'workspaces_tagline' => 'Ideale per agenzie e grandi attività.',
        'everything_included' => 'Tutto incluso',
        'features' => [
            'networks_all' => 'Tutti i social network',
            'accounts_unlimited' => 'Account social illimitati',
            'calendar' => 'Calendario visuale con pubblicazione automatica',
            'ai' => 'IA: didascalie, immagini e brand voice',
            'mcp' => 'MCP: crea e programma da Claude, ChatGPT o Grok',
            'repurpose' => 'Repurpose: trasforma un post in tanti',
            'analytics' => 'Analytics per post e per account',
            'team' => 'Team, ruoli e approvazioni illimitati',
        ],
    ],

    'plan' => [
        'title' => 'Piano',
        'description' => 'Gestisci il tuo piano di abbonamento.',
        'label' => 'Piano',
        'price' => 'Prezzo',
        'month' => 'mese',
        'trial' => 'Prova',
        'active' => 'Attivo',
        'past_due' => 'Scaduto',
        'cancelling' => 'In cancellazione',
        'trial_ends' => 'La prova termina',
    ],

    'subscription' => [
        'title' => 'Abbonamento',
        'description' => 'Gestisci il tuo metodo di pagamento, i dati di fatturazione e l\'abbonamento.',
        'payment_method' => 'Metodo di pagamento',
        'no_payment_method' => 'Nessun metodo di pagamento ancora registrato.',
        'expires_on' => 'Scade il :month/:year',
        'manage_label' => 'Abbonamento',
        'manage_stripe' => 'Gestisci su Stripe',
    ],

    'invoices' => [
        'title' => 'Fatture',
        'description' => 'Scarica le tue fatture passate.',
        'empty' => 'Nessuna fattura trovata',
        'paid' => 'Pagata',
    ],

    'flash' => [
        'plan_changed' => 'Ora sei sul piano :plan.',
        'switched_to_yearly' => 'Ora hai la fatturazione annuale.',
        'cannot_manage' => 'Solo il proprietario dell\'account può gestire la fatturazione.',
        'too_many_workspaces' => 'Hai :count workspace. Questo piano ne include :limit — elimina quelli extra prima di cambiare.',
        'subscription_required' => 'È richiesto un abbonamento attivo per usare le funzioni IA.',
    ],

    'processing' => [
        'page_title' => 'Elaborazione...',
        'title' => 'Elaborazione del tuo abbonamento',
        'description' => 'Attendi mentre configuriamo il tuo account. Ci vorrà solo un momento.',
        'success_title' => 'Tutto pronto!',
        'success_description' => 'Il tuo abbonamento è attivo. Ti stiamo reindirizzando ai tuoi workspace...',
        'cancelled_title' => 'Pagamento annullato',
        'cancelled_description' => 'Il tuo pagamento è stato annullato. Non è stato effettuato alcun addebito.',
        'retry' => 'Riprova',
    ],
];

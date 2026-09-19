<?php

return [
    'title' => 'Fatturazione',

    'past_due_notice' => [
        'title' => 'Pagamento scaduto',
        'description' => 'Aggiorna il tuo metodo di pagamento per mantenere attivo l\'abbonamento.',
        'cta' => 'Aggiorna pagamento',
    ],

    'subscribe' => [
        'billed_monthly' => 'Fatturazione mensile',
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
        'workspaces_one' => 'Un workspace',
        'workspaces_unlimited' => 'Workspace illimitati',
        'workspaces_tooltip' => 'Un workspace è un brand o un cliente, separato dagli altri: con i propri account social, firme, etichette, analytics, permessi dei membri e connessione MCP.',
        'current' => 'Piano attuale',
        'switch_to_yearly' => 'Passa all’annuale',
        'switch_to_monthly' => 'Passa al mensile',
        'select' => 'Scegli :plan',
        'upgrade' => 'Fai upgrade a :plan',
        'downgrade' => 'Fai downgrade a :plan',
        'start_first_month' => 'Inizia a :price',
        'per_first_month' => '/primo mese',
        'then_monthly' => 'Poi :price/mese',
        'billed_yearly_total' => 'Fatturato annualmente · :price (2 mesi gratis)',
        'socials_tagline' => 'Ideale per creator e piccole marche.',
        'workspaces_tagline' => 'Ideale per agenzie e grandi attività.',
        'everything_included' => 'Tutto incluso',
        'features' => [
            'networks_all' => 'Tutti i social network inclusi',
            'networks_all_tooltip' => 'Puoi pubblicare su tutti questi social.',
            'accounts_unlimited' => 'Account social illimitati',
            'accounts_unlimited_tooltip' => 'Collega tutti gli account che vuoi, anche più di uno dello stesso social. Tre Instagram, per esempio.',
            'calendar' => 'Calendario mensile, settimanale e giornaliero',
            'calendar_tooltip' => 'Guarda tutto il mese in un colpo d\'occhio: cosa è pianificato, programmato e già pubblicato. Passa a settimana o giorno quando ti serve il dettaglio.',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => 'Il tuo assistente IA per scrivere e rivedere i post.',
            'mcp' => 'MCP: pubblica da Claude, ChatGPT o Grok',
            'mcp_tooltip' => 'Collega Claude, ChatGPT o Grok al tuo workspace. Chiedigli di creare e programmare post, recuperare le metriche, capire cosa ha funzionato meglio e pianificare il prossimo contenuto dai tuoi dati.',
            'repurpose' => 'Repurpose: trasforma un post in tanti',
            'repurpose_tooltip' => 'Scegli un account di origine. Ogni nuovo post che pubblichi lì viene ripubblicato automaticamente sugli altri tuoi social. Non devi aprire TryPost.',
            'analytics' => 'Analytics',
            'analytics_tooltip' => 'Ottieni metriche come impression, copertura, like e commenti per ogni post e ogni account, tutto in un unico posto.',
            'team' => 'Membri illimitati',
            'team_tooltip' => 'Invita nel team tutte le persone che vuoi, senza costi extra. Decidi cosa può fare ciascuna e approva i post prima della pubblicazione.',
        ],
    ],

    'plan' => [
        'trial' => 'Prova',
        'cancelling' => 'In cancellazione',
        'trial_ends' => 'La prova termina',
    ],

    'subscription' => [
        'title' => 'Metodo di pagamento',
        'description' => 'Aggiorna la carta o i dati di fatturazione su Stripe.',
        'no_payment_method' => 'Nessun metodo di pagamento ancora registrato.',
        'expires_on' => 'Scade il :month/:year',
        'manage_stripe' => 'Gestisci su Stripe',
    ],

    'invoices' => [
        'title' => 'Fatture',
        'description' => 'Scarica le tue fatture passate.',
        'paid' => 'Pagata',
    ],

    'flash' => [
        'plan_changed' => 'Ora sei sul piano :plan.',
        'cannot_manage' => 'Solo il proprietario dell\'account può gestire la fatturazione.',
        'too_many_workspaces' => 'Hai :count workspace. Questo piano ne include :limit — elimina quelli extra prima di cambiare.',
        'subscription_required' => 'È richiesto un abbonamento attivo per usare le funzioni IA.',
    ],

    'processing' => [
        'page_title' => 'Elaborazione...',
        'title' => 'Elaborazione del tuo abbonamento',
        'description' => 'Attendi mentre configuriamo il tuo account. Ci vorrà solo un momento.',
    ],
];

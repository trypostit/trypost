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
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'description' => 'Fai upgrade o downgrade in qualsiasi momento.',
        'monthly' => 'Mensile',
        'yearly' => 'Annuale',
        'save_two_months' => '2 mesi gratis',
        'per_month' => '/mese',
        'per_year' => '/anno',
        'workspaces_one' => 'Un workspace',
        'workspaces_unlimited' => 'Workspace illimitati',
        'workspaces_tooltip' => 'Un workspace è un brand o un cliente, separato dagli altri: con i propri account social, firme, etichette, analytics, permessi dei membri e connessione MCP.',
        'current' => 'Piano attuale',
        'switch_to_yearly' => 'Passa all’annuale',
        'switch_to_monthly' => 'Passa al mensile',
        'select' => 'Scegli :plan',
        'upgrade' => 'Fai upgrade a :plan',
        'downgrade' => 'Fai downgrade a :plan',
        'start_first_month' => 'Attiva a :price',
        'billed_yearly_total' => 'Fatturato annualmente · :price (2 mesi gratis)',
        'socials_tagline' => 'Ideale per creator e piccole marche.',
        'workspaces_tagline' => 'Ideale per agenzie e grandi attività.',
        'everything_included' => 'Tutto incluso',
        'features' => [
            'networks_all' => 'Tutti i social network inclusi',
            'networks_all_tooltip' => 'Pubblica su tutti da un unico posto.',
            'accounts_unlimited' => 'Account social illimitati',
            'accounts_unlimited_tooltip' => 'Collega tutti gli account che vuoi, anche più di uno dello stesso social. Tre Instagram, per esempio.',
            'calendar' => 'Calendario dei contenuti',
            'ideas' => 'Pianificazione dei contenuti',
            'ideas_tooltip' => 'Annota le tue idee, organizzale in un unico posto e trasforma le migliori in contenuti quando vuoi.',
            'calendar_tooltip' => 'Vedi tutti i post programmati e pubblicati in un unico posto, per mese o per settimana.',
            'ai' => 'Assistente IA per scrivere',
            'ai_tooltip' => 'Scrivi, riscrivi e regola il tono dei tuoi post con l’IA, nella tua lingua.',
            'mcp' => 'Usa TryPost da Claude o ChatGPT',
            'mcp_tooltip' => 'Collega Claude, ChatGPT o un’altra IA. Chiedile di creare e programmare post, riempire la coda, organizzare le idee e mostrarti la crescita.',
            'repurpose' => 'Ripubblicazione automatica dei video',
            'repurpose_tooltip' => 'Hai pubblicato un video su Instagram? TryPost lo ripubblica automaticamente su TikTok, YouTube, Facebook e sugli altri tuoi social.',
            'analytics' => 'Analisi avanzate',
            'analytics_tooltip' => 'Vedi tutti i tuoi social insieme, come i follower totali, o filtra per social e per post. Visualizzazioni, copertura, like, commenti e altro, per capire cosa funziona.',
            'team' => 'Membri del team illimitati',
            'team_tooltip' => 'Invita nel team tutte le persone che vuoi, senza costi extra. Decidi cosa può fare ciascuna e approva i post prima della pubblicazione.',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => 'Attivo',
            'trial' => 'Prova gratuita',
            'cancelling' => 'Annullato',
            'past_due' => 'Scaduto',
        ],
        'change' => 'Cambia piano',
        'price_monthly' => ':price/mese',
        'price_yearly' => ':price/anno',
        'trial_until' => 'Prova fino al :date',
        'cancelled' => 'Annullato, termina il :date',
        'discount_until' => ':discount di sconto fino al :date',
        'discount_forever' => ':discount di sconto su ogni pagamento',
        'discount_once' => ':discount di sconto sul prossimo pagamento',
    ],

    'subscription' => [
        'title' => 'Metodi di pagamento',
        'default' => 'Predefinito',
        'description' => 'Aggiorna la carta o i dati di fatturazione su Stripe.',
        'no_payment_method' => 'Nessun metodo di pagamento ancora registrato.',
        'expires_on' => 'Scade il :month/:year',
        'manage_stripe' => 'Gestisci su Stripe',
    ],

    'tabs' => [
        'plan' => 'Piano',
        'invoices' => 'Fatture',
    ],

    'invoices' => [
        'columns' => [
            'date' => 'Data',
            'amount' => 'Importo',
            'status' => 'Stato',
        ],
        'empty_title' => 'Ancora nessuna fattura',
        'empty_description' => 'Le tue fatture appariranno qui dopo il primo pagamento.',
        'paid' => 'Pagata',
        'download' => 'Scarica fattura',
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

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
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
        ],
    ],

    'plan' => [
        'title' => 'Piano',
        'description' => 'Gestisci il tuo piano di abbonamento.',
        'label' => 'Piano',
        'workspaces' => '{1}:count workspace|[2,*]:count workspace',
        'per_workspace' => 'per workspace',
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
        'credits_exhausted' => 'Crediti IA esauriti: la tua quota mensile di :limit è stata utilizzata. Aggiorna il tuo piano o attendi il mese prossimo.',
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

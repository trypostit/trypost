<?php

declare(strict_types=1);

return [
    'title' => 'Abrechnung',

    'past_due_notice' => [
        'title' => 'Zahlung überfällig',
        'description' => 'Aktualisiere deine Zahlungsmethode, um dein Abonnement aktiv zu halten.',
        'cta' => 'Zahlung aktualisieren',
    ],

    'annual_banner' => [
        'title' => '2 Monate gratis erhalten',
        'description' => 'Wechsle zur jährlichen Abrechnung und zahle jeden Monat weniger – gleicher Tarif, sonst ändert sich nichts.',
        'cta' => 'Auf jährlich upgraden',
    ],

    'subscribe' => [
        'billed_monthly' => 'Monatlich abgerechnet',
        'billed_yearly' => 'Jährlich abgerechnet',
        'prices' => [
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
        ],
    ],

    'plan' => [
        'title' => 'Tarif',
        'description' => 'Verwalte deinen Abonnement-Tarif.',
        'label' => 'Tarif',
        'workspaces' => '{1}:count Workspace|[2,*]:count Workspaces',
        'per_workspace' => 'pro Workspace',
        'price' => 'Preis',
        'month' => 'Monat',
        'trial' => 'Testphase',
        'active' => 'Aktiv',
        'past_due' => 'Überfällig',
        'cancelling' => 'Wird gekündigt',
        'trial_ends' => 'Testphase endet',
    ],

    'subscription' => [
        'title' => 'Abonnement',
        'description' => 'Verwalte deine Zahlungsmethode, Rechnungsdaten und dein Abonnement.',
        'payment_method' => 'Zahlungsmethode',
        'no_payment_method' => 'Noch keine Zahlungsmethode hinterlegt.',
        'expires_on' => 'Läuft ab :month/:year',
        'manage_label' => 'Abonnement',
        'manage_stripe' => 'Bei Stripe verwalten',
    ],

    'invoices' => [
        'title' => 'Rechnungen',
        'description' => 'Lade deine bisherigen Rechnungen herunter.',
        'empty' => 'Keine Rechnungen gefunden',
        'paid' => 'Bezahlt',
    ],

    'flash' => [
        'plan_changed' => 'Du nutzt jetzt den Tarif :plan.',
        'switched_to_yearly' => 'Du nutzt jetzt die jährliche Abrechnung.',
        'cannot_manage' => 'Nur der Kontoinhaber kann die Abrechnung verwalten.',
        'credits_exhausted' => 'Keine KI-Credits mehr – dein monatliches Kontingent von :limit ist aufgebraucht. Führe ein Upgrade durch oder warte bis zum nächsten Monat.',
        'subscription_required' => 'Für die Nutzung der KI-Funktionen ist ein aktives Abonnement erforderlich.',
    ],

    'processing' => [
        'page_title' => 'Wird verarbeitet...',
        'title' => 'Dein Abonnement wird verarbeitet',
        'description' => 'Bitte warte, während wir dein Konto einrichten. Das dauert nur einen Moment.',
        'success_title' => 'Alles bereit!',
        'success_description' => 'Dein Abonnement ist aktiv. Du wirst zu deinen Workspaces weitergeleitet...',
        'cancelled_title' => 'Bezahlvorgang abgebrochen',
        'cancelled_description' => 'Dein Bezahlvorgang wurde abgebrochen. Es wurden keine Kosten berechnet.',
        'retry' => 'Erneut versuchen',
    ],
];

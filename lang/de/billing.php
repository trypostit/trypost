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
            'first_month' => '$1',
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => 'Tarife',
        'description' => 'Jederzeit upgraden oder downgraden.',
        'monthly' => 'Monatlich',
        'yearly' => 'Jährlich',
        'save_two_months' => '2 Monate gratis',
        'per_month' => '/Monat',
        'workspaces_one' => '1 Workspace',
        'workspaces_unlimited' => 'Unbegrenzte Workspaces',
        'current' => 'Aktueller Tarif',
        'select' => ':plan wählen',
        'start_first_month' => 'Meinen ersten Monat für :price starten',
        'first_month_then' => 'Erster Monat :first, danach :price/Monat',

        'billed_yearly_total' => 'Jährlich abgerechnet · :price (2 Monate gratis)',
        'socials_tagline' => 'Ein Workspace. Überall posten.',
        'workspaces_tagline' => 'Ein Workspace für jede Marke oder jeden Kunden.',
        'features' => [
            'accounts_unlimited' => 'Unbegrenzte Social-Accounts',
            'calendar' => 'Visueller Kalender mit Auto-Publishing',
            'ai' => 'KI: Captions, Bilder und Markenstimme',
            'mcp' => 'MCP: erstellen und planen mit Claude, ChatGPT oder Grok',
            'repurpose' => 'Repurpose: aus einem Post viele machen',
            'analytics' => 'Analytics pro Post und Account',
            'team' => 'Unbegrenztes Team, Rollen und Freigaben',
        ],
    ],

    'plan' => [
        'title' => 'Tarif',
        'description' => 'Verwalte deinen Abonnement-Tarif.',
        'label' => 'Tarif',
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
        'too_many_workspaces' => 'Du hast :count Workspaces. Dieser Tarif umfasst :limit — lösche die überzähligen, bevor du wechselst.',
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

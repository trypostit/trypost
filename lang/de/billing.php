<?php

declare(strict_types=1);

return [
    'title' => 'Abrechnung',

    'past_due_notice' => [
        'title' => 'Zahlung überfällig',
        'description' => 'Aktualisiere deine Zahlungsmethode, um dein Abonnement aktiv zu halten.',
        'cta' => 'Zahlung aktualisieren',
    ],

    'subscribe' => [
        'billed_monthly' => 'Monatlich abgerechnet',
        'prices' => [
            'first_month' => '$1',
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
        'workspaces_one' => 'Ein Workspace',
        'workspaces_unlimited' => 'Unbegrenzte Workspaces',
        'workspaces_tooltip' => 'Ein Workspace ist eine Marke oder ein Kunde, getrennt von allen anderen: mit eigenen Social-Media-Konten, Signaturen, Labels, Analytics, Mitgliederrechten und MCP-Verbindung.',
        'current' => 'Aktueller Tarif',
        'switch_to_yearly' => 'Zu jährlich wechseln',
        'switch_to_monthly' => 'Zu monatlich wechseln',
        'select' => ':plan wählen',
        'upgrade' => 'Auf :plan upgraden',
        'downgrade' => 'Auf :plan downgraden',
        'start_first_month' => 'Für :price starten',
        'per_first_month' => '/erster Monat',
        'then_monthly' => 'Danach :price/Monat',
        'billed_yearly_total' => 'Jährlich abgerechnet · :price (2 Monate gratis)',
        'socials_tagline' => 'Ideal für Creator und kleine Marken.',
        'workspaces_tagline' => 'Ideal für Agenturen und größere Unternehmen.',
        'everything_included' => 'Alles inklusive',
        'features' => [
            'networks_all' => 'Alle sozialen Netzwerke inklusive',
            'networks_all_tooltip' => 'Du kannst auf allen diesen Netzwerken posten.',
            'accounts_unlimited' => 'Unbegrenzte Social-Accounts',
            'accounts_unlimited_tooltip' => 'Verbinde so viele Konten, wie du willst, auch mehrere vom selben Netzwerk. Zum Beispiel drei Instagram-Konten.',
            'calendar' => 'Kalender: Monats-, Wochen- und Tagesansicht',
            'calendar_tooltip' => 'Sieh deinen ganzen Monat auf einen Blick: was geplant, terminiert und schon veröffentlicht ist. Wechsle zu Woche oder Tag, wenn du Details brauchst.',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => 'Dein KI-Assistent zum Schreiben und Überarbeiten von Posts.',
            'mcp' => 'MCP: posten mit Claude, ChatGPT oder Grok',
            'mcp_tooltip' => 'Verbinde Claude, ChatGPT oder Grok mit deinem Workspace. Lass es Posts erstellen und planen, Kennzahlen abrufen, die besten Posts erkennen und aus deinen Daten den nächsten Content planen.',
            'repurpose' => 'Repurpose: aus einem Post viele machen',
            'repurpose_tooltip' => 'Wähle ein Quellkonto. Jeder neue Post, den du dort veröffentlichst, wird automatisch auf deinen anderen Netzwerken veröffentlicht. Du musst TryPost nicht öffnen.',
            'analytics' => 'Analytics',
            'analytics_tooltip' => 'Hol dir Kennzahlen wie Impressionen, Reichweite, Likes und Kommentare für jeden Post und jedes Konto, alles an einem Ort.',
            'team' => 'Unbegrenzte Mitglieder',
            'team_tooltip' => 'Lade so viele Personen in dein Team ein, wie du willst, ohne Aufpreis. Lege fest, was jede Person darf, und gib Posts vor der Veröffentlichung frei.',
        ],
    ],

    'plan' => [
        'trial' => 'Testphase',
        'cancelling' => 'Wird gekündigt',
        'trial_ends' => 'Testphase endet',
    ],

    'subscription' => [
        'title' => 'Zahlungsmethode',
        'description' => 'Aktualisiere deine Karte oder Rechnungsdaten bei Stripe.',
        'no_payment_method' => 'Noch keine Zahlungsmethode hinterlegt.',
        'expires_on' => 'Läuft ab :month/:year',
        'manage_stripe' => 'Bei Stripe verwalten',
    ],

    'invoices' => [
        'title' => 'Rechnungen',
        'description' => 'Lade deine bisherigen Rechnungen herunter.',
        'paid' => 'Bezahlt',
    ],

    'flash' => [
        'plan_changed' => 'Du nutzt jetzt den Tarif :plan.',
        'cannot_manage' => 'Nur der Kontoinhaber kann die Abrechnung verwalten.',
        'too_many_workspaces' => 'Du hast :count Workspaces. Dieser Tarif umfasst :limit — lösche die überzähligen, bevor du wechselst.',
        'subscription_required' => 'Für die Nutzung der KI-Funktionen ist ein aktives Abonnement erforderlich.',
    ],

    'processing' => [
        'page_title' => 'Wird verarbeitet...',
        'title' => 'Dein Abonnement wird verarbeitet',
        'description' => 'Bitte warte, während wir dein Konto einrichten. Das dauert nur einen Moment.',
    ],
];

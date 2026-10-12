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
        'start_first_month' => 'Für :price aktivieren',
        'billed_yearly_total' => 'Jährlich abgerechnet · :price (2 Monate gratis)',
        'socials_tagline' => 'Ideal für Creator und kleine Marken.',
        'workspaces_tagline' => 'Ideal für Agenturen und größere Unternehmen.',
        'everything_included' => 'Alles inklusive',
        'features' => [
            'networks_all' => 'Alle sozialen Netzwerke inklusive',
            'networks_all_tooltip' => 'Poste überall von einem Ort aus.',
            'accounts_unlimited' => 'Unbegrenzt viele Konten',
            'accounts_unlimited_tooltip' => 'Verbinde so viele Konten, wie du willst, auch mehrere vom selben Netzwerk. Zum Beispiel drei Instagram-Konten.',
            'calendar' => 'Content-Kalender',
            'ideas' => 'Content-Planung',
            'ideas_tooltip' => 'Halte deine Ideen fest, behalte sie an einem Ort im Blick und mach aus den besten Content, wann du willst.',
            'calendar_tooltip' => 'Sieh alle geplanten und veröffentlichten Posts an einem Ort, nach Monat oder Woche.',
            'ai' => 'KI-Schreibassistent',
            'ai_tooltip' => 'Schreibe und überarbeite deine Posts mit KI und passe den Ton an – in deiner Sprache.',
            'mcp' => 'TryPost in Claude oder ChatGPT nutzen',
            'mcp_tooltip' => 'Verbinde Claude, ChatGPT oder eine andere KI. Lass sie Posts erstellen und planen, deine Warteschlange füllen, Ideen ordnen und dein Wachstum zeigen.',
            'repurpose' => 'Automatisches Video-Reposting',
            'repurpose_tooltip' => 'Ein Video auf Instagram gepostet? TryPost veröffentlicht es automatisch erneut auf TikTok, YouTube, Facebook und deinen anderen Netzwerken.',
            'analytics' => 'Erweiterte Analysen',
            'analytics_tooltip' => 'Sieh alle Netzwerke zusammen, etwa deine Follower insgesamt, oder filtere nach Netzwerk und Post. Aufrufe, Reichweite, Likes, Kommentare und mehr – damit du weißt, was funktioniert.',
            'team' => 'Unbegrenzt viele Teammitglieder',
            'team_tooltip' => 'Lade so viele Personen in dein Team ein, wie du willst, ohne Aufpreis. Lege fest, was jede Person darf, und gib Posts vor der Veröffentlichung frei.',
        ],
    ],

    'current_plan' => [
        'change' => 'Tarif ändern',
        'price_monthly' => ':price/Monat',
        'price_yearly' => ':price/Jahr',
        'trial_until' => 'Testphase bis :date',
        'renews' => 'Verlängert sich automatisch',
        'cancelled' => 'Gekündigt, endet am :date',
        'workspaces_usage' => 'Workspaces: :count von :limit',
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
        'download' => 'Rechnung herunterladen',
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

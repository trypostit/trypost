<?php

return [
    'title' => 'Facturatie',

    'past_due_notice' => [
        'title' => 'Betaling achterstallig',
        'description' => 'Werk je betaalmethode bij om je abonnement actief te houden.',
        'cta' => 'Betaling bijwerken',
    ],

    'annual_banner' => [
        'title' => 'Krijg 2 maanden gratis',
        'description' => 'Stap over op jaarlijkse facturatie en betaal elke maand minder — hetzelfde abonnement, verder verandert er niets.',
        'cta' => 'Upgraden naar jaarlijks',
    ],

    'subscribe' => [
        'billed_monthly' => 'Maandelijks gefactureerd',
        'billed_yearly' => 'Jaarlijks gefactureerd',
        'prices' => [
            'first_month' => '$1',
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => 'Plannen',
        'description' => 'Upgrade of downgrade wanneer je wilt.',
        'monthly' => 'Maandelijks',
        'yearly' => 'Jaarlijks',
        'save_two_months' => '2 maanden gratis',
        'per_month' => '/maand',
        'workspaces_one' => '1 workspace',
        'workspaces_unlimited' => 'Onbeperkte workspaces',
        'current' => 'Huidig plan',
        'select' => 'Kies :plan',
        'start_first_month' => 'Start mijn eerste maand voor :price',
        'first_month_then' => 'Eerste maand :first, daarna :price/maand',

        'billed_yearly_total' => 'Jaarlijks gefactureerd · :price (2 maanden gratis)',
        'socials_tagline' => 'Eén workspace. Overal posten.',
        'workspaces_tagline' => 'Een workspace voor elk merk of elke klant.',
        'features' => [
            'accounts_unlimited' => 'Onbeperkte social accounts',
            'calendar' => 'Visuele kalender met automatisch publiceren',
            'ai' => 'AI: captions, afbeeldingen en merkstem',
            'mcp' => 'MCP: maak en plan via Claude, ChatGPT of Grok',
            'repurpose' => 'Repurpose: maak van één post er veel',
            'analytics' => 'Analytics per post en per account',
            'team' => 'Onbeperkt team, rollen en goedkeuringen',
        ],
    ],

    'plan' => [
        'title' => 'Abonnement',
        'description' => 'Beheer je abonnement.',
        'label' => 'Abonnement',
        'price' => 'Prijs',
        'month' => 'maand',
        'trial' => 'Proefperiode',
        'active' => 'Actief',
        'past_due' => 'Achterstallig',
        'cancelling' => 'Wordt opgezegd',
        'trial_ends' => 'Proefperiode eindigt',
    ],

    'subscription' => [
        'title' => 'Abonnement',
        'description' => 'Beheer je betaalmethode, factuurgegevens en abonnement.',
        'payment_method' => 'Betaalmethode',
        'no_payment_method' => 'Nog geen betaalmethode geregistreerd.',
        'expires_on' => 'Verloopt :month/:year',
        'manage_label' => 'Abonnement',
        'manage_stripe' => 'Beheren op Stripe',
    ],

    'invoices' => [
        'title' => 'Facturen',
        'description' => 'Download je eerdere facturen.',
        'empty' => 'Geen facturen gevonden',
        'paid' => 'Betaald',
    ],

    'flash' => [
        'plan_changed' => 'Je zit nu op het :plan-abonnement.',
        'switched_to_yearly' => 'Je zit nu op jaarlijkse facturatie.',
        'cannot_manage' => 'Alleen de accounteigenaar kan de facturatie beheren.',
        'too_many_workspaces' => 'Je hebt :count workspaces. Dit plan bevat er :limit — verwijder de extra\'s voordat je wisselt.',
        'subscription_required' => 'Een actief abonnement is vereist om AI-functies te gebruiken.',
    ],

    'processing' => [
        'page_title' => 'Verwerken...',
        'title' => 'Je abonnement wordt verwerkt',
        'description' => 'Wacht even terwijl we je account instellen. Dit duurt maar een moment.',
        'success_title' => 'Je bent helemaal klaar!',
        'success_description' => 'Je abonnement is actief. Je wordt doorgestuurd naar je workspaces...',
        'cancelled_title' => 'Afrekenen geannuleerd',
        'cancelled_description' => 'Je afrekenen is geannuleerd. Er zijn geen kosten in rekening gebracht.',
        'retry' => 'Opnieuw proberen',
    ],
];

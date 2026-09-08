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
        'workspaces_one' => 'Eén workspace',
        'workspaces_unlimited' => 'Onbeperkte workspaces',
        'workspaces_tooltip' => 'Een workspace is één merk of klant, gescheiden van de rest: met eigen social-media-accounts, handtekeningen, labels, analytics, ledenrechten en MCP-verbinding.',
        'current' => 'Huidig plan',
        'select' => 'Kies :plan',
        'start_first_month' => 'Start voor :price',
        'per_first_month' => '/eerste maand',
        'then_monthly' => 'Daarna :price/maand',
        'billed_yearly_total' => 'Jaarlijks gefactureerd · :price (2 maanden gratis)',
        'socials_tagline' => 'Ideaal voor creators en kleine merken.',
        'workspaces_tagline' => 'Ideaal voor bureaus en grotere bedrijven.',
        'everything_included' => 'Alles inbegrepen',
        'features' => [
            'networks_all' => 'Alle sociale netwerken inbegrepen',
            'networks_all_tooltip' => 'Je kunt op al deze netwerken posten.',
            'accounts_unlimited' => 'Onbeperkte social accounts',
            'accounts_unlimited_tooltip' => 'Koppel zoveel accounts als je wilt, ook meerdere van hetzelfde netwerk. Drie Instagrams, bijvoorbeeld.',
            'calendar' => 'Kalender: maand-, week- en dagweergave',
            'calendar_tooltip' => 'Zie je hele maand in één oogopslag: wat gepland, ingepland en al gepubliceerd is. Schakel naar week of dag als je detail nodig hebt.',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => 'Je AI-assistent voor het schrijven en nakijken van posts.',
            'mcp' => 'MCP: post via Claude, ChatGPT of Grok',
            'mcp_tooltip' => 'Koppel Claude, ChatGPT of Grok aan je workspace. Laat het posts maken en inplannen, statistieken ophalen, zien wat het beste werkte en op basis van je eigen data plannen wat hierna komt.',
            'repurpose' => 'Repurpose: maak van één post er veel',
            'repurpose_tooltip' => 'Kies een bronaccount. Elke nieuwe post die je daar plaatst, wordt automatisch op je andere netwerken geplaatst. Je hoeft TryPost niet te openen.',
            'analytics' => 'Analytics',
            'analytics_tooltip' => 'Krijg statistieken zoals impressies, bereik, likes en reacties voor elke post en elk account, alles op één plek.',
            'team' => 'Onbeperkt aantal leden',
            'team_tooltip' => 'Nodig zoveel mensen uit voor je team als je wilt, zonder extra kosten. Bepaal wat iedereen mag doen en keur posts goed voordat ze live gaan.',
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

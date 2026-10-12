<?php

return [
    'title' => 'Facturatie',

    'past_due_notice' => [
        'title' => 'Betaling achterstallig',
        'description' => 'Werk je betaalmethode bij om je abonnement actief te houden.',
        'cta' => 'Betaling bijwerken',
    ],

    'subscribe' => [
        'billed_monthly' => 'Maandelijks gefactureerd',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'description' => 'Upgrade of downgrade wanneer je wilt.',
        'monthly' => 'Maandelijks',
        'yearly' => 'Jaarlijks',
        'save_two_months' => '2 maanden gratis',
        'per_month' => '/maand',
        'workspaces_one' => 'Eén workspace',
        'workspaces_unlimited' => 'Onbeperkte workspaces',
        'workspaces_tooltip' => 'Een workspace is één merk of klant, gescheiden van de rest: met eigen social-media-accounts, handtekeningen, labels, analytics, ledenrechten en MCP-verbinding.',
        'current' => 'Huidig plan',
        'switch_to_yearly' => 'Overschakelen naar jaarlijks',
        'switch_to_monthly' => 'Overschakelen naar maandelijks',
        'select' => 'Kies :plan',
        'upgrade' => 'Upgrade naar :plan',
        'downgrade' => 'Downgrade naar :plan',
        'start_first_month' => 'Activeer voor :price',
        'billed_yearly_total' => 'Jaarlijks gefactureerd · :price (2 maanden gratis)',
        'socials_tagline' => 'Ideaal voor creators en kleine merken.',
        'workspaces_tagline' => 'Ideaal voor bureaus en grotere bedrijven.',
        'everything_included' => 'Alles inbegrepen',
        'features' => [
            'networks_all' => 'Alle 13 sociale netwerken',
            'networks_all_tooltip' => 'Post op allemaal vanaf één plek.',
            'accounts_unlimited' => 'Onbeperkt sociale accounts',
            'accounts_unlimited_tooltip' => 'Koppel zoveel accounts als je wilt, ook meerdere van hetzelfde netwerk. Drie Instagrams, bijvoorbeeld.',
            'calendar' => 'Contentkalender',
            'calendar_tooltip' => 'Zie je hele maand in één oogopslag: wat gepland, ingepland en al gepubliceerd is. Schakel naar week als je detail nodig hebt.',
            'ai' => 'AI-schrijfassistent',
            'ai_tooltip' => 'Je AI-assistent voor het schrijven en nakijken van posts.',
            'mcp' => 'Gebruik TryPost vanuit Claude of ChatGPT',
            'mcp_tooltip' => 'Koppel Claude, ChatGPT of een andere AI. Laat hem posts maken en plannen, je wachtrij vullen, ideeën ordenen en je groei tonen.',
            'repurpose' => 'Je video\'s op al je netwerken',
            'repurpose_tooltip' => 'Kies een Instagram- of Facebook-account. Elke nieuwe Reel of video die je daar plaatst, gaat vanzelf naar je andere netwerken, zonder TryPost te openen.',
            'analytics' => 'Zie wat je laat groeien',
            'analytics_tooltip' => 'Zie of je groeit en welke posts het best werken, over al je accounts.',
            'team' => 'Onbeperkt teamleden',
            'team_tooltip' => 'Nodig zoveel mensen uit voor je team als je wilt, zonder extra kosten. Bepaal wat iedereen mag doen en keur posts goed voordat ze live gaan.',
        ],
    ],

    'current_plan' => [
        'change' => 'Plan wijzigen',
        'price_monthly' => ':price/maand',
        'price_yearly' => ':price/jaar',
        'trial_until' => 'Proefperiode tot :date',
        'renews' => 'Wordt automatisch verlengd',
        'cancelled' => 'Opgezegd, eindigt op :date',
        'workspaces_usage' => 'Workspaces: :count van :limit',
    ],

    'subscription' => [
        'title' => 'Betaalmethode',
        'description' => 'Werk je kaart of factuurgegevens bij in Stripe.',
        'no_payment_method' => 'Nog geen betaalmethode geregistreerd.',
        'expires_on' => 'Verloopt :month/:year',
        'manage_stripe' => 'Beheren op Stripe',
    ],

    'invoices' => [
        'title' => 'Facturen',
        'description' => 'Download je eerdere facturen.',
        'paid' => 'Betaald',
        'download' => 'Factuur downloaden',
    ],

    'flash' => [
        'plan_changed' => 'Je zit nu op het :plan-abonnement.',
        'cannot_manage' => 'Alleen de accounteigenaar kan de facturatie beheren.',
        'too_many_workspaces' => 'Je hebt :count workspaces. Dit plan bevat er :limit — verwijder de extra\'s voordat je wisselt.',
        'subscription_required' => 'Een actief abonnement is vereist om AI-functies te gebruiken.',
    ],

    'processing' => [
        'page_title' => 'Verwerken...',
        'title' => 'Je abonnement wordt verwerkt',
        'description' => 'Wacht even terwijl we je account instellen. Dit duurt maar een moment.',
    ],
];

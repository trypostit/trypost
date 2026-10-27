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
        'per_year' => '/jaar',
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
            'networks_all' => 'Alle sociale netwerken inbegrepen',
            'networks_all_tooltip' => 'Post op allemaal vanaf één plek.',
            'accounts_unlimited' => 'Onbeperkt sociale accounts',
            'accounts_unlimited_tooltip' => 'Koppel zoveel accounts als je wilt, ook meerdere van hetzelfde netwerk. Drie Instagrams, bijvoorbeeld.',
            'calendar' => 'Contentkalender',
            'ideas' => 'Contentplanning',
            'ideas_tooltip' => 'Noteer je ideeën, houd ze op één plek bij elkaar en maak van de beste content wanneer je wilt.',
            'calendar_tooltip' => 'Zie al je geplande en gepubliceerde posts op één plek, per maand of per week.',
            'ai' => 'AI-schrijfassistent',
            'ai_tooltip' => 'Schrijf en herschrijf je posts met AI en pas de toon aan, in jouw taal.',
            'mcp' => 'Gebruik TryPost vanuit Claude of ChatGPT',
            'mcp_tooltip' => 'Koppel Claude, ChatGPT of een andere AI. Laat hem posts maken en plannen, je wachtrij vullen, ideeën ordenen en je groei tonen.',
            'repurpose' => 'Automatisch video\'s herposten',
            'repurpose_tooltip' => 'Een video op Instagram geplaatst? TryPost plaatst hem automatisch opnieuw op TikTok, YouTube, Facebook en je andere netwerken.',
            'analytics' => 'Geavanceerde statistieken',
            'analytics_tooltip' => 'Zie al je netwerken samen, zoals je totale volgers, of filter per netwerk en per post. Weergaven, bereik, likes, reacties en meer, zodat je weet wat werkt.',
            'team' => 'Onbeperkt teamleden',
            'team_tooltip' => 'Nodig zoveel mensen uit voor je team als je wilt, zonder extra kosten. Bepaal wat iedereen mag doen en keur posts goed voordat ze live gaan.',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => 'Actief',
            'trial' => 'Gratis proefperiode',
            'cancelling' => 'Opgezegd',
            'past_due' => 'Achterstallig',
        ],
        'change' => 'Plan wijzigen',
        'price_monthly' => ':price/maand',
        'price_yearly' => ':price/jaar',
        'trial_until' => 'Proefperiode tot :date',
        'cancelled' => 'Opgezegd, eindigt op :date',
        'discount_until' => ':discount korting tot :date',
        'discount_forever' => ':discount korting op elke betaling',
        'discount_once' => ':discount korting op je volgende betaling',
    ],

    'subscription' => [
        'title' => 'Betaalmethoden',
        'default' => 'Standaard',
        'description' => 'Werk je kaart of factuurgegevens bij in Stripe.',
        'no_payment_method' => 'Nog geen betaalmethode geregistreerd.',
        'expires_on' => 'Verloopt :month/:year',
        'manage_stripe' => 'Beheren op Stripe',
    ],

    'tabs' => [
        'plan' => 'Plan',
        'invoices' => 'Facturen',
    ],

    'invoices' => [
        'columns' => [
            'date' => 'Datum',
            'amount' => 'Bedrag',
            'status' => 'Status',
        ],
        'empty_title' => 'Nog geen facturen',
        'empty_description' => 'Je facturen verschijnen hier na je eerste betaling.',
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

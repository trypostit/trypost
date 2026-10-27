<?php

return [
    'title' => 'Facturation',

    'past_due_notice' => [
        'title' => 'Paiement en retard',
        'description' => 'Mettez à jour votre moyen de paiement pour conserver votre abonnement actif.',
        'cta' => 'Mettre à jour le paiement',
    ],

    'subscribe' => [
        'billed_monthly' => 'Facturé mensuellement',
        'prices' => [
            'first_month' => '1 $',
            'socials' => ['monthly' => '19 $', 'yearly_per_month' => '15,83 $', 'yearly' => '190 $'],
            'workspaces' => ['monthly' => '99 $', 'yearly_per_month' => '82,50 $', 'yearly' => '990 $'],
        ],
    ],

    'plans' => [
        'description' => 'Passez à une offre supérieure ou inférieure à tout moment.',
        'monthly' => 'Mensuel',
        'yearly' => 'Annuel',
        'save_two_months' => '2 mois offerts',
        'per_month' => '/mois',
        'per_year' => '/an',
        'workspaces_one' => 'Un espace de travail',
        'workspaces_unlimited' => 'Espaces de travail illimités',
        'workspaces_tooltip' => 'Un espace de travail correspond à une marque ou un client, séparé des autres : ses propres réseaux sociaux, signatures, libellés, statistiques, permissions des membres et connexion MCP.',
        'current' => 'Offre actuelle',
        'switch_to_yearly' => 'Passer à l’annuel',
        'switch_to_monthly' => 'Passer au mensuel',
        'select' => 'Choisir :plan',
        'upgrade' => 'Passer à :plan',
        'downgrade' => 'Revenir à :plan',
        'start_first_month' => 'Activer pour :price',
        'billed_yearly_total' => 'Facturé annuellement · :price (2 mois offerts)',
        'socials_tagline' => 'Idéal pour les créateurs et petites marques.',
        'workspaces_tagline' => 'Idéal pour les agences et grandes entreprises.',
        'everything_included' => 'Tout est inclus',
        'features' => [
            'networks_all' => 'Tous les réseaux sociaux inclus',
            'networks_all_tooltip' => 'Publiez sur tous depuis un seul endroit.',
            'accounts_unlimited' => 'Comptes sociaux illimités',
            'accounts_unlimited_tooltip' => 'Connectez autant de comptes que vous voulez, même plusieurs du même réseau. Trois Instagram, par exemple.',
            'calendar' => 'Calendrier de contenu',
            'ideas' => 'Planification de contenu',
            'ideas_tooltip' => 'Notez vos idées, organisez-les au même endroit et transformez les meilleures en contenu quand vous voulez.',
            'calendar_tooltip' => 'Voyez tous vos posts planifiés et publiés au même endroit, par mois ou par semaine.',
            'ai' => 'Assistant IA de rédaction',
            'ai_tooltip' => 'Rédigez, réécrivez et ajustez le ton de vos posts avec l’IA, dans votre langue.',
            'mcp' => 'Utilisez TryPost depuis Claude ou ChatGPT',
            'mcp_tooltip' => 'Connectez Claude, ChatGPT ou une autre IA. Demandez-lui de créer et planifier des posts, remplir votre file, organiser vos idées et suivre votre croissance.',
            'repurpose' => 'Republication automatique de vidéos',
            'repurpose_tooltip' => 'Vous avez publié une vidéo sur Instagram ? TryPost la republie automatiquement sur TikTok, YouTube, Facebook et vos autres réseaux.',
            'analytics' => 'Statistiques avancées',
            'analytics_tooltip' => 'Voyez tous vos réseaux ensemble, comme vos abonnés au total, ou filtrez par réseau et par post. Vues, portée, j’aime, commentaires et plus, pour savoir ce qui marche.',
            'team' => 'Membres d’équipe illimités',
            'team_tooltip' => 'Invitez autant de personnes que vous voulez dans votre équipe, sans frais supplémentaires. Définissez ce que chacun peut faire et validez les posts avant publication.',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => 'Active',
            'trial' => 'Essai gratuit',
            'cancelling' => 'Annulée',
            'past_due' => 'En retard',
        ],
        'change' => 'Changer d\'offre',
        'price_monthly' => ':price/mois',
        'price_yearly' => ':price/an',
        'trial_until' => 'Essai jusqu\'au :date',
        'cancelled' => 'Annulé, se termine le :date',
        'discount_until' => ':discount de réduction jusqu\'au :date',
        'discount_forever' => ':discount de réduction sur chaque paiement',
        'discount_once' => ':discount de réduction sur votre prochain paiement',
    ],

    'subscription' => [
        'title' => 'Moyens de paiement',
        'default' => 'Par défaut',
        'description' => 'Mettez à jour votre carte ou vos informations de facturation sur Stripe.',
        'no_payment_method' => 'Aucun moyen de paiement enregistré pour le moment.',
        'expires_on' => 'Expire le :month/:year',
        'manage_stripe' => 'Gérer sur Stripe',
    ],

    'tabs' => [
        'plan' => 'Offre',
        'invoices' => 'Factures',
    ],

    'invoices' => [
        'columns' => [
            'date' => 'Date',
            'amount' => 'Montant',
            'status' => 'Statut',
        ],
        'empty_title' => 'Aucune facture pour l\'instant',
        'empty_description' => 'Vos factures apparaîtront ici après votre premier paiement.',
        'paid' => 'Payée',
        'download' => 'Télécharger la facture',
    ],

    'flash' => [
        'plan_changed' => 'Vous êtes maintenant sur le forfait :plan.',
        'cannot_manage' => 'Seul le propriétaire du compte peut gérer la facturation.',
        'too_many_workspaces' => 'Vous avez :count espaces de travail. Cette offre en inclut :limit — supprimez les extras avant de changer.',
        'subscription_required' => 'Un abonnement actif est requis pour utiliser les fonctionnalités d\'IA.',
    ],

    'processing' => [
        'page_title' => 'Traitement...',
        'title' => 'Traitement de votre abonnement',
        'description' => 'Veuillez patienter pendant que nous configurons votre compte. Cela ne prendra qu\'un instant.',
    ],
];

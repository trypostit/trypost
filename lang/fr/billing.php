<?php

return [
    'title' => 'Facturation',

    'past_due_notice' => [
        'title' => 'Paiement en retard',
        'description' => 'Mettez à jour votre moyen de paiement pour conserver votre abonnement actif.',
        'cta' => 'Mettre à jour le paiement',
    ],

    'annual_banner' => [
        'title' => 'Obtenez 2 mois gratuits',
        'description' => 'Passez à la facturation annuelle et payez moins chaque mois — même forfait, rien d\'autre ne change.',
        'cta' => 'Passer à l\'annuel',
    ],

    'subscribe' => [
        'billed_monthly' => 'Facturé mensuellement',
        'billed_yearly' => 'Facturé annuellement',
        'prices' => [
            'first_month' => '1 $',
            'socials' => ['monthly' => '19 $', 'yearly_per_month' => '15,83 $', 'yearly' => '190 $'],
            'workspaces' => ['monthly' => '99 $', 'yearly_per_month' => '82,50 $', 'yearly' => '990 $'],
        ],
    ],

    'plans' => [
        'title' => 'Offres',
        'description' => 'Passez à une offre supérieure ou inférieure à tout moment.',
        'monthly' => 'Mensuel',
        'yearly' => 'Annuel',
        'save_two_months' => '2 mois offerts',
        'per_month' => '/mois',
        'workspaces_one' => 'Un espace de travail',
        'workspaces_unlimited' => 'Espaces de travail illimités',
        'workspaces_tooltip' => 'Un espace de travail correspond à une marque ou un client, séparé des autres : ses propres réseaux sociaux, signatures, libellés, statistiques, permissions des membres et connexion MCP.',
        'current' => 'Offre actuelle',
        'switch_to_yearly' => 'Passer à l’annuel',
        'switch_to_monthly' => 'Passer au mensuel',
        'select' => 'Choisir :plan',
        'start_first_month' => 'Commencer pour :price',
        'per_first_month' => '/premier mois',
        'then_monthly' => 'Puis :price/mois',
        'billed_yearly_total' => 'Facturé annuellement · :price (2 mois offerts)',
        'socials_tagline' => 'Idéal pour les créateurs et petites marques.',
        'workspaces_tagline' => 'Idéal pour les agences et grandes entreprises.',
        'everything_included' => 'Tout est inclus',
        'features' => [
            'networks_all' => 'Tous les réseaux sociaux inclus',
            'networks_all_tooltip' => 'Vous pouvez publier sur tous ces réseaux.',
            'accounts_unlimited' => 'Comptes sociaux illimités',
            'accounts_unlimited_tooltip' => 'Connectez autant de comptes que vous voulez, même plusieurs du même réseau. Trois Instagram, par exemple.',
            'calendar' => 'Calendrier mensuel, hebdomadaire et quotidien',
            'calendar_tooltip' => 'Voyez tout votre mois d\'un coup d\'œil : ce qui est prévu, planifié et déjà publié. Passez à la semaine ou au jour quand il vous faut le détail.',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => 'Votre assistant IA pour rédiger et relire vos posts.',
            'mcp' => 'MCP : publiez depuis Claude, ChatGPT ou Grok',
            'mcp_tooltip' => 'Connectez Claude, ChatGPT ou Grok à votre workspace. Demandez-lui de créer et planifier des posts, de sortir les métriques, de repérer ce qui a le mieux marché et de préparer la suite à partir de vos données.',
            'repurpose' => 'Repurpose : transformez un post en plusieurs',
            'repurpose_tooltip' => 'Choisissez un compte source. Chaque nouveau post que vous y publiez est automatiquement republié sur vos autres réseaux. Pas besoin d\'ouvrir TryPost.',
            'analytics' => 'Analytics',
            'analytics_tooltip' => 'Obtenez des métriques comme les impressions, la portée, les likes et les commentaires pour chaque post et chaque compte, tout au même endroit.',
            'team' => 'Membres illimités',
            'team_tooltip' => 'Invitez autant de personnes que vous voulez dans votre équipe, sans frais supplémentaires. Définissez ce que chacun peut faire et validez les posts avant publication.',
        ],
    ],

    'plan' => [
        'title' => 'Forfait',
        'description' => 'Gérez votre forfait d\'abonnement.',
        'label' => 'Forfait',
        'price' => 'Prix',
        'month' => 'mois',
        'trial' => 'Essai',
        'active' => 'Actif',
        'past_due' => 'En retard',
        'cancelling' => 'Annulation en cours',
        'trial_ends' => 'Fin de l\'essai',
    ],

    'subscription' => [
        'title' => 'Moyen de paiement',
        'description' => 'Mettez à jour votre carte ou vos informations de facturation sur Stripe.',
        'payment_method' => 'Moyen de paiement',
        'no_payment_method' => 'Aucun moyen de paiement enregistré pour le moment.',
        'expires_on' => 'Expire le :month/:year',
        'manage_label' => 'Abonnement',
        'manage_stripe' => 'Gérer sur Stripe',
    ],

    'invoices' => [
        'title' => 'Factures',
        'description' => 'Téléchargez vos factures passées.',
        'empty' => 'Aucune facture trouvée',
        'paid' => 'Payée',
    ],

    'flash' => [
        'plan_changed' => 'Vous êtes maintenant sur le forfait :plan.',
        'switched_to_yearly' => 'Vous êtes maintenant en facturation annuelle.',
        'cannot_manage' => 'Seul le propriétaire du compte peut gérer la facturation.',
        'too_many_workspaces' => 'Vous avez :count espaces de travail. Cette offre en inclut :limit — supprimez les extras avant de changer.',
        'subscription_required' => 'Un abonnement actif est requis pour utiliser les fonctionnalités d\'IA.',
    ],

    'processing' => [
        'page_title' => 'Traitement...',
        'title' => 'Traitement de votre abonnement',
        'description' => 'Veuillez patienter pendant que nous configurons votre compte. Cela ne prendra qu\'un instant.',
        'success_title' => 'Tout est prêt !',
        'success_description' => 'Votre abonnement est actif. Redirection vers vos espaces de travail...',
        'cancelled_title' => 'Paiement annulé',
        'cancelled_description' => 'Votre paiement a été annulé. Aucun montant n\'a été débité.',
        'retry' => 'Réessayer',
    ],
];

<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'Republiez automatiquement sur vos autres réseaux les vidéos que vous postez en dehors de TryPost.',
    'new' => 'Nouveau repurpose',

    'flow' => [
        'no_source' => 'Aucun compte source',
        'no_destinations' => 'Aucune destination',
    ],

    'publish_mode' => [

        'title' => 'Publication',

        'description' => 'Ce qui se passe quand une nouvelle vidéo apparaît.',

    ],

    'publish_modes' => [

        'publish' => 'Publier automatiquement',

        'publish_hint' => 'Chaque nouvelle vidéo est programmée dès qu\'elle est trouvée.',

        'draft' => 'Créer en brouillon',

        'draft_hint' => 'Chaque nouvelle vidéo devient un brouillon à relire et publier ici.',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Vidéos',
        'story' => 'Stories',
    ],

    'source' => [
        'title' => 'Source',
        'description' => 'TryPost surveille ce compte pour les nouvelles vidéos du format ci-dessous.',
        'account_label' => 'Compte',
        'watch_label' => 'Surveiller',
    ],

    'summary' => [
        'sentence' => 'Chaque nouveau :format publié sur :source est republié sur :destinations.',
        'no_destinations' => 'Chaque nouveau :format publié sur :source attend une destination.',
        'no_source' => 'Cette automatisation n\'a plus de compte source. Choisissez-en un pour la relancer.',
    ],

    'empty' => [
        'title' => 'Aucun repurpose configuré',
        'description' => 'Choisissez un point de départ ci-dessous. TryPost surveille le compte choisi et republie chaque nouvelle vidéo sur les réseaux que vous sélectionnez.',
    ],

    'table' => [
        'flow' => 'Flux',
        'source' => 'Source',
        'destinations' => 'Destinations',
        'status' => 'Statut',
        'published' => 'Répliquées',
        'last_polled' => 'Dernière vérification',
    ],

    'status' => [
        'draft' => 'Brouillon',
        'active' => 'Actif',
        'paused' => 'En pause',
        'disabled' => 'Désactivé',
    ],

    'templates' => [
        'use' => 'Utiliser ce modèle',
        'instagram_everywhere' => [
            'title' => 'Instagram partout',
            'description' => 'Publiez un Reel sur Instagram et TryPost le republie sur TikTok, YouTube Shorts et Facebook.',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook partout',
            'description' => 'Publiez une vidéo sur votre Page Facebook et TryPost la republie sur Instagram, TikTok et YouTube Shorts.',
        ],
    ],

    'create' => [
        'title' => 'Nouveau repurpose',
        'description' => 'Choisissez le compte que TryPost doit surveiller. Les destinations se choisissent à l\'écran suivant.',
        'source_label' => 'Compte source',
        'source_placeholder' => 'Choisissez un compte',
        'source_search' => 'Rechercher des comptes',
        'source_empty' => 'Aucun compte trouvé.',
        'source_placeholder' => 'Sélectionner un compte',
        'no_accounts' => 'Connectez d\'abord un compte Instagram ou Facebook. Seuls ces réseaux peuvent être source, car ce sont les seuls qui permettent de télécharger la vidéo.',
        'submit' => 'Créer',
        'connect' => 'Connecter un compte',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'Les vidéos publiées sur ce compte en dehors de TryPost sont répliquées vers les destinations ci-dessous.',
    ],

    'tabs' => [
        'configuration' => 'Configuration',
        'activity' => 'Activité',
        'settings' => 'Réglages',
    ],

    'destinations' => [
        'title' => 'Destinations',
        'description' => 'Choisissez les comptes qui le reçoivent. Chacun publie dans le format que vous choisissez.',
        'hint' => 'La légende n\'est adaptée par réseau que lorsqu\'elle dépasse la limite de ce réseau.',
        'none_available' => 'Aucun autre compte n\'est connecté dans cet espace de travail.',
        'save' => 'Enregistrer',
        'saved' => 'Destinations enregistrées',
        'publish_as' => 'Publier comme',
    ],

    'status_card' => [
        'title' => 'Statut',
        'activate' => 'Activer',
        'pause' => 'Mettre en pause',
        'resume' => 'Reprendre',
        'disable' => 'Désactiver',
        'watermark' => 'Surveillé depuis',
        'last_polled' => 'Dernière vérification',
        'draft_hint' => 'Choisissez au moins une destination, puis activez. Seules les vidéos publiées après l\'activation sont répliquées.',
        'active_hint' => 'TryPost vérifie ce compte régulièrement et réplique chaque nouvelle vidéo.',
        'paused_hint' => 'Les vérifications sont suspendues. La reprise repart là où elle s\'est arrêtée, rien n\'est perdu.',
        'disabled_hint' => 'Désactivé. Une nouvelle activation repart de zéro : ce que vous avez publié entre-temps reste de côté.',
    ],

    'items' => [
        'source' => 'Original',
        'published_at' => 'Publié',
        'status' => 'Statut',
        'detail' => 'Détail',
        'posts' => 'Répliqué sur',
        'view_original' => 'Voir l\'original',
        'original_from' => 'original du :date',
        'empty' => [
            'title' => 'Rien pour l\'instant',
            'description' => 'Les vidéos publiées par ce compte hors de TryPost apparaîtront ici.',
        ],
        'open_post' => 'Ouvrir la publication',
        'statuses' => [
            'pending' => 'En file d\'attente',
            'processing' => 'Traitement',
            'published' => 'Répliqué',
            'drafted' => 'Brouillon',
            'skipped' => 'Ignoré',
            'failed' => 'Échec',
        ],
        'reasons' => [
            'published_via_trypost' => 'Déjà publié via TryPost',
            'media_url_missing' => 'Le réseau n\'a pas fourni de fichier téléchargeable, généralement à cause d\'un audio protégé par le droit d\'auteur',
            'download_failed' => 'La vidéo n\'a pas pu être téléchargée',
            'post_creation_failed' => 'Impossible de créer les publications',
            'no_usable_destinations' => 'Aucune destination n\'était disponible pour publier',
        ],
    ],

    'menu' => [

        'label' => 'Plus d\'actions',

    ],

    'danger' => [
        'title' => 'Supprimer ce repurpose',
        'description' => 'Les vérifications s\'arrêtent immédiatement. Les publications déjà créées restent dans votre calendrier.',
        'delete' => 'Supprimer le repurpose',
    ],

    'health' => [
        'stopped_itself' => 'Arrêtée d\'elle-même — ouvrez-la pour voir pourquoi',
        'source_missing' => 'La réplication est en pause : cette automatisation n\'a pas de compte source. Choisissez-en un, puis reprenez.',
        'source_unusable' => 'La réplication est en pause : le compte surveillé doit être reconnecté.',
        'no_destinations' => 'La réplication est en pause : aucune destination disponible. Ajoutez-en une, puis reprenez.',
        'ready' => 'Le problème est résolu. Reprenez cette automatisation pour recommencer à répliquer.',
    ],

    'errors' => [
        'source_already_used' => 'Ce compte alimente déjà un autre repurpose. Modifiez celui-là.',
        'source_missing' => 'Choisissez un compte à surveiller avant de démarrer cette automatisation.',
        'source_unusable' => 'Reconnectez le compte que cette automatisation surveille avant de la démarrer.',
        'destinations_required' => 'Choisissez au moins une destination avant d\'activer.',
        'destination_needs_video' => 'Ce format n\'accepte pas de vidéo.',
        'only_paused_resumes' => 'Seul un repurpose en pause peut être repris.',
        'only_active_pauses' => 'Seul un repurpose actif peut être mis en pause.',
        'only_running_disables' => 'Seul un repurpose en cours peut être désactivé.',
        'only_idle_activates' => 'Seul un brouillon ou un repurpose désactivé peut être activé.',
        'destination_unavailable' => 'Ce compte de destination n\'est plus disponible.',
        'destination_is_source' => 'Cette destination est le compte que ce repurpose surveille.',
        'source_unavailable' => 'Ce compte source n\'est plus disponible.',
        'action_failed' => 'Une erreur est survenue. Vérifiez le formulaire et réessayez.',
    ],
];

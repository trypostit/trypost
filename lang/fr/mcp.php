<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Connectez des assistants IA à votre espace.',
    'copy' => 'Copier',
    'connect' => 'Connecter :client',
    'name_label' => 'Nom',
    'url_label' => 'URL du serveur',
    'config_label' => 'Config',
    'tabs' => [
        'connected' => 'Apps connectées',
        'connect' => 'Connecter une app',
    ],
    'connect_other' => 'Connecter',
    'other_hint' => 'Toute app compatible MCP.',
    'other_dialog_title' => 'Connectez TryPost à une autre app',
    'connected_description' => 'Assistants auxquels vous vous êtes connecté. Déconnectez ceux dont vous n’avez plus besoin.',
    'connected_empty_title' => 'Aucune app connectée',
    'connected_empty' => 'Connectez Claude, ChatGPT ou une autre app pour la voir ici.',
    'disconnect' => 'Déconnecter',
    'disconnect_title' => 'Déconnecter l’app',
    'disconnect_confirm' => 'Cela déconnecte l’app de TryPost. Elle devra se reconnecter pour utiliser MCP à nouveau.',
    'disconnected' => 'App déconnectée.',
    'copied' => 'Copié',
    'last_used' => 'Dernière utilisation',
    'never' => 'Jamais',
    'connector_name' => 'TryPost',

    'authorize' => [
        'page_title' => 'Autoriser l\'application - TryPost',
        'app_title' => 'Autoriser MCP',
        'heading' => 'Autoriser :client',
        'intro' => 'Cette application pourra :',
        'intro_capability' => 'Utiliser les fonctions MCP disponibles.',
        'logged_in_as' => 'Connecté en tant que :',
        'workspace' => 'Espace de travail :',
        'workspace_scope' => 'Cette connexion n\'accédera qu\'à l\'espace de travail sélectionné.',
        'permissions' => 'Autorisations :',
        'cancel' => 'Annuler',
        'approve' => 'Autoriser',
        'approving' => 'Autorisation...',
        'select_workspace' => 'Choisir un espace de travail',
        'search_workspace' => 'Rechercher un espace...',
        'no_workspace_found' => 'Aucun espace de travail trouvé',
        'scope_mcp_use' => 'Utiliser le serveur MCP',
        'error_page_title' => 'Échec de l\'autorisation - TryPost',
        'error_title' => 'Connexion impossible',
        'error_body' => 'Cette demande d\'autorisation est invalide ou a expiré. Fermez cette fenêtre et réessayez depuis votre client MCP.',
        'error_code' => 'Erreur : :error',
    ],

    'other_clients_title' => 'Autres apps',

    'dialog' => [
        'title' => 'Connectez TryPost à :client',
        'description' => 'Cela prend une minute. Cette page se met à jour dès que vous êtes connecté.',
        'open' => 'Ouvrir :client',
        'steps' => [
            'claude' => [
                'paste' => 'Collez l’URL dans le champ URL du connecteur',
                'add' => 'Cliquez sur Ajouter, puis sur Connecter',
                'login' => 'Connectez-vous à TryPost et choisissez le workspace',
            ],
            'chatgpt' => [
                'open' => 'Ouvrez TryPost dans ChatGPT et cliquez sur Connecter',
                'login' => 'Connectez-vous à TryPost et choisissez le workspace',
            ],
            'other' => [
                'add' => 'Ajoutez un serveur MCP personnalisé dans votre app',
                'paste' => 'Collez le nom et l’URL, ou la configuration',
                'login' => 'Connectez-vous à TryPost et choisissez le workspace',
            ],
        ],
        'waiting' => 'En attente de la connexion de votre IA…',
    ],

];

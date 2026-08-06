<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Connectez des assistants IA à votre workspace TryPost. Ils utilisent les mêmes permissions que chaque utilisateur connecté.',
    'copy_step' => 'Copiez l’URL du serveur TryPost',
    'open_step' => 'Ouvrez votre assistant IA',
    'copy' => 'Copier l’URL',
    'connect' => 'Connecter avec :client',
    'step_add' => 'Collez le nom, l’URL ou la config ci-dessous dans votre app. La connexion s’ouvre dans le navigateur la première fois.',
    'name_label' => 'Nom',
    'url_label' => 'URL du serveur',
    'config_label' => 'Config',
    'connected_title' => 'Apps connectées',
    'connected_description' => 'Assistants auxquels vous vous êtes connecté. Déconnectez ceux dont vous n’avez plus besoin.',
    'connected_empty' => 'Rien de connecté pour l’instant. Utilisez Claude, ChatGPT ou un autre client ci-dessus.',
    'disconnect' => 'Déconnecter',
    'disconnect_title' => 'Déconnecter l’app',
    'disconnect_confirm' => 'Cela déconnecte l’app de TryPost. Elle devra se reconnecter pour utiliser MCP à nouveau.',
    'disconnected' => 'App déconnectée.',
    'copied' => 'Copié',
    'last_used' => 'Dernière utilisation',
    'never' => 'Jamais',
    'documentation_title' => 'Documentation',
    'documentation_description' => 'Guides par client, tools disponibles et dépannage.',
    'view_docs' => 'Voir la documentation',
    'connector_name' => 'TryPost',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => 'Autres apps',
    'other_clients_description' => 'Cursor, VS Code, Claude Code et toute app qui parle MCP.',

    'clients' => [
        'claude' => 'Ouvrez Settings → Connectors, ajoutez un connecteur personnalisé, puis collez l’URL ci-dessus.',
        'chatgpt' => 'Ouvrez Settings → Apps & Connectors, créez un connecteur personnalisé, puis collez l’URL ci-dessus.',
        'cursor' => 'Ajoutez TryPost comme serveur MCP distant dans Cursor.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Collez la configuration ci-dessous dans les paramètres MCP de VS Code.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Collez la configuration ci-dessous dans les paramètres MCP de Claude Code.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Fonctionne avec tout client qui lit une config mcpServers.',
        'other_name' => 'Autres',
    ],
];

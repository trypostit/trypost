<?php

declare(strict_types=1);

return [
    'title' => 'Premiers pas',
    'welcome' => 'Bienvenue sur TryPost, :name',
    'welcome_anonymous' => 'Bienvenue sur TryPost',
    'description' => 'Suivez les étapes ci-dessous pour découvrir comment TryPost fonctionne et publier votre premier post.',
    'skip_step' => 'Ignorer cette étape',
    'continue' => 'Continuer vers TryPost',
    'status' => [
        'complete' => 'Terminé',
        'todo' => 'À faire',
        'skipped' => 'Ignorée',
    ],
    'mcp' => [
        'title' => 'Connectez votre assistant IA',
        'description' => 'Ajoutez TryPost comme serveur MCP pour que votre assistant puisse créer et gérer vos posts sociaux.',
        'copy_step' => 'Copiez l’URL du serveur TryPost',
        'open_step' => 'Ouvrez votre assistant IA',
        'copy' => 'Copier l’URL',
        'copied' => 'URL MCP copiée.',
        'connect' => 'Connecter avec :client',
        'clients' => [
            'claude' => 'Ouvrez Settings → Connectors, ajoutez un connecteur personnalisé, puis collez l’URL ci-dessus.',
            'chatgpt' => 'Ouvrez Settings → Apps & Connectors, créez un connecteur personnalisé, puis collez l’URL ci-dessus.',
        ],
    ],
    'social' => [
        'title' => 'Connectez un compte social',
        'description' => 'Choisissez au moins un réseau où TryPost pourra publier votre contenu.',
        'connected_elsewhere' => 'Vous avez déjà connecté un compte dans un autre workspace, cette étape est donc terminée.',
    ],
    'first_post' => [
        'title' => 'Créez votre premier post',
        'description' => 'Essayez ce prompt de démarrage avec votre assistant connecté, ou créez le post directement dans TryPost.',
        'prompt_label' => 'Prompt d’exemple',
        'sample_prompt' => 'Crée un post social amical présentant ma marque et adapte-le pour chaque réseau connecté.',
        'copy_prompt' => 'Copier le prompt',
        'copied' => 'Prompt d’exemple copié.',
        'create_button' => 'Créer votre premier post',
        'or' => 'ou',
    ],
    'ready' => [
        'title' => 'Vous êtes prêt à publier',
        'description' => 'Tout est bon. Continuez vers TryPost et commencez à planifier votre contenu.',
    ],
];

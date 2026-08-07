<?php

declare(strict_types=1);

return [
    'title' => 'Erste Schritte',
    'welcome' => 'Willkommen bei TryPost, :name',
    'welcome_anonymous' => 'Willkommen bei TryPost',
    'description' => 'Folge den Schritten unten, um zu sehen, wie TryPost funktioniert, und deinen ersten Beitrag zu veröffentlichen.',
    'skip_step' => 'Diesen Schritt überspringen',
    'continue' => 'Weiter zu TryPost',
    'status' => [
        'complete' => 'Erledigt',
        'todo' => 'Offen',
        'skipped' => 'Übersprungen',
    ],
    'mcp' => [
        'title' => 'Verbinde deinen KI-Assistenten',
        'description' => 'Füge TryPost als MCP-Server hinzu, damit dein Assistent Social-Beiträge für dich erstellen und verwalten kann.',
        'copy_step' => 'Kopiere deine TryPost-Server-URL',
        'open_step' => 'Öffne deinen KI-Assistenten',
        'copy' => 'URL kopieren',
        'copied' => 'MCP-URL kopiert.',
        'connect' => 'Mit :client verbinden',
        'clients' => [
            'claude' => 'Öffne Settings → Connectors, füge einen benutzerdefinierten Connector hinzu und füge die URL oben ein.',
            'chatgpt' => 'Öffne Settings → Apps & Connectors, erstelle einen benutzerdefinierten Connector und füge die URL oben ein.',
        ],
    ],
    'social' => [
        'title' => 'Verbinde ein Social-Konto',
        'description' => 'Wähle mindestens ein Netzwerk, in dem TryPost deine Inhalte veröffentlichen kann.',
        'connected_elsewhere' => 'Du hast bereits ein Konto in einem anderen Workspace verbunden — dieser Schritt ist erledigt.',
    ],
    'first_post' => [
        'title' => 'Erstelle deinen ersten Beitrag',
        'description' => 'Probiere diesen Starter-Prompt mit deinem verbundenen Assistenten aus, oder erstelle den Beitrag direkt in TryPost.',
        'prompt_label' => 'Beispiel-Prompt',
        'sample_prompt' => 'Erstelle einen freundlichen Social-Beitrag, der meine Marke vorstellt, und passe ihn für jedes verbundene Netzwerk an.',
        'copy_prompt' => 'Prompt kopieren',
        'copied' => 'Beispiel-Prompt kopiert.',
        'create_button' => 'Ersten Beitrag erstellen',
        'or' => 'oder',
    ],
    'ready' => [
        'title' => 'Du bist bereit zum Veröffentlichen',
        'description' => 'Alles klar. Weiter zu TryPost und plane deine Inhalte.',
    ],
];

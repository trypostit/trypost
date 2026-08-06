<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Verbinde KI-Assistenten mit deinem TryPost-Workspace. Sie nutzen dieselben Berechtigungen wie jeder angemeldete Nutzer.',
    'copy_step' => 'Kopiere deine TryPost-Server-URL',
    'open_step' => 'Öffne deinen KI-Assistenten',
    'copy' => 'URL kopieren',
    'connect' => 'Mit :client verbinden',
    'step_add' => 'Füge Name, URL oder Config unten in deine App ein. Die Anmeldung öffnet sich beim ersten Verbinden im Browser.',
    'name_label' => 'Name',
    'url_label' => 'Server-URL',
    'config_label' => 'Config',
    'connected_title' => 'Verbundene Apps',
    'connected_description' => 'Assistenten, mit denen du dich angemeldet hast. Trenne Verbindungen, die du nicht mehr brauchst.',
    'connected_empty' => 'Noch nichts verbunden. Nutze Claude, ChatGPT oder einen anderen Client oben.',
    'disconnect' => 'Trennen',
    'disconnect_title' => 'App trennen',
    'disconnect_confirm' => 'Dadurch wird die App von TryPost abgemeldet. Sie muss sich neu verbinden, bevor sie MCP wieder nutzen kann.',
    'disconnected' => 'App getrennt.',
    'copied' => 'Kopiert',
    'last_used' => 'Zuletzt verwendet',
    'never' => 'Nie',
    'documentation_title' => 'Dokumentation',
    'documentation_description' => 'Einrichtungsguides pro Client, verfügbare Tools und Fehlerhilfe.',
    'view_docs' => 'Dokumentation ansehen',
    'connector_name' => 'TryPost',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => 'Andere Apps',
    'other_clients_description' => 'Cursor, VS Code, Claude Code und alles andere, das MCP spricht.',

    'clients' => [
        'claude' => 'Öffne Settings → Connectors, füge einen benutzerdefinierten Connector hinzu und füge die URL oben ein.',
        'chatgpt' => 'Öffne Settings → Apps & Connectors, erstelle einen benutzerdefinierten Connector und füge die URL oben ein.',
        'cursor' => 'Füge TryPost in Cursor als Remote-MCP-Server hinzu.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Füge die Konfiguration unten in die MCP-Einstellungen von VS Code ein.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Füge die Konfiguration unten in die MCP-Einstellungen von Claude Code ein.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Funktioniert mit jedem Client, der eine mcpServers-Config liest.',
        'other_name' => 'Andere',
    ],
];

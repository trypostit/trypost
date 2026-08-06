<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Koppel AI-assistenten aan je TryPost-workspace. Ze gebruiken dezelfde rechten als elke ingelogde gebruiker.',
    'copy_step' => 'Kopieer je TryPost-server-URL',
    'open_step' => 'Open je AI-assistent',
    'copy' => 'URL kopiëren',
    'connect' => 'Verbinden met :client',
    'step_add' => 'Plak de naam, URL of config hieronder in je app. Inloggen opent in de browser bij de eerste verbinding.',
    'name_label' => 'Naam',
    'url_label' => 'Server-URL',
    'config_label' => 'Config',
    'connected_title' => 'Gekoppelde apps',
    'connected_description' => 'Assistenten waarmee je bent ingelogd. Koppel apps los die je niet meer gebruikt.',
    'connected_empty' => 'Nog niets gekoppeld. Gebruik Claude, ChatGPT of een andere client hierboven.',
    'disconnect' => 'Ontkoppelen',
    'disconnect_title' => 'App ontkoppelen',
    'disconnect_confirm' => 'Dit logt de app uit bij TryPost. Hij moet opnieuw verbinden om MCP weer te gebruiken.',
    'disconnected' => 'App ontkoppeld.',
    'copied' => 'Gekopieerd',
    'last_used' => 'Laatst gebruikt',
    'never' => 'Nooit',
    'documentation_title' => 'Documentatie',
    'documentation_description' => 'Handleidingen per client, beschikbare tools en probleemoplossing.',
    'view_docs' => 'Documentatie bekijken',
    'connector_name' => 'TryPost',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => 'Andere apps',
    'other_clients_description' => 'Cursor, VS Code, Claude Code en alles wat MCP spreekt.',

    'clients' => [
        'claude' => 'Open Settings → Connectors, voeg een aangepaste connector toe en plak de URL hierboven.',
        'chatgpt' => 'Open Settings → Apps & Connectors, maak een aangepaste connector aan en plak de URL hierboven.',
        'cursor' => 'Voeg TryPost toe als remote MCP-server in Cursor.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Plak de config hieronder in de MCP-instellingen van VS Code.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Plak de config hieronder in de MCP-instellingen van Claude Code.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Werkt met elke client die een mcpServers-config leest.',
        'other_name' => 'Overig',
    ],
];

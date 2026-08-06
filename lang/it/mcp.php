<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Collega assistenti IA così possono creare e gestire post con il tuo account TryPost.',
    'copy_step' => 'Copia l’URL del server TryPost',
    'open_step' => 'Apri il tuo assistente IA',
    'copy' => 'Copia URL',
    'connect' => 'Collega con :client',
    'step_add' => 'Incolla nome, URL o config qui sotto nella tua app. Il login si apre nel browser al primo collegamento.',
    'name_label' => 'Nome',
    'url_label' => 'URL del server',
    'config_label' => 'Config',
    'connected_title' => 'App collegate',
    'connected_description' => 'Assistenti con accesso di chiunque su questo account. Puoi disconnettere solo i tuoi.',
    'connected_empty' => 'Nessuna connessione ancora. Usa Claude, ChatGPT o un altro client sopra.',
    'connected_by' => 'Connesso da :name',
    'disconnect' => 'Scollega',
    'disconnect_title' => 'Scollega app',
    'disconnect_confirm' => 'Questo scollega l’app da TryPost. Dovrà riconnettersi prima di usare di nuovo MCP.',
    'disconnected' => 'App scollegata.',
    'copied' => 'Copiato',
    'last_used' => 'Ultimo uso',
    'never' => 'Mai',
    'documentation_title' => 'Documentazione',
    'documentation_description' => 'Guide per client, tools disponibili e risoluzione problemi.',
    'view_docs' => 'Vedi documentazione',
    'connector_name' => 'TryPost',

    'authorize_denied_title' => 'Cannot connect',
    'authorize_denied_body' => ':client needs a Member or Admin role on at least one workspace. Ask a workspace admin to upgrade your role, then try again.',
    'authorize_denied_close' => 'Close',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => 'Altre app',
    'other_clients_description' => 'Cursor, VS Code, Claude Code e qualsiasi app che parla MCP.',

    'clients' => [
        'claude' => 'Apri Settings → Connectors, aggiungi un connettore personalizzato e incolla l’URL qui sopra.',
        'chatgpt' => 'Apri Settings → Apps & Connectors, crea un connettore personalizzato e incolla l’URL qui sopra.',
        'cursor' => 'Aggiungi TryPost come server MCP remoto in Cursor.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Incolla la config qui sotto nelle impostazioni MCP di VS Code.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Incolla la config qui sotto nelle impostazioni MCP di Claude Code.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Funziona con qualsiasi client che legge una config mcpServers.',
        'other_name' => 'Altri',
    ],
];

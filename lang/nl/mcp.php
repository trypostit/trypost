<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Koppel AI-assistenten aan je workspace.',
    'copy' => 'Kopiëren',
    'connect' => 'Verbind :client',
    'name_label' => 'Naam',
    'url_label' => 'Server-URL',
    'config_label' => 'Config',
    'tabs' => [
        'connected' => 'Gekoppelde apps',
        'connect' => 'App koppelen',
    ],
    'connect_other' => 'Koppelen',
    'other_hint' => 'Elke app die MCP ondersteunt.',
    'other_dialog_title' => 'Koppel TryPost aan een andere app',
    'connected_description' => 'Assistenten waarmee je bent ingelogd. Koppel apps los die je niet meer gebruikt.',
    'connected_empty_title' => 'Geen gekoppelde apps',
    'connected_empty' => 'Koppel Claude, ChatGPT of een andere app om die hier te zien.',
    'disconnect' => 'Ontkoppelen',
    'disconnect_title' => 'App ontkoppelen',
    'disconnect_confirm' => 'Dit logt de app uit bij TryPost. Hij moet opnieuw verbinden om MCP weer te gebruiken.',
    'disconnected' => 'App ontkoppeld.',
    'copied' => 'Gekopieerd',
    'last_used' => 'Laatst gebruikt',
    'never' => 'Nooit',
    'connector_name' => 'TryPost',

    'authorize' => [
        'page_title' => 'Applicatie autoriseren - TryPost',
        'app_title' => 'MCP autoriseren',
        'heading' => ':client autoriseren',
        'intro' => 'Deze applicatie kan:',
        'intro_capability' => 'Beschikbare MCP-functies gebruiken.',
        'logged_in_as' => 'Ingelogd als:',
        'workspace' => 'Workspace:',
        'workspace_scope' => 'Deze verbinding krijgt alleen toegang tot de geselecteerde workspace.',
        'permissions' => 'Rechten:',
        'cancel' => 'Annuleren',
        'approve' => 'Autoriseren',
        'approving' => 'Autoriseren...',
        'select_workspace' => 'Selecteer een workspace',
        'search_workspace' => 'Workspaces zoeken...',
        'no_workspace_found' => 'Geen workspace gevonden',
        'scope_mcp_use' => 'MCP-server gebruiken',
        'error_page_title' => 'Autorisatie mislukt - TryPost',
        'error_title' => 'Verbinden mislukt',
        'error_body' => 'Dit autorisatieverzoek is ongeldig of verlopen. Sluit dit venster en probeer opnieuw te verbinden vanuit je MCP-client.',
        'error_code' => 'Fout: :error',
    ],

    'other_clients_title' => 'Andere apps',

    'dialog' => [
        'title' => 'Koppel TryPost aan :client',
        'description' => 'Duurt ongeveer een minuut. Deze pagina werkt bij zodra je gekoppeld bent.',
        'open' => ':client openen',
        'steps' => [
            'claude' => [
                'paste' => 'Plak de URL in het URL-veld van de connector',
                'add' => 'Klik op Toevoegen en daarna op Verbinden',
                'login' => 'Log in bij TryPost en kies de workspace',
            ],
            'chatgpt' => [
                'developer' => 'Zet de ontwikkelaarsmodus aan in de instellingen van ChatGPT',
                'create' => 'Maak een app met de naam TryPost en plak de URL',
                'login' => 'Log in bij TryPost en kies de workspace',
            ],
            'other' => [
                'add' => 'Voeg een eigen MCP-server toe in je app',
                'paste' => 'Plak de naam en URL, of de configuratie',
                'login' => 'Log in bij TryPost en kies de workspace',
            ],
        ],
        'waiting' => 'Wachten tot je AI verbindt…',
    ],

];

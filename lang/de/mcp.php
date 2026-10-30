<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Verbinde KI-Assistenten mit deinem Workspace.',
    'copy' => 'Kopieren',
    'connect' => ':client verbinden',
    'name_label' => 'Name',
    'url_label' => 'Server-URL',
    'config_label' => 'Config',
    'tabs' => [
        'connected' => 'Verbundene Apps',
        'connect' => 'App verbinden',
    ],
    'connect_other' => 'Verbinden',
    'other_hint' => 'Jede App mit MCP-Unterstützung.',
    'other_dialog_title' => 'TryPost mit einer anderen App verbinden',
    'connected_description' => 'Assistenten, mit denen du dich angemeldet hast. Trenne Verbindungen, die du nicht mehr brauchst.',
    'connected_empty_title' => 'Keine verbundenen Apps',
    'connected_empty' => 'Verbinde Claude, ChatGPT oder eine andere App, um sie hier zu sehen.',
    'disconnect' => 'Trennen',
    'disconnect_title' => 'App trennen',
    'disconnect_confirm' => 'Dadurch wird die App von TryPost abgemeldet. Sie muss sich neu verbinden, bevor sie MCP wieder nutzen kann.',
    'disconnected' => 'App getrennt.',
    'copied' => 'Kopiert',
    'last_used' => 'Zuletzt verwendet',
    'never' => 'Nie',
    'connector_name' => 'TryPost',

    'authorize' => [
        'page_title' => 'Anwendung autorisieren - TryPost',
        'app_title' => 'MCP autorisieren',
        'heading' => ':client autorisieren',
        'intro' => 'Diese Anwendung kann:',
        'intro_capability' => 'Verfügbare MCP-Funktionen nutzen.',
        'logged_in_as' => 'Angemeldet als:',
        'workspace' => 'Workspace:',
        'workspace_scope' => 'Diese Verbindung greift nur auf den ausgewählten Workspace zu.',
        'permissions' => 'Berechtigungen:',
        'cancel' => 'Abbrechen',
        'approve' => 'Autorisieren',
        'approving' => 'Wird autorisiert...',
        'select_workspace' => 'Workspace auswählen',
        'search_workspace' => 'Workspaces suchen...',
        'no_workspace_found' => 'Kein Workspace gefunden',
        'scope_mcp_use' => 'MCP-Server nutzen',
        'error_page_title' => 'Autorisierung fehlgeschlagen - TryPost',
        'error_title' => 'Verbindung nicht möglich',
        'error_body' => 'Diese Autorisierungsanfrage ist ungültig oder abgelaufen. Schließe dieses Fenster und verbinde dich erneut über deinen MCP-Client.',
        'error_code' => 'Fehler: :error',
    ],

    'other_clients_title' => 'Andere Apps',

    'dialog' => [
        'title' => 'Verbinde TryPost mit :client',
        'description' => 'Dauert etwa eine Minute. Diese Seite aktualisiert sich, sobald du verbunden bist.',
        'open' => ':client öffnen',
        'steps' => [
            'claude' => [
                'paste' => 'Füge die URL in das URL-Feld des Connectors ein',
                'add' => 'Klicke auf Hinzufügen und dann auf Verbinden',
                'login' => 'Melde dich bei TryPost an und wähle den Workspace',
            ],
            'chatgpt' => [
                'open' => 'Öffne TryPost in ChatGPT und klicke auf „Verbinden“',
                'login' => 'Melde dich bei TryPost an und wähle den Workspace',
            ],
            'other' => [
                'add' => 'Füge in deiner App einen eigenen MCP-Server hinzu',
                'paste' => 'Füge Name und URL oder die Konfiguration ein',
                'login' => 'Melde dich bei TryPost an und wähle den Workspace',
            ],
        ],
        'waiting' => 'Warte auf die Verbindung deiner KI…',
    ],

];

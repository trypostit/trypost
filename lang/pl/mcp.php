<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Połącz asystentów AI, aby tworzyli i zarządzali postami na koncie TryPost.',
    'copy_step' => 'Skopiuj URL serwera TryPost',
    'open_step' => 'Otwórz asystenta AI',
    'copy' => 'Kopiuj URL',
    'connect' => 'Połącz z :client',
    'step_add' => 'Wklej nazwę, URL lub config poniżej do swojej aplikacji. Logowanie otworzy się w przeglądarce przy pierwszym połączeniu.',
    'name_label' => 'Nazwa',
    'url_label' => 'URL serwera',
    'config_label' => 'Config',
    'connected_title' => 'Połączone aplikacje',
    'connected_description' => 'Asystenci, z którymi się zalogowałeś. Możesz rozłączyć te, których już nie używasz.',
    'connected_empty' => 'Nic jeszcze nie połączono. Użyj Claude, ChatGPT lub innego klienta powyżej.',
    'disconnect' => 'Rozłącz',
    'disconnect_title' => 'Rozłącz aplikację',
    'disconnect_confirm' => 'To wyloguje aplikację z TryPost. Musi połączyć się ponownie, zanim znów użyje MCP.',
    'disconnected' => 'Aplikacja rozłączona.',
    'copied' => 'Skopiowano',
    'last_used' => 'Ostatnie użycie',
    'never' => 'Nigdy',
    'documentation_title' => 'Dokumentacja',
    'documentation_description' => 'Przewodniki per klient, dostępne tools i rozwiązywanie problemów.',
    'view_docs' => 'Zobacz dokumentację',
    'connector_name' => 'TryPost',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => 'Inne aplikacje',
    'other_clients_description' => 'Cursor, VS Code, Claude Code i wszystko, co mówi MCP.',

    'clients' => [
        'claude' => 'Otwórz Settings → Connectors, dodaj niestandardowy connector, a następnie wklej powyższy URL.',
        'chatgpt' => 'Otwórz Settings → Apps & Connectors, utwórz niestandardowy connector, a następnie wklej powyższy URL.',
        'cursor' => 'Dodaj TryPost jako zdalny serwer MCP w Cursorze.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Wklej poniższą konfigurację w ustawieniach MCP VS Code.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Wklej poniższą konfigurację w ustawieniach MCP Claude Code.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Działa z każdym klientem, który czyta config mcpServers.',
        'other_name' => 'Inne',
    ],
];

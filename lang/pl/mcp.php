<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Połącz asystentów AI z workspace\'em.',
    'copy' => 'Kopiuj',
    'connect' => 'Połącz :client',
    'name_label' => 'Nazwa',
    'url_label' => 'URL serwera',
    'config_label' => 'Config',
    'tabs' => [
        'connected' => 'Połączone aplikacje',
        'connect' => 'Połącz aplikację',
    ],
    'connect_other' => 'Połącz',
    'other_hint' => 'Każda aplikacja obsługująca MCP.',
    'other_dialog_title' => 'Połącz TryPost z inną aplikacją',
    'connected_description' => 'Asystenci, z którymi się zalogowałeś. Możesz rozłączyć te, których już nie używasz.',
    'connected_empty_title' => 'Brak połączonych aplikacji',
    'connected_empty' => 'Połącz Claude, ChatGPT lub inną aplikację, aby zobaczyć ją tutaj.',
    'disconnect' => 'Rozłącz',
    'disconnect_title' => 'Rozłącz aplikację',
    'disconnect_confirm' => 'To wyloguje aplikację z TryPost. Musi połączyć się ponownie, zanim znów użyje MCP.',
    'disconnected' => 'Aplikacja rozłączona.',
    'copied' => 'Skopiowano',
    'last_used' => 'Ostatnie użycie',
    'never' => 'Nigdy',
    'connector_name' => 'TryPost',

    'authorize' => [
        'page_title' => 'Autoryzuj aplikację - TryPost',
        'app_title' => 'Autoryzuj MCP',
        'heading' => 'Autoryzuj :client',
        'intro' => 'Ta aplikacja będzie mogła:',
        'intro_capability' => 'Korzystać z dostępnych funkcji MCP.',
        'logged_in_as' => 'Zalogowano jako:',
        'workspace' => 'Przestrzeń robocza:',
        'workspace_scope' => 'To połączenie uzyska dostęp tylko do wybranej przestrzeni roboczej.',
        'permissions' => 'Uprawnienia:',
        'cancel' => 'Anuluj',
        'approve' => 'Autoryzuj',
        'approving' => 'Autoryzowanie...',
        'select_workspace' => 'Wybierz przestrzeń roboczą',
        'search_workspace' => 'Szukaj przestrzeni roboczych...',
        'no_workspace_found' => 'Nie znaleziono przestrzeni roboczej',
        'scope_mcp_use' => 'Korzystać z serwera MCP',
        'error_page_title' => 'Autoryzacja nie powiodła się - TryPost',
        'error_title' => 'Nie udało się połączyć',
        'error_body' => 'To żądanie autoryzacji jest nieprawidłowe lub wygasło. Zamknij to okno i spróbuj połączyć się ponownie z klienta MCP.',
        'error_code' => 'Błąd: :error',
    ],

    'other_clients_title' => 'Inne aplikacje',

    'dialog' => [
        'title' => 'Połącz TryPost z :client',
        'description' => 'Zajmuje około minuty. Ta strona odświeży się, gdy tylko się połączysz.',
        'open' => 'Otwórz :client',
        'steps' => [
            'claude' => [
                'paste' => 'Wklej adres URL w pole URL konektora',
                'add' => 'Kliknij Dodaj, a potem Połącz',
                'login' => 'Zaloguj się do TryPost i wybierz workspace',
            ],
            'chatgpt' => [
                'open' => 'Otwórz TryPost w ChatGPT i kliknij Połącz',
                'login' => 'Zaloguj się do TryPost i wybierz workspace',
            ],
            'other' => [
                'add' => 'Dodaj własny serwer MCP w swojej aplikacji',
                'paste' => 'Wklej nazwę i adres URL albo konfigurację',
                'login' => 'Zaloguj się do TryPost i wybierz workspace',
            ],
        ],
        'waiting' => 'Czekamy na połączenie Twojej AI…',
    ],

];

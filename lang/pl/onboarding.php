<?php

declare(strict_types=1);

return [
    'title' => 'Pierwsze kroki',
    'welcome' => 'Witaj w TryPost, :name',
    'welcome_anonymous' => 'Witaj w TryPost',
    'description' => 'Wykonaj poniższe kroki, aby zobaczyć, jak działa TryPost, i opublikować pierwszy post.',
    'skip_step' => 'Pomiń ten krok',
    'continue' => 'Przejdź do TryPost',
    'status' => [
        'complete' => 'Ukończone',
        'todo' => 'Do zrobienia',
        'skipped' => 'Pominięty',
    ],
    'mcp' => [
        'title' => 'Połącz asystenta AI',
        'description' => 'Dodaj TryPost jako serwer MCP, aby asystent mógł tworzyć i zarządzać postami społecznościowymi za Ciebie.',
        'copy_step' => 'Skopiuj URL serwera TryPost',
        'open_step' => 'Otwórz asystenta AI',
        'copy' => 'Kopiuj URL',
        'copied' => 'URL MCP skopiowany.',
        'connect' => 'Połącz z :client',
        'clients' => [
            'claude' => 'Otwórz Settings → Connectors, dodaj niestandardowy connector, a następnie wklej powyższy URL.',
            'chatgpt' => 'Otwórz Settings → Apps & Connectors, utwórz niestandardowy connector, a następnie wklej powyższy URL.',
        ],
    ],
    'social' => [
        'title' => 'Połącz konto społecznościowe',
        'description' => 'Wybierz co najmniej jedną sieć, na której TryPost może publikować Twoje treści.',
        'connected_elsewhere' => 'Masz już połączone konto w innym workspace, więc ten krok jest ukończony.',
    ],
    'first_post' => [
        'title' => 'Utwórz pierwszy post',
        'description' => 'Wypróbuj ten prompt startowy z podłączonym asystentem albo utwórz post bezpośrednio w TryPost.',
        'prompt_label' => 'Przykładowy prompt',
        'sample_prompt' => 'Utwórz przyjazny post społecznościowy przedstawiający moją markę i dostosuj go do każdej podłączonej sieci.',
        'copy_prompt' => 'Kopiuj prompt',
        'copied' => 'Przykładowy prompt skopiowany.',
        'create_button' => 'Utwórz pierwszy post',
        'or' => 'lub',
    ],
    'ready' => [
        'title' => 'Możesz już publikować',
        'description' => 'Wszystko gotowe. Przejdź do TryPost i zacznij planować treści.',
    ],
];

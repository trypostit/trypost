<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'Automatycznie publikuj w pozostałych sieciach filmy, które wrzucasz poza TryPost.',
    'new' => 'Nowy repurpose',

    'flow' => [
        'no_destinations' => 'Brak celu',
    ],

    'publish_mode' => [

        'title' => 'Publikowanie',

        'description' => 'Co się dzieje, gdy pojawia się nowy film.',

    ],

    'publish_modes' => [

        'publish' => 'Publikuj automatycznie',

        'publish_hint' => 'Każdy nowy film jest planowany zaraz po znalezieniu.',

        'draft' => 'Utwórz jako wersję roboczą',

        'draft_hint' => 'Każdy nowy film trafia tu jako wersja robocza do sprawdzenia i publikacji.',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Filmy',
        'story' => 'Stories',
    ],

    'source' => [
        'title' => 'Źródło',
        'description' => 'TryPost obserwuje to konto w poszukiwaniu nowych filmów w formacie poniżej.',
        'account_label' => 'Konto',
        'watch_label' => 'Obserwuj',
    ],

    'summary' => [
        'sentence' => 'Każdy nowy :format opublikowany na :source jest publikowany ponownie na :destinations.',
        'no_destinations' => 'Każdy nowy :format opublikowany na :source czeka na cel.',
    ],

    'empty' => [
        'title' => 'Nie skonfigurowano jeszcze repurpose',
        'description' => 'Wybierz punkt startowy poniżej. TryPost obserwuje wybrane konto i publikuje każdy nowy film w sieciach, które zaznaczysz.',
    ],

    'table' => [
        'flow' => 'Przepływ',
        'source' => 'Źródło',
        'destinations' => 'Cele',
        'status' => 'Status',
        'published' => 'Zreplikowane',
        'last_polled' => 'Ostatnie sprawdzenie',
    ],

    'status' => [
        'draft' => 'Szkic',
        'active' => 'Aktywny',
        'paused' => 'Wstrzymany',
        'disabled' => 'Wyłączony',
    ],

    'templates' => [
        'use' => 'Użyj tego szablonu',
        'instagram_everywhere' => [
            'title' => 'Instagram wszędzie',
            'description' => 'Opublikuj Reel na Instagramie, a TryPost powtórzy go na TikToku, YouTube Shorts i Facebooku.',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook wszędzie',
            'description' => 'Opublikuj film na swojej stronie na Facebooku, a TryPost powtórzy go na Instagramie, TikToku i YouTube Shorts.',
        ],
    ],

    'create' => [
        'title' => 'Nowy repurpose',
        'description' => 'Wybierz konto, które TryPost ma obserwować. Cele wybierzesz na następnym ekranie.',
        'source_label' => 'Konto źródłowe',
        'source_placeholder' => 'Wybierz konto',
        'source_search' => 'Szukaj kont',
        'source_empty' => 'Nie znaleziono konta.',
        'source_placeholder' => 'Wybierz konto',
        'no_accounts' => 'Najpierw połącz konto Instagrama lub Facebooka. Tylko one mogą być źródłem, bo tylko te sieci pozwalają pobrać film.',
        'submit' => 'Utwórz',
        'connect' => 'Połącz konto',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'Filmy opublikowane na tym koncie poza TryPost są replikowane do celów poniżej.',
    ],

    'tabs' => [
        'configuration' => 'Konfiguracja',
        'activity' => 'Aktywność',
        'settings' => 'Ustawienia',
    ],

    'destinations' => [
        'title' => 'Cele',
        'description' => 'Wybierz konta, które go otrzymają. Każde publikuje w wybranym przez ciebie formacie.',
        'hint' => 'Opis jest dostosowywany do sieci tylko wtedy, gdy przekracza jej limit.',
        'none_available' => 'W tym obszarze roboczym nie ma jeszcze innego połączonego konta.',
        'save' => 'Zapisz cele',
        'saved' => 'Cele zapisane',
        'publish_as' => 'Publikuj jako',
    ],

    'status_card' => [
        'title' => 'Status',
        'activate' => 'Aktywuj',
        'pause' => 'Wstrzymaj',
        'resume' => 'Wznów',
        'disable' => 'Wyłącz',
        'watermark' => 'Obserwuje od',
        'last_polled' => 'Ostatnie sprawdzenie',
        'draft_hint' => 'Wybierz co najmniej jeden cel i aktywuj. Replikowane są tylko filmy opublikowane po aktywacji.',
        'active_hint' => 'TryPost regularnie sprawdza to konto i replikuje każdy nowy film.',
        'paused_hint' => 'Sprawdzanie jest wstrzymane. Wznowienie kontynuuje od miejsca zatrzymania i nic nie ginie.',
        'disabled_hint' => 'Wyłączone. Ponowna aktywacja zaczyna od zera: to, co opublikowałeś w międzyczasie, zostaje pominięte.',
    ],

    'items' => [
        'source' => 'Oryginał',
        'published_at' => 'Opublikowano',
        'status' => 'Status',
        'detail' => 'Szczegół',
        'posts' => 'Zreplikowano do',
        'view_original' => 'Zobacz oryginał',
        'open_post' => 'Otwórz post',
        'statuses' => [
            'pending' => 'W kolejce',
            'processing' => 'Przetwarzanie',
            'published' => 'Zreplikowano',
            'drafted' => 'Wersja robocza',
            'skipped' => 'Pominięto',
            'failed' => 'Niepowodzenie',
        ],
        'reasons' => [
            'published_via_trypost' => 'Już opublikowane przez TryPost',
            'media_url_missing' => 'Sieć nie udostępniła pliku do pobrania, zwykle z powodu dźwięku chronionego prawem autorskim',
            'download_failed' => 'Nie udało się pobrać filmu',
            'post_creation_failed' => 'Brak dostępnego celu',
        ],
    ],

    'danger' => [
        'title' => 'Usuń ten repurpose',
        'description' => 'Sprawdzanie zatrzyma się natychmiast. Utworzone już posty pozostaną w kalendarzu.',
        'delete' => 'Usuń repurpose',
    ],

    'errors' => [
        'source_already_used' => 'To konto zasila już inny repurpose. Edytuj tamten.',
        'destinations_required' => 'Wybierz co najmniej jeden cel przed aktywacją.',
        'destination_needs_video' => 'Ten format nie przyjmuje filmu.',
        'only_paused_resumes' => 'Wznowić można tylko wstrzymany repurpose.',
        'only_active_pauses' => 'Tylko aktywny repurpose można wstrzymać.',
        'only_running_disables' => 'Tylko działający repurpose można wyłączyć.',
        'only_idle_activates' => 'Tylko wersję roboczą lub wyłączony repurpose można aktywować.',
        'destination_unavailable' => 'To konto docelowe nie jest już dostępne.',
        'destination_is_source' => 'Ten cel to konto obserwowane przez ten repurpose.',
        'source_unavailable' => 'To konto źródłowe nie jest już dostępne.',
        'action_failed' => 'Coś poszło nie tak. Sprawdź formularz i spróbuj ponownie.',
    ],
];

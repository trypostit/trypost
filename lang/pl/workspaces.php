<?php

declare(strict_types=1);

return [
    'title' => 'Przestrzenie robocze',
    'select_title' => 'Twoje przestrzenie robocze',
    'select_description' => 'Wybierz przestrzeń roboczą, aby kontynuować',
    'current' => 'Bieżąca',
    'connections' => ':count połączeń',
    'posts' => ':count postów',

    'create' => [
        'page_title' => 'Utwórz przestrzeń roboczą',
        'title' => 'Skonfiguruj przestrzeń roboczą',
        'description' => 'Opowiedz nam trochę o sobie lub swoim projekcie. Wykorzystamy to, aby dopasować posty generowane przez AI do Twojego stylu.',
        'website' => 'Strona internetowa',
        'website_placeholder' => 'https://twojamarka.com',
        'autofill' => 'Uzupełnij automatycznie ze strony',
        'autofill_missing_url' => 'Najpierw wprowadź adres URL.',
        'autofill_success' => 'Wczytano informacje o marce.',
        'autofill_error' => 'Nie udało się uzupełnić automatycznie. Możesz wypełnić pola ręcznie.',
        'autofill_errors' => [
            'unreachable' => 'Nie udało się połączyć z tą stroną (:reason).',
            'http_status' => 'Strona zwróciła nieoczekiwany status (:status).',
            'invalid_scheme' => 'Obsługiwane są tylko adresy URL http i https.',
            'missing_host' => 'W adresie URL brakuje hosta.',
            'unresolvable_host' => 'Nie udało się rozpoznać hosta (:host).',
            'private_network' => 'Adresy URL wskazujące na sieci prywatne są niedozwolone.',
        ],
        'logo_captured' => 'Pobrano logo z Twojej strony.',
        'name' => 'Nazwa przestrzeni roboczej',
        'name_placeholder' => 'np. Acme Inc',
        'brand_description' => 'Opis marki',
        'brand_description_placeholder' => 'Czym zajmuje się Twoja marka?',
        'content_language' => 'Język treści',
        'content_language_description' => 'Podpisy generowane przez AI będą tworzone w tym języku.',
        'brand_color' => 'Kolor marki',
        'background_color' => 'Kolor tła',
        'text_color' => 'Kolor tekstu',
        'submit' => 'Utwórz przestrzeń roboczą',
        'success' => 'Przestrzeń robocza utworzona. Połącz konto społecznościowe, aby zacząć publikować.',
    ],

    'cannot_delete_last' => 'Nie możesz usunąć swojej jedynej przestrzeni roboczej. Anuluj subskrypcję w ustawieniach rozliczeń, aby zamknąć konto.',

    'flash' => [
        'deleted' => 'Przestrzeń robocza została pomyślnie usunięta.',
    ],
];

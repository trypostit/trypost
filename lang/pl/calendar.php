<?php

declare(strict_types=1);

return [
    'title' => 'Kalendarz',
    'today' => 'Dziś',
    'previous' => 'Poprzedni',
    'next' => 'Następny',
    'days' => '3 dni',
    'week' => 'Tydzień',
    'month' => 'Miesiąc',
    'new_post' => 'Nowy post',
    'no_content' => 'Brak treści',
    'more' => '+:count więcej',
    'less' => 'Pokaż mniej',
    'empty_day' => 'Nic nie zaplanowano na ten dzień.',
    'status' => [
        'label' => 'Status',
        'all' => 'Wszystkie posty',
        'drafts' => 'Szkice',
        'scheduled' => 'Zaplanowane',
        'sent' => 'Wysłane',
    ],
    'no_date' => 'Bez daty',
    'undated' => [
        'title' => 'Szkice bez daty',
        'description' => 'Szkice bez zaplanowanej daty.',
        'close' => 'Zamknij',
        'empty' => 'Brak szkiców bez daty.',
        'draft' => 'Szkic',
        'no_channel' => 'Brak kanału',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => 'Przenoszenie wpisu na własną godzinę',
            'description' => 'Ten wpis zostanie przeniesiony z miejsca w kolejce, :from, na własną godzinę, :to, która nie należy do Twoich godzin publikacji.',
            'unqueued' => 'Wpis zostanie opublikowany o wybranej godzinie, ale nie zajmie miejsca w Twojej kolejce.',
            'dont_show_again' => 'Nie pokazuj ponownie',
            'cancel' => 'Anuluj',
            'confirm' => 'Przenieś na własną godzinę',
        ],
        'error' => 'Nie udało się przenieść wpisu. Spróbuj ponownie.',
        'drag_hint' => 'Przeciągnij, aby zmienić termin',
        'drag_hint_undated' => 'Przeciągnij szkic bez daty dla :channel, aby go zaplanować',
        'announce' => [
            'over' => 'element zaplanowany na :from znajduje się nad: :target.',
            'moved' => 'element zaplanowany na :from przeniesiono na :to.',
            'returned' => 'Element wrócił na pierwotne miejsce.',
            'undated' => 'element zaplanowany na :from upuszczono w sekcji Bez daty.',
            'from_undated' => 'Szkic bez daty dla :channel zaplanowano na :to.',
            'date_time' => ':date, :time',
        ],
        'toast' => [
            'moved' => 'Wpis przeniesiono na :time.',
            'moved_draft' => 'Szkic przeniesiono na :time.',
            'undated' => 'Szkic przeniesiono do sekcji Bez daty.',
            'unqueued_suffix' => 'Miejsce w kolejce jest znów wolne.',
        ],
    ],
];

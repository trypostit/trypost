<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => 'Neu',
    ],
    'actions' => [
        'got_it' => 'Verstanden',
        'back' => 'Zurück',
        'next' => 'Weiter',
        'done' => 'Fertig',
        'skip' => 'Überspringen',
    ],
    'progress' => ':current von :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => 'Ansicht wechseln',
                    'description' => 'Plane deine Beiträge in dem Format, das für dich am besten passt.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => 'Nach Zeitraum filtern',
                    'description' => 'Filtere deine Zahlen nach den letzten Tagen oder einem eigenen Zeitraum.',
                ],
                'channels' => [
                    'title' => 'Nach Kanal filtern',
                    'description' => 'Sieh dir die Zahlen eines Kanals an oder vergleiche mehrere.',
                ],
                'labels' => [
                    'title' => 'Nach Label filtern',
                    'description' => 'Verfolge eine Kampagne oder Serie über ihre Labels.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'Du hast noch keine KI verbunden',
                    'description' => 'Verbinde Claude oder ChatGPT, um Posts einfach per Nachricht zu erstellen, zu planen und zu analysieren.',
                    'not_now' => 'Nicht jetzt',
                ],
            ],
        ],
    ],
];

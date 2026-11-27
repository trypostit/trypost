<?php

declare(strict_types=1);

test('slavic plurals pick the grammatical form for every count', function (string $locale, string $key, array $expected) {
    $rendered = collect($expected)->map(fn (string $text, int $count): string => trans_choice($key, $count, [
        'count' => $count,
        'time' => '9:00',
        'weekday' => 'X',
        'day' => 'D',
        'date' => 'M',
        'rule' => 'R',
        'until' => 'U',
    ], $locale))->all();

    expect($rendered)->toBe($expected);
})->with([
    'ru channels' => ['ru', 'sidebar.channels_count', [1 => '1 канал', 2 => '2 канала', 5 => '5 каналов', 11 => '11 каналов', 21 => '21 канал', 22 => '22 канала', 25 => '25 каналов']],
    'ru frequency' => ['ru', 'posts.recurrence.frequency.day', [1 => 'День', 2 => 'Дня', 5 => 'Дней', 11 => 'Дней', 21 => 'День', 22 => 'Дня', 25 => 'Дней']],
    'ru rule' => ['ru', 'posts.recurrence.rule.day', [1 => 'каждый день в 9:00', 2 => 'каждые 2 дня в 9:00', 5 => 'каждые 5 дней в 9:00', 11 => 'каждые 11 дней в 9:00', 21 => 'каждый 21 день в 9:00', 22 => 'каждые 22 дня в 9:00', 25 => 'каждые 25 дней в 9:00']],
    'ru times' => ['ru', 'posts.recurrence.times', [1 => 'раз', 2 => 'раза', 5 => 'раз', 11 => 'раз', 21 => 'раз', 22 => 'раза', 25 => 'раз']],
    'uk channels' => ['uk', 'sidebar.channels_count', [1 => '1 канал', 2 => '2 канали', 5 => '5 каналів', 11 => '11 каналів', 21 => '21 канал', 22 => '22 канали', 25 => '25 каналів']],
    'uk rule' => ['uk', 'posts.recurrence.rule.week', [1 => 'щотижня (X) о 9:00', 2 => 'кожні 2 тижні (X) о 9:00', 5 => 'кожні 5 тижнів (X) о 9:00', 11 => 'кожні 11 тижнів (X) о 9:00', 21 => 'кожен 21 тиждень (X) о 9:00', 22 => 'кожні 22 тижні (X) о 9:00', 25 => 'кожні 25 тижнів (X) о 9:00']],
    'pl channels' => ['pl', 'sidebar.channels_count', [1 => '1 kanał', 2 => '2 kanały', 5 => '5 kanałów', 11 => '11 kanałów', 21 => '21 kanałów', 22 => '22 kanały', 25 => '25 kanałów']],
    'pl rule' => ['pl', 'posts.recurrence.rule.month', [1 => 'co miesiąc, dnia D, o 9:00', 2 => 'co 2 miesiące, dnia D, o 9:00', 5 => 'co 5 miesięcy, dnia D, o 9:00', 11 => 'co 11 miesięcy, dnia D, o 9:00', 21 => 'co 21 miesięcy, dnia D, o 9:00', 22 => 'co 22 miesiące, dnia D, o 9:00', 25 => 'co 25 miesięcy, dnia D, o 9:00']],
    'pl banner' => ['pl', 'posts.recurrence.banner', [1 => 'Ten post będzie publikowany R, do U (pozostał 1 post).', 2 => 'Ten post będzie publikowany R, do U (pozostały 2 posty).', 5 => 'Ten post będzie publikowany R, do U (pozostało 5 postów).', 11 => 'Ten post będzie publikowany R, do U (pozostało 11 postów).', 21 => 'Ten post będzie publikowany R, do U (pozostało 21 postów).', 22 => 'Ten post będzie publikowany R, do U (pozostały 22 posty).', 25 => 'Ten post będzie publikowany R, do U (pozostało 25 postów).']],
]);

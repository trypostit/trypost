<?php

declare(strict_types=1);

return [
    'title' => 'Kalender',
    'today' => 'Vandaag',
    'previous' => 'Vorige',
    'next' => 'Volgende',
    'days' => '3 dagen',
    'week' => 'Week',
    'month' => 'Maand',
    'new_post' => 'Nieuwe post',
    'no_content' => 'Geen inhoud',
    'more' => '+:count meer',
    'less' => 'Minder tonen',
    'empty_day' => 'Niets gepland voor deze dag.',
    'status' => [
        'label' => 'Status',
        'all' => 'Alle berichten',
        'drafts' => 'Concepten',
        'scheduled' => 'Gepland',
        'sent' => 'Verzonden',
    ],
    'no_date' => 'Geen datum',
    'undated' => [
        'title' => 'Concepten zonder datum',
        'description' => 'Concepten zonder geplande datum.',
        'close' => 'Sluiten',
        'empty' => 'Geen concepten zonder datum.',
        'draft' => 'Concept',
        'no_channel' => 'Geen kanaal',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => 'Bericht naar een eigen tijd verplaatsen',
            'description' => 'Je verplaatst dit bericht van een plek in de wachtrij, :from, naar een eigen tijd, :to, die niet een van je publicatietijden is.',
            'unqueued' => 'Het bericht wordt op de nieuwe tijd gepubliceerd, maar neemt geen plek in je wachtrij in.',
            'dont_show_again' => 'Niet meer tonen',
            'cancel' => 'Annuleren',
            'confirm' => 'Eigen tijd gebruiken',
        ],
        'error' => 'Kon dit bericht niet verplaatsen. Probeer het opnieuw.',
        'errors' => [
            'past' => 'Deze tijd is al voorbij. Kies een latere tijd.',
            'required' => 'Kies een tijd voor dit bericht.',
            'pending' => 'Dit bericht wacht op goedkeuring. Verplaats het zodra het is goedgekeurd of afgewezen.',
            'publishing_soon' => 'Dit bericht wordt zo gepubliceerd en kan niet meer worden verplaatst.',
            'too_far' => 'Kies een datum vóór 19 januari 2038.',
        ],
        'drag_hint' => 'Sleep om te verplaatsen',
        'drag_hint_undated' => 'Sleep het concept zonder datum voor :channel om te plannen',
        'announce' => [
            'over' => 'item gepland voor :from staat boven :target.',
            'over_undated' => 'Concept zonder datum voor :channel staat boven :target.',
            'moved' => 'item gepland voor :from is verplaatst naar :to.',
            'returned' => 'Item is teruggezet op de oorspronkelijke plek.',
            'undated' => 'item gepland voor :from is neergezet op Geen datum.',
            'from_undated' => 'Concept zonder datum voor :channel is gepland voor :to.',
            'date_time' => ':date om :time',
        ],
        'toast' => [
            'moved' => 'Je bericht is verplaatst naar :time.',
            'moved_draft' => 'Je concept is verplaatst naar :time.',
            'undated' => 'Je concept is verplaatst naar Geen datum.',
            'unqueued_suffix' => 'Het wachtrijslot is weer vrij.',
        ],
    ],
];

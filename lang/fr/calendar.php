<?php

declare(strict_types=1);

return [
    'title' => 'Calendrier',
    'today' => 'Aujourd\'hui',
    'previous' => 'Précédent',
    'next' => 'Suivant',
    'days' => '3 jours',
    'week' => 'Semaine',
    'month' => 'Mois',
    'new_post' => 'Nouvelle publication',
    'no_content' => 'Aucun contenu',
    'more' => '+:count de plus',
    'less' => 'Voir moins',
    'empty_day' => 'Rien de prévu ce jour-là.',
    'status' => [
        'label' => 'Statut',
        'all' => 'Toutes les publications',
        'drafts' => 'Brouillons',
        'scheduled' => 'Programmées',
        'sent' => 'Envoyées',
    ],
    'no_date' => 'Sans date',
    'undated' => [
        'title' => 'Brouillons sans date',
        'description' => 'Brouillons sans date de publication.',
        'close' => 'Fermer',
        'empty' => 'Aucun brouillon sans date.',
        'draft' => 'Brouillon',
        'no_channel' => 'Aucun canal',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => 'Déplacer la publication à une heure personnalisée',
            'description' => 'Vous déplacez cette publication d\'un créneau de la file d\'attente, :from, vers une heure personnalisée, :to, qui ne fait pas partie de vos horaires de publication.',
            'unqueued' => 'La publication sera diffusée à la nouvelle heure choisie, mais n\'occupera pas de place dans votre file d\'attente.',
            'dont_show_again' => 'Ne plus afficher',
            'cancel' => 'Annuler',
            'confirm' => 'Déplacer à l\'heure choisie',
        ],
        'error' => 'Impossible de déplacer cette publication. Réessayez.',
        'drag_hint' => 'Faites glisser pour reprogrammer',
        'drag_hint_undated' => 'Faites glisser le brouillon sans date de :channel pour le programmer',
        'announce' => [
            'over' => 'l\'élément prévu pour :from est au-dessus de :target.',
            'moved' => 'l\'élément prévu pour :from a été reprogrammé pour :to.',
            'returned' => 'L\'élément a été remis à sa position d\'origine.',
            'undated' => 'l\'élément prévu pour :from a été déposé sur Sans date.',
            'from_undated' => 'Le brouillon sans date de :channel a été programmé pour :to.',
            'date_time' => ':date à :time',
        ],
        'toast' => [
            'moved' => 'Votre publication a été reprogrammée pour :time.',
            'moved_draft' => 'Votre brouillon a été reprogrammé pour :time.',
            'undated' => 'Votre brouillon a été déplacé vers Sans date.',
            'unqueued_suffix' => 'L\'emplacement de la file est de nouveau libre.',
        ],
    ],
];

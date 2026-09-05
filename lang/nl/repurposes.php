<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'Publiceer video\'s die je buiten TryPost post automatisch opnieuw op je andere netwerken.',
    'new' => 'Nieuwe repurpose',

    'flow' => [
        'no_destinations' => 'Nog geen bestemming',
    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Video\'s',
        'story' => 'Stories',
    ],

    'source' => [
        'title' => 'Bron',
        'description' => 'TryPost volgt dit account op nieuwe video\'s van het formaat hieronder.',
        'watch_label' => 'Volgen',
    ],

    'summary' => [
        'sentence' => 'Elke nieuwe :format die je op :source plaatst, wordt opnieuw geplaatst op :destinations.',
        'no_destinations' => 'Elke nieuwe :format op :source wacht nog op een bestemming.',
    ],

    'empty' => [
        'title' => 'Nog geen repurpose ingesteld',
        'description' => 'Kies hieronder een startpunt. TryPost volgt het gekozen account en plaatst elke nieuwe video opnieuw op de netwerken die je aanvinkt.',
    ],

    'table' => [
        'flow' => 'Stroom',
        'source' => 'Bron',
        'destinations' => 'Bestemmingen',
        'status' => 'Status',
        'published' => 'Gerepliceerd',
        'last_polled' => 'Laatst gecontroleerd',
    ],

    'status' => [
        'draft' => 'Concept',
        'active' => 'Actief',
        'paused' => 'Gepauzeerd',
        'disabled' => 'Uitgeschakeld',
    ],

    'templates' => [
        'use' => 'Dit sjabloon gebruiken',
        'instagram_everywhere' => [
            'title' => 'Instagram overal',
            'description' => 'Plaats een Reel op Instagram en TryPost publiceert die opnieuw op TikTok, YouTube Shorts en Facebook.',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook overal',
            'description' => 'Plaats een video op je Facebook-pagina en TryPost publiceert die opnieuw op Instagram, TikTok en YouTube Shorts.',
        ],
    ],

    'create' => [
        'title' => 'Nieuwe repurpose',
        'description' => 'Kies het account dat TryPost moet volgen. De bestemmingen kies je in het volgende scherm.',
        'source_label' => 'Bronaccount',
        'source_placeholder' => 'Selecteer een account',
        'no_accounts' => 'Koppel eerst een Instagram- of Facebook-account. Alleen die kunnen bron zijn, want alleen zij laten ons de video downloaden.',
        'submit' => 'Aanmaken',
        'connect' => 'Account koppelen',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'Video\'s die buiten TryPost op dit account verschijnen, worden gerepliceerd naar de bestemmingen hieronder.',
    ],

    'tabs' => [
        'configuration' => 'Configuratie',
        'activity' => 'Activiteit',
        'settings' => 'Instellingen',
    ],

    'destinations' => [
        'title' => 'Bestemmingen',
        'description' => 'Kies de accounts die het ontvangen. Elk plaatst in het formaat dat jij kiest.',
        'hint' => 'Het bijschrift wordt alleen per netwerk aangepast als het de limiet van dat netwerk overschrijdt.',
        'none_available' => 'Er is nog geen ander account gekoppeld in deze workspace.',
        'save' => 'Bestemmingen opslaan',
        'publish_as' => 'Plaatsen als',
    ],

    'status_card' => [
        'title' => 'Status',
        'activate' => 'Activeren',
        'pause' => 'Pauzeren',
        'resume' => 'Hervatten',
        'disable' => 'Uitschakelen',
        'watermark' => 'Gevolgd sinds',
        'last_polled' => 'Laatst gecontroleerd',
        'draft_hint' => 'Kies minstens één bestemming en activeer daarna. Alleen video\'s van na de activering worden gerepliceerd.',
        'active_hint' => 'TryPost controleert dit account regelmatig en repliceert elke nieuwe video.',
        'paused_hint' => 'De controles liggen stil. Bij hervatten gaat het verder waar het stopte, er gaat niets verloren.',
        'disabled_hint' => 'Uitgeschakeld. Opnieuw activeren begint schoon: wat je plaatste terwijl het uit stond, blijft buiten beschouwing.',
    ],

    'items' => [
        'source' => 'Origineel',
        'published_at' => 'Geplaatst',
        'status' => 'Status',
        'detail' => 'Detail',
        'posts' => 'Gerepliceerd naar',
        'view_original' => 'Origineel bekijken',
        'open_post' => 'Post openen',
        'statuses' => [
            'pending' => 'In wachtrij',
            'processing' => 'Bezig',
            'published' => 'Gerepliceerd',
            'skipped' => 'Overgeslagen',
            'failed' => 'Mislukt',
        ],
        'reasons' => [
            'published_via_trypost' => 'Al gepubliceerd via TryPost',
            'not_video' => 'Geen video',
            'media_url_missing' => 'Het netwerk deelde geen downloadbaar bestand, meestal door auteursrechtelijk beschermde audio',
            'download_failed' => 'De video kon niet worden gedownload',
            'post_creation_failed' => 'Geen bestemming beschikbaar',
        ],
    ],

    'danger' => [
        'title' => 'Deze repurpose verwijderen',
        'description' => 'De controles stoppen onmiddellijk. Al gemaakte posts blijven in je kalender staan.',
        'delete' => 'Repurpose verwijderen',
    ],

    'errors' => [
        'source_already_used' => 'Dit account voedt al een andere repurpose. Bewerk die in plaats daarvan.',
        'destinations_required' => 'Kies minstens één bestemming voordat je activeert.',
        'destination_needs_video' => 'Dat formaat kan geen video bevatten.',
    ],
];

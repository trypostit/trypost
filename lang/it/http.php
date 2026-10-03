<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'Non siamo riusciti a raggiungere quel sito web (:reason).',
        'http_status' => 'Il sito web ha restituito uno stato inatteso (:status).',
        'invalid_scheme' => 'Sono supportati solo URL http e https.',
        'missing_host' => 'All\'URL manca un host.',
        'unresolvable_host' => 'Non siamo riusciti a risolvere l\'host (:host).',
        'private_network' => 'Gli URL che puntano a reti private non sono consentiti.',
    ],
];

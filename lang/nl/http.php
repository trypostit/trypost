<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'We konden die website niet bereiken (:reason).',
        'http_status' => 'De website gaf een onverwachte status terug (:status).',
        'invalid_scheme' => 'Alleen http- en https-URL\'s worden ondersteund.',
        'missing_host' => 'De URL mist een host.',
        'unresolvable_host' => 'We konden de host niet omzetten (:host).',
        'private_network' => 'URL\'s die naar privénetwerken verwijzen zijn niet toegestaan.',
    ],
];

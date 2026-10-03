<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'Δεν μπορέσαμε να προσπελάσουμε αυτόν τον ιστότοπο (:reason).',
        'http_status' => 'Ο ιστότοπος επέστρεψε μη αναμενόμενη κατάσταση (:status).',
        'invalid_scheme' => 'Υποστηρίζονται μόνο διευθύνσεις URL http και https.',
        'missing_host' => 'Από τη διεύθυνση URL λείπει ο host.',
        'unresolvable_host' => 'Δεν μπορέσαμε να επιλύσουμε τον host (:host).',
        'private_network' => 'Δεν επιτρέπονται διευθύνσεις URL που δείχνουν σε ιδιωτικά δίκτυα.',
    ],
];

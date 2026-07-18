<?php

declare(strict_types=1);

return [
    'title' => 'Workspaces',
    'select_title' => 'Τα workspaces σας',
    'select_description' => 'Επιλέξτε ένα workspace για να συνεχίσετε',
    'current' => 'Τρέχον',
    'connections' => ':count συνδέσεις',
    'posts' => ':count δημοσιεύσεις',

    'create' => [
        'page_title' => 'Δημιουργήστε το workspace σας',
        'title' => 'Ρυθμίστε το workspace σας',
        'description' => 'Πείτε μας λίγα πράγματα για εσάς ή το έργο σας. Θα τα χρησιμοποιήσουμε για να προσαρμόσουμε τις δημοσιεύσεις που δημιουργεί το AI στο ύφος σας.',
        'website' => 'Ιστότοπος',
        'website_placeholder' => 'https://yourbrand.com',
        'autofill' => 'Αυτόματη συμπλήρωση από ιστότοπο',
        'autofill_missing_url' => 'Εισάγετε πρώτα μια διεύθυνση URL.',
        'autofill_success' => 'Οι πληροφορίες μάρκας φορτώθηκαν.',
        'autofill_error' => 'Δεν ήταν δυνατή η αυτόματη συμπλήρωση. Μπορείτε να συμπληρώσετε τα πεδία χειροκίνητα.',
        'autofill_errors' => [
            'unreachable' => 'Δεν μπορέσαμε να προσπελάσουμε αυτόν τον ιστότοπο (:reason).',
            'http_status' => 'Ο ιστότοπος επέστρεψε μη αναμενόμενη κατάσταση (:status).',
            'invalid_scheme' => 'Υποστηρίζονται μόνο διευθύνσεις URL http και https.',
            'missing_host' => 'Από τη διεύθυνση URL λείπει ο host.',
            'unresolvable_host' => 'Δεν μπορέσαμε να επιλύσουμε τον host (:host).',
            'private_network' => 'Δεν επιτρέπονται διευθύνσεις URL που δείχνουν σε ιδιωτικά δίκτυα.',
        ],
        'logo_captured' => 'Το λογότυπο ελήφθη από τον ιστότοπό σας.',
        'name' => 'Όνομα workspace',
        'name_placeholder' => 'π.χ. Acme Inc',
        'brand_description' => 'Περιγραφή μάρκας',
        'brand_description_placeholder' => 'Τι κάνει η μάρκα σας;',
        'content_language' => 'Γλώσσα περιεχομένου',
        'content_language_description' => 'Οι λεζάντες που δημιουργεί το AI θα γράφονται σε αυτή τη γλώσσα.',
        'brand_color' => 'Χρώμα μάρκας',
        'background_color' => 'Χρώμα φόντου',
        'text_color' => 'Χρώμα κειμένου',
        'submit' => 'Δημιουργία workspace',
        'success' => 'Το workspace δημιουργήθηκε. Συνδέστε έναν λογαριασμό κοινωνικού δικτύου για να ξεκινήσετε να δημοσιεύετε.',
    ],

    'cannot_delete_last' => 'Δεν μπορείτε να διαγράψετε το μοναδικό σας workspace. Ακυρώστε τη συνδρομή σας στις ρυθμίσεις χρέωσης για να κλείσετε τον λογαριασμό σας.',

    'flash' => [
        'deleted' => 'Το workspace διαγράφηκε με επιτυχία.',
    ],
];

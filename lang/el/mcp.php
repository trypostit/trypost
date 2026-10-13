<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Συνδέστε βοηθούς AI στον χώρο σας.',
    'copy' => 'Αντιγραφή',
    'connect' => 'Σύνδεση :client',
    'name_label' => 'Όνομα',
    'url_label' => 'URL διακομιστή',
    'config_label' => 'Config',
    'tabs' => [
        'connected' => 'Συνδεδεμένες εφαρμογές',
        'connect' => 'Σύνδεση εφαρμογής',
    ],
    'connect_other' => 'Σύνδεση',
    'other_hint' => 'Οποιαδήποτε εφαρμογή υποστηρίζει MCP.',
    'other_dialog_title' => 'Συνδέστε το TryPost με άλλη εφαρμογή',
    'connected_description' => 'Βοηθοί στους οποίους έχετε συνδεθεί. Μπορείτε να αποσυνδέσετε όσους δεν χρησιμοποιείτε πλέον.',
    'connected_empty_title' => 'Καμία συνδεδεμένη εφαρμογή',
    'connected_empty' => 'Συνδέστε το Claude, το ChatGPT ή άλλη εφαρμογή για να τη δείτε εδώ.',
    'disconnect' => 'Αποσύνδεση',
    'disconnect_title' => 'Αποσύνδεση εφαρμογής',
    'disconnect_confirm' => 'Αυτό αποσυνδέει την εφαρμογή από το TryPost. Θα χρειαστεί να συνδεθεί ξανά πριν χρησιμοποιήσει το MCP.',
    'disconnected' => 'Η εφαρμογή αποσυνδέθηκε.',
    'copied' => 'Αντιγράφηκε',
    'last_used' => 'Τελευταία χρήση',
    'never' => 'Ποτέ',
    'connector_name' => 'TryPost',

    'authorize' => [
        'page_title' => 'Εξουσιοδότηση εφαρμογής - TryPost',
        'app_title' => 'Εξουσιοδότηση MCP',
        'heading' => 'Εξουσιοδότηση :client',
        'intro' => 'Αυτή η εφαρμογή θα μπορεί να:',
        'intro_capability' => 'Χρησιμοποιεί τις διαθέσιμες λειτουργίες MCP.',
        'logged_in_as' => 'Συνδεδεμένος ως:',
        'workspace' => 'Χώρος εργασίας:',
        'workspace_scope' => 'Αυτή η σύνδεση θα έχει πρόσβαση μόνο στο επιλεγμένο workspace.',
        'permissions' => 'Δικαιώματα:',
        'cancel' => 'Ακύρωση',
        'approve' => 'Εξουσιοδότηση',
        'approving' => 'Εξουσιοδότηση...',
        'select_workspace' => 'Επιλέξτε workspace',
        'search_workspace' => 'Αναζήτηση workspaces...',
        'no_workspace_found' => 'Δεν βρέθηκε workspace',
        'scope_mcp_use' => 'Χρήση του διακομιστή MCP',
        'error_page_title' => 'Αποτυχία εξουσιοδότησης - TryPost',
        'error_title' => 'Αδυναμία σύνδεσης',
        'error_body' => 'Αυτό το αίτημα εξουσιοδότησης δεν είναι έγκυρο ή έχει λήξει. Κλείστε αυτό το παράθυρο και δοκιμάστε ξανά από τον πελάτη MCP.',
        'error_code' => 'Σφάλμα: :error',
    ],

    'other_clients_title' => 'Άλλες εφαρμογές',

    'dialog' => [
        'title' => 'Συνδέστε το TryPost με το :client',
        'description' => 'Χρειάζεται περίπου ένα λεπτό. Η σελίδα θα ενημερωθεί μόλις συνδεθείτε.',
        'open' => 'Άνοιγμα του :client',
        'steps' => [
            'claude' => [
                'paste' => 'Επικολλήστε το URL στο πεδίο URL του connector',
                'add' => 'Πατήστε Προσθήκη και μετά Σύνδεση',
                'login' => 'Συνδεθείτε στο TryPost και επιλέξτε workspace',
            ],
            'chatgpt' => [
                'developer' => 'Ενεργοποιήστε τη λειτουργία προγραμματιστή στις ρυθμίσεις του ChatGPT',
                'create' => 'Δημιουργήστε μια εφαρμογή με όνομα TryPost και επικολλήστε το URL',
                'login' => 'Συνδεθείτε στο TryPost και επιλέξτε workspace',
            ],
            'other' => [
                'add' => 'Προσθέστε έναν προσαρμοσμένο διακομιστή MCP στην εφαρμογή σας',
                'paste' => 'Επικολλήστε το όνομα και το URL ή τις ρυθμίσεις',
                'login' => 'Συνδεθείτε στο TryPost και επιλέξτε workspace',
            ],
        ],
        'waiting' => 'Αναμονή για σύνδεση του AI σας…',
    ],

];

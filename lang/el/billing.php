<?php

return [
    'title' => 'Χρέωση',

    'past_due_notice' => [
        'title' => 'Ληξιπρόθεσμη πληρωμή',
        'description' => 'Ενημερώστε τη μέθοδο πληρωμής σας για να διατηρήσετε ενεργή τη συνδρομή σας.',
        'cta' => 'Ενημέρωση πληρωμής',
    ],

    'annual_banner' => [
        'title' => 'Κερδίστε 2 μήνες δωρεάν',
        'description' => 'Μεταβείτε σε ετήσια χρέωση και πληρώνετε λιγότερα κάθε μήνα — ίδιο πρόγραμμα, τίποτα άλλο δεν αλλάζει.',
        'cta' => 'Αναβάθμιση σε ετήσιο',
    ],

    'subscribe' => [
        'billed_monthly' => 'Μηνιαία χρέωση',
        'billed_yearly' => 'Ετήσια χρέωση',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => 'Πλάνα',
        'description' => 'Αναβαθμίστε ή υποβαθμίστε οποιαδήποτε στιγμή.',
        'monthly' => 'Μηνιαία',
        'yearly' => 'Ετήσια',
        'save_two_months' => '2 μήνες δωρεάν',
        'per_month' => '/μήνα',
        'workspaces_one' => '1 workspace',
        'workspaces_unlimited' => 'Απεριόριστα workspaces',
        'current' => 'Τρέχον πλάνο',
        'select' => 'Επιλέξτε :plan',
        'start_first_month' => 'Ξεκίνα τον πρώτο μήνα με :price',

        'billed_yearly_total' => 'Ετήσια χρέωση · :price (2 μήνες δωρεάν)',
        'socials_tagline' => 'Ιδανικό για creators και μικρά brands.',
        'workspaces_tagline' => 'Ιδανικό για agencies και μεγαλύτερες επιχειρήσεις.',
        'everything_included' => 'Όλα περιλαμβάνονται',
        'features' => [
            'networks_all' => 'Όλα τα κοινωνικά δίκτυα περιλαμβάνονται',
            'networks_all_tooltip' => 'Συνδέστε οποιοδήποτε — όλα περιλαμβάνονται.',
            'accounts_unlimited' => 'Απεριόριστοι λογαριασμοί social',
            'calendar' => 'Οπτικό ημερολόγιο με αυτόματη δημοσίευση',
            'ai' => 'AI: λεζάντες, εικόνες και φωνή brand',
            'mcp' => 'MCP: δημοσίευση από Claude, ChatGPT ή Grok',
            'repurpose' => 'Repurpose: ένα post γίνεται πολλά',
            'analytics' => 'Analytics ανά ανάρτηση και λογαριασμό',
            'team' => 'Απεριόριστη ομάδα, ρόλοι και εγκρίσεις',
        ],
    ],

    'plan' => [
        'title' => 'Πρόγραμμα',
        'description' => 'Διαχειριστείτε το πρόγραμμα συνδρομής σας.',
        'label' => 'Πρόγραμμα',
        'price' => 'Τιμή',
        'month' => 'μήνας',
        'trial' => 'Δοκιμαστική περίοδος',
        'active' => 'Ενεργό',
        'past_due' => 'Ληξιπρόθεσμο',
        'cancelling' => 'Ακυρώνεται',
        'trial_ends' => 'Η δοκιμαστική περίοδος λήγει',
    ],

    'subscription' => [
        'title' => 'Συνδρομή',
        'description' => 'Διαχειριστείτε τη μέθοδο πληρωμής, τα στοιχεία χρέωσης και τη συνδρομή σας.',
        'payment_method' => 'Μέθοδος πληρωμής',
        'no_payment_method' => 'Δεν υπάρχει καταχωρημένη μέθοδος πληρωμής ακόμη.',
        'expires_on' => 'Λήγει :month/:year',
        'manage_label' => 'Συνδρομή',
        'manage_stripe' => 'Διαχείριση στο Stripe',
    ],

    'invoices' => [
        'title' => 'Τιμολόγια',
        'description' => 'Κατεβάστε τα προηγούμενα τιμολόγιά σας.',
        'empty' => 'Δεν βρέθηκαν τιμολόγια',
        'paid' => 'Πληρωμένο',
    ],

    'flash' => [
        'plan_changed' => 'Είστε πλέον στο πρόγραμμα :plan.',
        'switched_to_yearly' => 'Είστε πλέον σε ετήσια χρέωση.',
        'cannot_manage' => 'Μόνο ο κάτοχος του λογαριασμού μπορεί να διαχειρίζεται τη χρέωση.',
        'too_many_workspaces' => 'Έχετε :count workspaces. Αυτό το πλάνο περιλαμβάνει :limit — διαγράψτε τα επιπλέον πριν αλλάξετε.',
        'subscription_required' => 'Απαιτείται ενεργή συνδρομή για τη χρήση των λειτουργιών AI.',
    ],

    'processing' => [
        'page_title' => 'Επεξεργασία...',
        'title' => 'Επεξεργασία της συνδρομής σας',
        'description' => 'Παρακαλούμε περιμένετε όσο ρυθμίζουμε τον λογαριασμό σας. Θα πάρει μόνο μια στιγμή.',
        'success_title' => 'Είστε έτοιμοι!',
        'success_description' => 'Η συνδρομή σας είναι ενεργή. Σας ανακατευθύνουμε στα workspaces σας...',
        'cancelled_title' => 'Η πληρωμή ακυρώθηκε',
        'cancelled_description' => 'Η πληρωμή σας ακυρώθηκε. Δεν έγινε καμία χρέωση.',
        'retry' => 'Δοκιμάστε ξανά',
    ],
];

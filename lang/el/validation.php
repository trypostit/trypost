<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'Το πεδίο :attribute πρέπει να γίνει αποδεκτό.',
    'accepted_if' => 'Το πεδίο :attribute πρέπει να γίνει αποδεκτό όταν το :other είναι :value.',
    'active_url' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση URL.',
    'after' => 'Το πεδίο :attribute πρέπει να είναι ημερομηνία μετά τις :date.',
    'after_or_equal' => 'Το πεδίο :attribute πρέπει να είναι ημερομηνία μετά ή ίση με τις :date.',
    'alpha' => 'Το πεδίο :attribute πρέπει να περιέχει μόνο γράμματα.',
    'alpha_dash' => 'Το πεδίο :attribute πρέπει να περιέχει μόνο γράμματα, αριθμούς, παύλες και κάτω παύλες.',
    'alpha_num' => 'Το πεδίο :attribute πρέπει να περιέχει μόνο γράμματα και αριθμούς.',
    'any_of' => 'Το πεδίο :attribute δεν είναι έγκυρο.',
    'array' => 'Το πεδίο :attribute πρέπει να είναι πίνακας.',
    'ascii' => 'Το πεδίο :attribute πρέπει να περιέχει μόνο αλφαριθμητικούς χαρακτήρες και σύμβολα ενός byte.',
    'before' => 'Το πεδίο :attribute πρέπει να είναι ημερομηνία πριν τις :date.',
    'before_or_equal' => 'Το πεδίο :attribute πρέπει να είναι ημερομηνία πριν ή ίση με τις :date.',
    'between' => [
        'array' => 'Το πεδίο :attribute πρέπει να έχει μεταξύ :min και :max στοιχεία.',
        'file' => 'Το πεδίο :attribute πρέπει να είναι μεταξύ :min και :max kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι μεταξύ :min και :max.',
        'string' => 'Το πεδίο :attribute πρέπει να είναι μεταξύ :min και :max χαρακτήρων.',
    ],
    'boolean' => 'Το πεδίο :attribute πρέπει να είναι αληθές ή ψευδές.',
    'can' => 'Το πεδίο :attribute περιέχει μη εξουσιοδοτημένη τιμή.',
    'confirmed' => 'Η επιβεβαίωση του πεδίου :attribute δεν ταιριάζει.',
    'contains' => 'Από το πεδίο :attribute λείπει μια απαιτούμενη τιμή.',
    'current_password' => 'Ο κωδικός πρόσβασης είναι εσφαλμένος.',
    'date' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη ημερομηνία.',
    'date_equals' => 'Το πεδίο :attribute πρέπει να είναι ημερομηνία ίση με τις :date.',
    'date_format' => 'Το πεδίο :attribute πρέπει να ταιριάζει με τη μορφή :format.',
    'decimal' => 'Το πεδίο :attribute πρέπει να έχει :decimal δεκαδικά ψηφία.',
    'declined' => 'Το πεδίο :attribute πρέπει να απορριφθεί.',
    'declined_if' => 'Το πεδίο :attribute πρέπει να απορριφθεί όταν το :other είναι :value.',
    'different' => 'Τα πεδία :attribute και :other πρέπει να είναι διαφορετικά.',
    'digits' => 'Το πεδίο :attribute πρέπει να έχει :digits ψηφία.',
    'digits_between' => 'Το πεδίο :attribute πρέπει να έχει μεταξύ :min και :max ψηφία.',
    'dimensions' => 'Το πεδίο :attribute έχει μη έγκυρες διαστάσεις εικόνας.',
    'distinct' => 'Το πεδίο :attribute έχει διπλότυπη τιμή.',
    'doesnt_contain' => 'Το πεδίο :attribute δεν πρέπει να περιέχει κανένα από τα ακόλουθα: :values.',
    'doesnt_end_with' => 'Το πεδίο :attribute δεν πρέπει να τελειώνει με ένα από τα ακόλουθα: :values.',
    'doesnt_start_with' => 'Το πεδίο :attribute δεν πρέπει να ξεκινά με ένα από τα ακόλουθα: :values.',
    'email' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση email.',
    'encoding' => 'Το πεδίο :attribute πρέπει να είναι κωδικοποιημένο σε :encoding.',
    'ends_with' => 'Το πεδίο :attribute πρέπει να τελειώνει με ένα από τα ακόλουθα: :values.',
    'enum' => 'Η επιλεγμένη τιμή :attribute δεν είναι έγκυρη.',
    'exists' => 'Η επιλεγμένη τιμή :attribute δεν είναι έγκυρη.',
    'extensions' => 'Το πεδίο :attribute πρέπει να έχει μία από τις ακόλουθες επεκτάσεις: :values.',
    'file' => 'Το πεδίο :attribute πρέπει να είναι αρχείο.',
    'filled' => 'Το πεδίο :attribute πρέπει να έχει τιμή.',
    'gt' => [
        'array' => 'Το πεδίο :attribute πρέπει να έχει περισσότερα από :value στοιχεία.',
        'file' => 'Το πεδίο :attribute πρέπει να είναι μεγαλύτερο από :value kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι μεγαλύτερο από :value.',
        'string' => 'Το πεδίο :attribute πρέπει να είναι μεγαλύτερο από :value χαρακτήρες.',
    ],
    'gte' => [
        'array' => 'Το πεδίο :attribute πρέπει να έχει :value στοιχεία ή περισσότερα.',
        'file' => 'Το πεδίο :attribute πρέπει να είναι μεγαλύτερο ή ίσο με :value kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι μεγαλύτερο ή ίσο με :value.',
        'string' => 'Το πεδίο :attribute πρέπει να είναι μεγαλύτερο ή ίσο με :value χαρακτήρες.',
    ],
    'hex_color' => 'Το πεδίο :attribute πρέπει να είναι έγκυρο δεκαεξαδικό χρώμα.',
    'image' => 'Το πεδίο :attribute πρέπει να είναι εικόνα.',
    'in' => 'Η επιλεγμένη τιμή :attribute δεν είναι έγκυρη.',
    'in_array' => 'Το πεδίο :attribute πρέπει να υπάρχει στο :other.',
    'in_array_keys' => 'Το πεδίο :attribute πρέπει να περιέχει τουλάχιστον ένα από τα ακόλουθα κλειδιά: :values.',
    'integer' => 'Το πεδίο :attribute πρέπει να είναι ακέραιος.',
    'ip' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση IP.',
    'ipv4' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση IPv4.',
    'ipv6' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση IPv6.',
    'json' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη συμβολοσειρά JSON.',
    'list' => 'Το πεδίο :attribute πρέπει να είναι λίστα.',
    'lowercase' => 'Το πεδίο :attribute πρέπει να είναι με πεζά γράμματα.',
    'lt' => [
        'array' => 'Το πεδίο :attribute πρέπει να έχει λιγότερα από :value στοιχεία.',
        'file' => 'Το πεδίο :attribute πρέπει να είναι μικρότερο από :value kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι μικρότερο από :value.',
        'string' => 'Το πεδίο :attribute πρέπει να είναι μικρότερο από :value χαρακτήρες.',
    ],
    'lte' => [
        'array' => 'Το πεδίο :attribute δεν πρέπει να έχει περισσότερα από :value στοιχεία.',
        'file' => 'Το πεδίο :attribute πρέπει να είναι μικρότερο ή ίσο με :value kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι μικρότερο ή ίσο με :value.',
        'string' => 'Το πεδίο :attribute πρέπει να είναι μικρότερο ή ίσο με :value χαρακτήρες.',
    ],
    'mac_address' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση MAC.',
    'max' => [
        'array' => 'Το πεδίο :attribute δεν πρέπει να έχει περισσότερα από :max στοιχεία.',
        'file' => 'Το πεδίο :attribute δεν πρέπει να είναι μεγαλύτερο από :max kilobytes.',
        'numeric' => 'Το πεδίο :attribute δεν πρέπει να είναι μεγαλύτερο από :max.',
        'string' => 'Το πεδίο :attribute δεν πρέπει να είναι μεγαλύτερο από :max χαρακτήρες.',
    ],
    'max_digits' => 'Το πεδίο :attribute δεν πρέπει να έχει περισσότερα από :max ψηφία.',
    'mimes' => 'Το πεδίο :attribute πρέπει να είναι αρχείο τύπου: :values.',
    'mimetypes' => 'Το πεδίο :attribute πρέπει να είναι αρχείο τύπου: :values.',
    'min' => [
        'array' => 'Το πεδίο :attribute πρέπει να έχει τουλάχιστον :min στοιχεία.',
        'file' => 'Το πεδίο :attribute πρέπει να είναι τουλάχιστον :min kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι τουλάχιστον :min.',
        'string' => 'Το πεδίο :attribute πρέπει να είναι τουλάχιστον :min χαρακτήρες.',
    ],
    'min_digits' => 'Το πεδίο :attribute πρέπει να έχει τουλάχιστον :min ψηφία.',
    'missing' => 'Το πεδίο :attribute πρέπει να λείπει.',
    'missing_if' => 'Το πεδίο :attribute πρέπει να λείπει όταν το :other είναι :value.',
    'missing_unless' => 'Το πεδίο :attribute πρέπει να λείπει εκτός αν το :other είναι :value.',
    'missing_with' => 'Το πεδίο :attribute πρέπει να λείπει όταν υπάρχει το :values.',
    'missing_with_all' => 'Το πεδίο :attribute πρέπει να λείπει όταν υπάρχουν τα :values.',
    'multiple_of' => 'Το πεδίο :attribute πρέπει να είναι πολλαπλάσιο του :value.',
    'not_in' => 'Η επιλεγμένη τιμή :attribute δεν είναι έγκυρη.',
    'not_regex' => 'Η μορφή του πεδίου :attribute δεν είναι έγκυρη.',
    'numeric' => 'Το πεδίο :attribute πρέπει να είναι αριθμός.',
    'password' => [
        'letters' => 'Το πεδίο :attribute πρέπει να περιέχει τουλάχιστον ένα γράμμα.',
        'mixed' => 'Το πεδίο :attribute πρέπει να περιέχει τουλάχιστον ένα κεφαλαίο και ένα πεζό γράμμα.',
        'numbers' => 'Το πεδίο :attribute πρέπει να περιέχει τουλάχιστον έναν αριθμό.',
        'symbols' => 'Το πεδίο :attribute πρέπει να περιέχει τουλάχιστον ένα σύμβολο.',
        'uncompromised' => 'Το δοθέν :attribute έχει εμφανιστεί σε διαρροή δεδομένων. Παρακαλούμε επιλέξτε διαφορετικό :attribute.',
    ],
    'present' => 'Το πεδίο :attribute πρέπει να υπάρχει.',
    'present_if' => 'Το πεδίο :attribute πρέπει να υπάρχει όταν το :other είναι :value.',
    'present_unless' => 'Το πεδίο :attribute πρέπει να υπάρχει εκτός αν το :other είναι :value.',
    'present_with' => 'Το πεδίο :attribute πρέπει να υπάρχει όταν υπάρχει το :values.',
    'present_with_all' => 'Το πεδίο :attribute πρέπει να υπάρχει όταν υπάρχουν τα :values.',
    'prohibited' => 'Το πεδίο :attribute απαγορεύεται.',
    'prohibited_if' => 'Το πεδίο :attribute απαγορεύεται όταν το :other είναι :value.',
    'prohibited_if_accepted' => 'Το πεδίο :attribute απαγορεύεται όταν το :other γίνεται αποδεκτό.',
    'prohibited_if_declined' => 'Το πεδίο :attribute απαγορεύεται όταν το :other απορρίπτεται.',
    'prohibited_unless' => 'Το πεδίο :attribute απαγορεύεται εκτός αν το :other είναι στο :values.',
    'prohibits' => 'Το πεδίο :attribute απαγορεύει την ύπαρξη του :other.',
    'regex' => 'Η μορφή του πεδίου :attribute δεν είναι έγκυρη.',
    'required' => 'Το πεδίο :attribute είναι υποχρεωτικό.',
    'required_array_keys' => 'Το πεδίο :attribute πρέπει να περιέχει καταχωρίσεις για: :values.',
    'required_if' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν το :other είναι :value.',
    'required_if_accepted' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν το :other γίνεται αποδεκτό.',
    'required_if_declined' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν το :other απορρίπτεται.',
    'required_unless' => 'Το πεδίο :attribute είναι υποχρεωτικό εκτός αν το :other είναι στο :values.',
    'required_with' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν υπάρχει το :values.',
    'required_with_all' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν υπάρχουν τα :values.',
    'required_without' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν δεν υπάρχει το :values.',
    'required_without_all' => 'Το πεδίο :attribute είναι υποχρεωτικό όταν δεν υπάρχει κανένα από τα :values.',
    'same' => 'Το πεδίο :attribute πρέπει να ταιριάζει με το :other.',
    'size' => [
        'array' => 'Το πεδίο :attribute πρέπει να περιέχει :size στοιχεία.',
        'file' => 'Το πεδίο :attribute πρέπει να είναι :size kilobytes.',
        'numeric' => 'Το πεδίο :attribute πρέπει να είναι :size.',
        'string' => 'Το πεδίο :attribute πρέπει να είναι :size χαρακτήρες.',
    ],
    'starts_with' => 'Το πεδίο :attribute πρέπει να ξεκινά με ένα από τα ακόλουθα: :values.',
    'string' => 'Το πεδίο :attribute πρέπει να είναι συμβολοσειρά.',
    'timezone' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη ζώνη ώρας.',
    'unique' => 'Το :attribute χρησιμοποιείται ήδη.',
    'uploaded' => 'Η μεταφόρτωση του :attribute απέτυχε.',
    'uppercase' => 'Το πεδίο :attribute πρέπει να είναι με κεφαλαία γράμματα.',
    'url' => 'Το πεδίο :attribute πρέπει να είναι έγκυρη διεύθυνση URL.',
    'ulid' => 'Το πεδίο :attribute πρέπει να είναι έγκυρο ULID.',
    'uuid' => 'Το πεδίο :attribute πρέπει να είναι έγκυρο UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],

];

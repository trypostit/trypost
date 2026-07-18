<?php

declare(strict_types=1);

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

    'accepted' => ':attribute muss akzeptiert werden.',
    'accepted_if' => ':attribute muss akzeptiert werden, wenn :other :value ist.',
    'active_url' => ':attribute muss eine gültige URL sein.',
    'after' => ':attribute muss ein Datum nach :date sein.',
    'after_or_equal' => ':attribute muss ein Datum nach oder gleich :date sein.',
    'alpha' => ':attribute darf nur Buchstaben enthalten.',
    'alpha_dash' => ':attribute darf nur Buchstaben, Zahlen, Bindestriche und Unterstriche enthalten.',
    'alpha_num' => ':attribute darf nur Buchstaben und Zahlen enthalten.',
    'any_of' => ':attribute ist ungültig.',
    'array' => ':attribute muss ein Array sein.',
    'ascii' => ':attribute darf nur alphanumerische Ein-Byte-Zeichen und Symbole enthalten.',
    'before' => ':attribute muss ein Datum vor :date sein.',
    'before_or_equal' => ':attribute muss ein Datum vor oder gleich :date sein.',
    'between' => [
        'array' => ':attribute muss zwischen :min und :max Elemente haben.',
        'file' => ':attribute muss zwischen :min und :max Kilobyte groß sein.',
        'numeric' => ':attribute muss zwischen :min und :max liegen.',
        'string' => ':attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => ':attribute muss wahr oder falsch sein.',
    'can' => ':attribute enthält einen unzulässigen Wert.',
    'confirmed' => 'Die Bestätigung von :attribute stimmt nicht überein.',
    'contains' => ':attribute fehlt ein erforderlicher Wert.',
    'current_password' => 'Das Passwort ist falsch.',
    'date' => ':attribute muss ein gültiges Datum sein.',
    'date_equals' => ':attribute muss ein Datum gleich :date sein.',
    'date_format' => ':attribute muss dem Format :format entsprechen.',
    'decimal' => ':attribute muss :decimal Dezimalstellen haben.',
    'declined' => ':attribute muss abgelehnt werden.',
    'declined_if' => ':attribute muss abgelehnt werden, wenn :other :value ist.',
    'different' => ':attribute und :other müssen unterschiedlich sein.',
    'digits' => ':attribute muss :digits Ziffern lang sein.',
    'digits_between' => ':attribute muss zwischen :min und :max Ziffern lang sein.',
    'dimensions' => ':attribute hat ungültige Bildabmessungen.',
    'distinct' => ':attribute hat einen doppelten Wert.',
    'doesnt_contain' => ':attribute darf keinen der folgenden Werte enthalten: :values.',
    'doesnt_end_with' => ':attribute darf nicht mit einem der folgenden Werte enden: :values.',
    'doesnt_start_with' => ':attribute darf nicht mit einem der folgenden Werte beginnen: :values.',
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'encoding' => ':attribute muss in :encoding kodiert sein.',
    'ends_with' => ':attribute muss mit einem der folgenden Werte enden: :values.',
    'enum' => 'Das ausgewählte :attribute ist ungültig.',
    'exists' => 'Das ausgewählte :attribute ist ungültig.',
    'extensions' => ':attribute muss eine der folgenden Dateiendungen haben: :values.',
    'file' => ':attribute muss eine Datei sein.',
    'filled' => ':attribute muss einen Wert haben.',
    'gt' => [
        'array' => ':attribute muss mehr als :value Elemente haben.',
        'file' => ':attribute muss größer als :value Kilobyte sein.',
        'numeric' => ':attribute muss größer als :value sein.',
        'string' => ':attribute muss länger als :value Zeichen sein.',
    ],
    'gte' => [
        'array' => ':attribute muss :value oder mehr Elemente haben.',
        'file' => ':attribute muss größer oder gleich :value Kilobyte sein.',
        'numeric' => ':attribute muss größer oder gleich :value sein.',
        'string' => ':attribute muss mindestens :value Zeichen lang sein.',
    ],
    'hex_color' => ':attribute muss eine gültige Hexadezimalfarbe sein.',
    'image' => ':attribute muss ein Bild sein.',
    'in' => 'Das ausgewählte :attribute ist ungültig.',
    'in_array' => ':attribute muss in :other vorhanden sein.',
    'in_array_keys' => ':attribute muss mindestens einen der folgenden Schlüssel enthalten: :values.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'ip' => ':attribute muss eine gültige IP-Adresse sein.',
    'ipv4' => ':attribute muss eine gültige IPv4-Adresse sein.',
    'ipv6' => ':attribute muss eine gültige IPv6-Adresse sein.',
    'json' => ':attribute muss eine gültige JSON-Zeichenkette sein.',
    'list' => ':attribute muss eine Liste sein.',
    'lowercase' => ':attribute muss in Kleinbuchstaben geschrieben sein.',
    'lt' => [
        'array' => ':attribute muss weniger als :value Elemente haben.',
        'file' => ':attribute muss kleiner als :value Kilobyte sein.',
        'numeric' => ':attribute muss kleiner als :value sein.',
        'string' => ':attribute muss kürzer als :value Zeichen sein.',
    ],
    'lte' => [
        'array' => ':attribute darf nicht mehr als :value Elemente haben.',
        'file' => ':attribute muss kleiner oder gleich :value Kilobyte sein.',
        'numeric' => ':attribute muss kleiner oder gleich :value sein.',
        'string' => ':attribute darf höchstens :value Zeichen lang sein.',
    ],
    'mac_address' => ':attribute muss eine gültige MAC-Adresse sein.',
    'max' => [
        'array' => ':attribute darf nicht mehr als :max Elemente haben.',
        'file' => ':attribute darf nicht größer als :max Kilobyte sein.',
        'numeric' => ':attribute darf nicht größer als :max sein.',
        'string' => ':attribute darf nicht länger als :max Zeichen sein.',
    ],
    'max_digits' => ':attribute darf nicht mehr als :max Ziffern haben.',
    'mimes' => ':attribute muss eine Datei des folgenden Typs sein: :values.',
    'mimetypes' => ':attribute muss eine Datei des folgenden Typs sein: :values.',
    'min' => [
        'array' => ':attribute muss mindestens :min Elemente haben.',
        'file' => ':attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'min_digits' => ':attribute muss mindestens :min Ziffern haben.',
    'missing' => ':attribute muss fehlen.',
    'missing_if' => ':attribute muss fehlen, wenn :other :value ist.',
    'missing_unless' => ':attribute muss fehlen, es sei denn :other ist :value.',
    'missing_with' => ':attribute muss fehlen, wenn :values vorhanden ist.',
    'missing_with_all' => ':attribute muss fehlen, wenn :values vorhanden sind.',
    'multiple_of' => ':attribute muss ein Vielfaches von :value sein.',
    'not_in' => 'Das ausgewählte :attribute ist ungültig.',
    'not_regex' => 'Das Format von :attribute ist ungültig.',
    'numeric' => ':attribute muss eine Zahl sein.',
    'password' => [
        'letters' => ':attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':attribute muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => ':attribute muss mindestens eine Zahl enthalten.',
        'symbols' => ':attribute muss mindestens ein Symbol enthalten.',
        'uncompromised' => 'Das angegebene :attribute ist in einem Datenleck aufgetaucht. Bitte wähle ein anderes :attribute.',
    ],
    'present' => ':attribute muss vorhanden sein.',
    'present_if' => ':attribute muss vorhanden sein, wenn :other :value ist.',
    'present_unless' => ':attribute muss vorhanden sein, es sei denn :other ist :value.',
    'present_with' => ':attribute muss vorhanden sein, wenn :values vorhanden ist.',
    'present_with_all' => ':attribute muss vorhanden sein, wenn :values vorhanden sind.',
    'prohibited' => ':attribute ist unzulässig.',
    'prohibited_if' => ':attribute ist unzulässig, wenn :other :value ist.',
    'prohibited_if_accepted' => ':attribute ist unzulässig, wenn :other akzeptiert wurde.',
    'prohibited_if_declined' => ':attribute ist unzulässig, wenn :other abgelehnt wurde.',
    'prohibited_unless' => ':attribute ist unzulässig, es sei denn :other ist in :values enthalten.',
    'prohibits' => ':attribute verhindert, dass :other vorhanden ist.',
    'regex' => 'Das Format von :attribute ist ungültig.',
    'required' => ':attribute muss ausgefüllt werden.',
    'required_array_keys' => ':attribute muss Einträge für Folgendes enthalten: :values.',
    'required_if' => ':attribute muss ausgefüllt werden, wenn :other :value ist.',
    'required_if_accepted' => ':attribute muss ausgefüllt werden, wenn :other akzeptiert wurde.',
    'required_if_declined' => ':attribute muss ausgefüllt werden, wenn :other abgelehnt wurde.',
    'required_unless' => ':attribute muss ausgefüllt werden, es sei denn :other ist in :values enthalten.',
    'required_with' => ':attribute muss ausgefüllt werden, wenn :values vorhanden ist.',
    'required_with_all' => ':attribute muss ausgefüllt werden, wenn :values vorhanden sind.',
    'required_without' => ':attribute muss ausgefüllt werden, wenn :values nicht vorhanden ist.',
    'required_without_all' => ':attribute muss ausgefüllt werden, wenn keiner der Werte :values vorhanden ist.',
    'same' => ':attribute muss mit :other übereinstimmen.',
    'size' => [
        'array' => ':attribute muss :size Elemente enthalten.',
        'file' => ':attribute muss :size Kilobyte groß sein.',
        'numeric' => ':attribute muss :size sein.',
        'string' => ':attribute muss :size Zeichen lang sein.',
    ],
    'starts_with' => ':attribute muss mit einem der folgenden Werte beginnen: :values.',
    'string' => ':attribute muss eine Zeichenkette sein.',
    'timezone' => ':attribute muss eine gültige Zeitzone sein.',
    'unique' => ':attribute ist bereits vergeben.',
    'uploaded' => ':attribute konnte nicht hochgeladen werden.',
    'uppercase' => ':attribute muss in Großbuchstaben geschrieben sein.',
    'url' => ':attribute muss eine gültige URL sein.',
    'ulid' => ':attribute muss eine gültige ULID sein.',
    'uuid' => ':attribute muss eine gültige UUID sein.',

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

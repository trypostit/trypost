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

    'accepted' => 'Het veld :attribute moet worden geaccepteerd.',
    'accepted_if' => 'Het veld :attribute moet worden geaccepteerd wanneer :other :value is.',
    'active_url' => 'Het veld :attribute moet een geldige URL zijn.',
    'after' => 'Het veld :attribute moet een datum na :date zijn.',
    'after_or_equal' => 'Het veld :attribute moet een datum op of na :date zijn.',
    'alpha' => 'Het veld :attribute mag alleen letters bevatten.',
    'alpha_dash' => 'Het veld :attribute mag alleen letters, cijfers, streepjes en underscores bevatten.',
    'alpha_num' => 'Het veld :attribute mag alleen letters en cijfers bevatten.',
    'any_of' => 'Het veld :attribute is ongeldig.',
    'array' => 'Het veld :attribute moet een array zijn.',
    'ascii' => 'Het veld :attribute mag alleen alfanumerieke tekens en symbolen van één byte bevatten.',
    'before' => 'Het veld :attribute moet een datum vóór :date zijn.',
    'before_or_equal' => 'Het veld :attribute moet een datum op of vóór :date zijn.',
    'between' => [
        'array' => 'Het veld :attribute moet tussen :min en :max items bevatten.',
        'file' => 'Het veld :attribute moet tussen :min en :max kilobytes zijn.',
        'numeric' => 'Het veld :attribute moet tussen :min en :max liggen.',
        'string' => 'Het veld :attribute moet tussen :min en :max tekens bevatten.',
    ],
    'boolean' => 'Het veld :attribute moet waar of onwaar zijn.',
    'can' => 'Het veld :attribute bevat een niet-toegestane waarde.',
    'confirmed' => 'De bevestiging van het veld :attribute komt niet overeen.',
    'contains' => 'Het veld :attribute mist een vereiste waarde.',
    'current_password' => 'Het wachtwoord is onjuist.',
    'date' => 'Het veld :attribute moet een geldige datum zijn.',
    'date_equals' => 'Het veld :attribute moet een datum gelijk aan :date zijn.',
    'date_format' => 'Het veld :attribute moet overeenkomen met het formaat :format.',
    'decimal' => 'Het veld :attribute moet :decimal decimalen bevatten.',
    'declined' => 'Het veld :attribute moet worden geweigerd.',
    'declined_if' => 'Het veld :attribute moet worden geweigerd wanneer :other :value is.',
    'different' => 'De velden :attribute en :other moeten verschillend zijn.',
    'digits' => 'Het veld :attribute moet :digits cijfers bevatten.',
    'digits_between' => 'Het veld :attribute moet tussen :min en :max cijfers bevatten.',
    'dimensions' => 'Het veld :attribute heeft ongeldige afbeeldingsafmetingen.',
    'distinct' => 'Het veld :attribute heeft een dubbele waarde.',
    'doesnt_contain' => 'Het veld :attribute mag geen van de volgende bevatten: :values.',
    'doesnt_end_with' => 'Het veld :attribute mag niet eindigen met een van de volgende: :values.',
    'doesnt_start_with' => 'Het veld :attribute mag niet beginnen met een van de volgende: :values.',
    'email' => 'Het veld :attribute moet een geldig e-mailadres zijn.',
    'encoding' => 'Het veld :attribute moet gecodeerd zijn in :encoding.',
    'ends_with' => 'Het veld :attribute moet eindigen met een van de volgende: :values.',
    'enum' => 'De geselecteerde :attribute is ongeldig.',
    'exists' => 'De geselecteerde :attribute is ongeldig.',
    'extensions' => 'Het veld :attribute moet een van de volgende extensies hebben: :values.',
    'file' => 'Het veld :attribute moet een bestand zijn.',
    'filled' => 'Het veld :attribute moet een waarde hebben.',
    'gt' => [
        'array' => 'Het veld :attribute moet meer dan :value items bevatten.',
        'file' => 'Het veld :attribute moet groter zijn dan :value kilobytes.',
        'numeric' => 'Het veld :attribute moet groter zijn dan :value.',
        'string' => 'Het veld :attribute moet meer dan :value tekens bevatten.',
    ],
    'gte' => [
        'array' => 'Het veld :attribute moet :value items of meer bevatten.',
        'file' => 'Het veld :attribute moet groter dan of gelijk aan :value kilobytes zijn.',
        'numeric' => 'Het veld :attribute moet groter dan of gelijk aan :value zijn.',
        'string' => 'Het veld :attribute moet :value tekens of meer bevatten.',
    ],
    'hex_color' => 'Het veld :attribute moet een geldige hexadecimale kleur zijn.',
    'image' => 'Het veld :attribute moet een afbeelding zijn.',
    'in' => 'De geselecteerde :attribute is ongeldig.',
    'in_array' => 'Het veld :attribute moet in :other voorkomen.',
    'in_array_keys' => 'Het veld :attribute moet ten minste een van de volgende sleutels bevatten: :values.',
    'integer' => 'Het veld :attribute moet een geheel getal zijn.',
    'ip' => 'Het veld :attribute moet een geldig IP-adres zijn.',
    'ipv4' => 'Het veld :attribute moet een geldig IPv4-adres zijn.',
    'ipv6' => 'Het veld :attribute moet een geldig IPv6-adres zijn.',
    'json' => 'Het veld :attribute moet een geldige JSON-string zijn.',
    'list' => 'Het veld :attribute moet een lijst zijn.',
    'lowercase' => 'Het veld :attribute moet in kleine letters zijn.',
    'lt' => [
        'array' => 'Het veld :attribute moet minder dan :value items bevatten.',
        'file' => 'Het veld :attribute moet kleiner zijn dan :value kilobytes.',
        'numeric' => 'Het veld :attribute moet kleiner zijn dan :value.',
        'string' => 'Het veld :attribute moet minder dan :value tekens bevatten.',
    ],
    'lte' => [
        'array' => 'Het veld :attribute mag niet meer dan :value items bevatten.',
        'file' => 'Het veld :attribute moet kleiner dan of gelijk aan :value kilobytes zijn.',
        'numeric' => 'Het veld :attribute moet kleiner dan of gelijk aan :value zijn.',
        'string' => 'Het veld :attribute moet :value tekens of minder bevatten.',
    ],
    'mac_address' => 'Het veld :attribute moet een geldig MAC-adres zijn.',
    'max' => [
        'array' => 'Het veld :attribute mag niet meer dan :max items bevatten.',
        'file' => 'Het veld :attribute mag niet groter zijn dan :max kilobytes.',
        'numeric' => 'Het veld :attribute mag niet groter zijn dan :max.',
        'string' => 'Het veld :attribute mag niet meer dan :max tekens bevatten.',
    ],
    'max_digits' => 'Het veld :attribute mag niet meer dan :max cijfers bevatten.',
    'mimes' => 'Het veld :attribute moet een bestand van het type :values zijn.',
    'mimetypes' => 'Het veld :attribute moet een bestand van het type :values zijn.',
    'min' => [
        'array' => 'Het veld :attribute moet ten minste :min items bevatten.',
        'file' => 'Het veld :attribute moet ten minste :min kilobytes zijn.',
        'numeric' => 'Het veld :attribute moet ten minste :min zijn.',
        'string' => 'Het veld :attribute moet ten minste :min tekens bevatten.',
    ],
    'min_digits' => 'Het veld :attribute moet ten minste :min cijfers bevatten.',
    'missing' => 'Het veld :attribute moet ontbreken.',
    'missing_if' => 'Het veld :attribute moet ontbreken wanneer :other :value is.',
    'missing_unless' => 'Het veld :attribute moet ontbreken tenzij :other :value is.',
    'missing_with' => 'Het veld :attribute moet ontbreken wanneer :values aanwezig is.',
    'missing_with_all' => 'Het veld :attribute moet ontbreken wanneer :values aanwezig zijn.',
    'multiple_of' => 'Het veld :attribute moet een veelvoud van :value zijn.',
    'not_in' => 'De geselecteerde :attribute is ongeldig.',
    'not_regex' => 'Het formaat van het veld :attribute is ongeldig.',
    'numeric' => 'Het veld :attribute moet een getal zijn.',
    'password' => [
        'letters' => 'Het veld :attribute moet ten minste één letter bevatten.',
        'mixed' => 'Het veld :attribute moet ten minste één hoofdletter en één kleine letter bevatten.',
        'numbers' => 'Het veld :attribute moet ten minste één cijfer bevatten.',
        'symbols' => 'Het veld :attribute moet ten minste één symbool bevatten.',
        'uncompromised' => 'De opgegeven :attribute is verschenen in een datalek. Kies een andere :attribute.',
    ],
    'present' => 'Het veld :attribute moet aanwezig zijn.',
    'present_if' => 'Het veld :attribute moet aanwezig zijn wanneer :other :value is.',
    'present_unless' => 'Het veld :attribute moet aanwezig zijn tenzij :other :value is.',
    'present_with' => 'Het veld :attribute moet aanwezig zijn wanneer :values aanwezig is.',
    'present_with_all' => 'Het veld :attribute moet aanwezig zijn wanneer :values aanwezig zijn.',
    'prohibited' => 'Het veld :attribute is niet toegestaan.',
    'prohibited_if' => 'Het veld :attribute is niet toegestaan wanneer :other :value is.',
    'prohibited_if_accepted' => 'Het veld :attribute is niet toegestaan wanneer :other is geaccepteerd.',
    'prohibited_if_declined' => 'Het veld :attribute is niet toegestaan wanneer :other is geweigerd.',
    'prohibited_unless' => 'Het veld :attribute is niet toegestaan tenzij :other in :values voorkomt.',
    'prohibits' => 'Het veld :attribute verhindert dat :other aanwezig is.',
    'regex' => 'Het formaat van het veld :attribute is ongeldig.',
    'required' => 'Het veld :attribute is verplicht.',
    'required_array_keys' => 'Het veld :attribute moet vermeldingen bevatten voor: :values.',
    'required_if' => 'Het veld :attribute is verplicht wanneer :other :value is.',
    'required_if_accepted' => 'Het veld :attribute is verplicht wanneer :other is geaccepteerd.',
    'required_if_declined' => 'Het veld :attribute is verplicht wanneer :other is geweigerd.',
    'required_unless' => 'Het veld :attribute is verplicht tenzij :other in :values voorkomt.',
    'required_with' => 'Het veld :attribute is verplicht wanneer :values aanwezig is.',
    'required_with_all' => 'Het veld :attribute is verplicht wanneer :values aanwezig zijn.',
    'required_without' => 'Het veld :attribute is verplicht wanneer :values niet aanwezig is.',
    'required_without_all' => 'Het veld :attribute is verplicht wanneer geen van :values aanwezig is.',
    'same' => 'Het veld :attribute moet overeenkomen met :other.',
    'size' => [
        'array' => 'Het veld :attribute moet :size items bevatten.',
        'file' => 'Het veld :attribute moet :size kilobytes zijn.',
        'numeric' => 'Het veld :attribute moet :size zijn.',
        'string' => 'Het veld :attribute moet :size tekens bevatten.',
    ],
    'starts_with' => 'Het veld :attribute moet beginnen met een van de volgende: :values.',
    'string' => 'Het veld :attribute moet een string zijn.',
    'timezone' => 'Het veld :attribute moet een geldige tijdzone zijn.',
    'unique' => ':attribute is al in gebruik.',
    'uploaded' => 'Het uploaden van :attribute is mislukt.',
    'uppercase' => 'Het veld :attribute moet in hoofdletters zijn.',
    'url' => 'Het veld :attribute moet een geldige URL zijn.',
    'ulid' => 'Het veld :attribute moet een geldige ULID zijn.',
    'uuid' => 'Het veld :attribute moet een geldige UUID zijn.',

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

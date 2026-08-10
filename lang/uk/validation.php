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

    'accepted' => 'Поле :attribute має бути прийняте.',
    'accepted_if' => 'Поле :attribute має бути прийняте, коли :other дорівнює :value.',
    'active_url' => 'Поле :attribute має містити коректну URL-адресу.',
    'after' => 'Поле :attribute має містити дату після :date.',
    'after_or_equal' => 'Поле :attribute має містити дату не раніше :date.',
    'alpha' => 'Поле :attribute має містити лише літери.',
    'alpha_dash' => 'Поле :attribute має містити лише літери, цифри, дефіси та підкреслення.',
    'alpha_num' => 'Поле :attribute має містити лише літери та цифри.',
    'any_of' => 'Поле :attribute недійсне.',
    'array' => 'Поле :attribute має бути масивом.',
    'ascii' => 'Поле :attribute має містити лише однобайтові латинські символи та знаки.',
    'before' => 'Поле :attribute має містити дату до :date.',
    'before_or_equal' => 'Поле :attribute має містити дату не пізніше :date.',
    'between' => [
        'array' => 'Поле :attribute має містити від :min до :max елементів.',
        'file' => 'Розмір файлу в полі :attribute має бути від :min до :max кілобайт.',
        'numeric' => 'Поле :attribute має бути від :min до :max.',
        'string' => 'Кількість символів у полі :attribute має бути від :min до :max.',
    ],
    'boolean' => 'Поле :attribute має мати значення true або false.',
    'can' => 'Поле :attribute містить недопустиме значення.',
    'confirmed' => 'Підтвердження поля :attribute не збігається.',
    'contains' => 'У полі :attribute відсутнє обов’язкове значення.',
    'current_password' => 'Невірний пароль.',
    'date' => 'Поле :attribute має містити коректну дату.',
    'date_equals' => 'Поле :attribute має містити дату, що дорівнює :date.',
    'date_format' => 'Поле :attribute має відповідати формату :format.',
    'decimal' => 'Поле :attribute має містити :decimal знаків після коми.',
    'declined' => 'Поле :attribute має бути відхилене.',
    'declined_if' => 'Поле :attribute має бути відхилене, коли :other дорівнює :value.',
    'different' => 'Поля :attribute та :other мають відрізнятися.',
    'digits' => 'Поле :attribute має містити :digits цифр.',
    'digits_between' => 'Поле :attribute має містити від :min до :max цифр.',
    'dimensions' => 'Поле :attribute має недопустимі розміри зображення.',
    'distinct' => 'Поле :attribute містить повторюване значення.',
    'doesnt_contain' => 'Поле :attribute не має містити жодного з таких значень: :values.',
    'doesnt_end_with' => 'Поле :attribute не має закінчуватися одним із таких значень: :values.',
    'doesnt_start_with' => 'Поле :attribute не має починатися з одного з таких значень: :values.',
    'email' => 'Поле :attribute має містити коректну адресу email.',
    'encoding' => 'Поле :attribute має бути в кодуванні :encoding.',
    'ends_with' => 'Поле :attribute має закінчуватися одним із таких значень: :values.',
    'enum' => 'Вибране значення для :attribute недійсне.',
    'exists' => 'Вибране значення для :attribute недійсне.',
    'extensions' => 'Поле :attribute має мати одне з таких розширень: :values.',
    'file' => 'Поле :attribute має бути файлом.',
    'filled' => 'Поле :attribute має мати значення.',
    'gt' => [
        'array' => 'Поле :attribute має містити більше ніж :value елементів.',
        'file' => 'Розмір файлу в полі :attribute має бути більше :value кілобайт.',
        'numeric' => 'Поле :attribute має бути більше :value.',
        'string' => 'Кількість символів у полі :attribute має бути більше :value.',
    ],
    'gte' => [
        'array' => 'Поле :attribute має містити :value елементів або більше.',
        'file' => 'Розмір файлу в полі :attribute має бути більше або дорівнювати :value кілобайт.',
        'numeric' => 'Поле :attribute має бути більше або дорівнювати :value.',
        'string' => 'Кількість символів у полі :attribute має бути більше або дорівнювати :value.',
    ],
    'hex_color' => 'Поле :attribute має містити коректний шістнадцятковий колір.',
    'image' => 'Поле :attribute має бути зображенням.',
    'in' => 'Вибране значення для :attribute недійсне.',
    'in_array' => 'Поле :attribute має існувати в :other.',
    'in_array_keys' => 'Поле :attribute має містити хоча б один з таких ключів: :values.',
    'integer' => 'Поле :attribute має бути цілим числом.',
    'ip' => 'Поле :attribute має містити коректну IP-адресу.',
    'ipv4' => 'Поле :attribute має містити коректну IPv4-адресу.',
    'ipv6' => 'Поле :attribute має містити коректну IPv6-адресу.',
    'json' => 'Поле :attribute має містити коректний рядок JSON.',
    'list' => 'Поле :attribute має бути списком.',
    'lowercase' => 'Поле :attribute має бути в нижньому регістрі.',
    'lt' => [
        'array' => 'Поле :attribute має містити менше ніж :value елементів.',
        'file' => 'Розмір файлу в полі :attribute має бути менше :value кілобайт.',
        'numeric' => 'Поле :attribute має бути менше :value.',
        'string' => 'Кількість символів у полі :attribute має бути менше :value.',
    ],
    'lte' => [
        'array' => 'Поле :attribute не має містити більше ніж :value елементів.',
        'file' => 'Розмір файлу в полі :attribute має бути менше або дорівнювати :value кілобайт.',
        'numeric' => 'Поле :attribute має бути менше або дорівнювати :value.',
        'string' => 'Кількість символів у полі :attribute має бути менше або дорівнювати :value.',
    ],
    'mac_address' => 'Поле :attribute має містити коректну MAC-адресу.',
    'max' => [
        'array' => 'Поле :attribute не має містити більше ніж :max елементів.',
        'file' => 'Розмір файлу в полі :attribute не має перевищувати :max кілобайт.',
        'numeric' => 'Поле :attribute не має бути більше :max.',
        'string' => 'Кількість символів у полі :attribute не має перевищувати :max.',
    ],
    'max_digits' => 'Поле :attribute не має містити більше ніж :max цифр.',
    'mimes' => 'Поле :attribute має бути файлом одного з типів: :values.',
    'mimetypes' => 'Поле :attribute має бути файлом одного з типів: :values.',
    'min' => [
        'array' => 'Поле :attribute має містити щонайменше :min елементів.',
        'file' => 'Розмір файлу в полі :attribute має бути щонайменше :min кілобайт.',
        'numeric' => 'Поле :attribute має бути щонайменше :min.',
        'string' => 'Кількість символів у полі :attribute має бути щонайменше :min.',
    ],
    'min_digits' => 'Поле :attribute має містити щонайменше :min цифр.',
    'missing' => 'Поле :attribute має бути відсутнім.',
    'missing_if' => 'Поле :attribute має бути відсутнім, коли :other дорівнює :value.',
    'missing_unless' => 'Поле :attribute має бути відсутнім, якщо :other не дорівнює :value.',
    'missing_with' => 'Поле :attribute має бути відсутнім, коли присутнє :values.',
    'missing_with_all' => 'Поле :attribute має бути відсутнім, коли присутні :values.',
    'multiple_of' => 'Поле :attribute має бути кратним :value.',
    'not_in' => 'Вибране значення для :attribute недійсне.',
    'not_regex' => 'Формат поля :attribute недійсний.',
    'numeric' => 'Поле :attribute має бути числом.',
    'password' => [
        'letters' => 'Поле :attribute має містити щонайменше одну літеру.',
        'mixed' => 'Поле :attribute має містити щонайменше одну велику та одну малу літеру.',
        'numbers' => 'Поле :attribute має містити щонайменше одну цифру.',
        'symbols' => 'Поле :attribute має містити щонайменше один символ.',
        'uncompromised' => 'Вказане значення :attribute з’являлося у витоку даних. Будь ласка, оберіть інше значення :attribute.',
    ],
    'present' => 'Поле :attribute має бути присутнім.',
    'present_if' => 'Поле :attribute має бути присутнім, коли :other дорівнює :value.',
    'present_unless' => 'Поле :attribute має бути присутнім, якщо :other не дорівнює :value.',
    'present_with' => 'Поле :attribute має бути присутнім, коли присутнє :values.',
    'present_with_all' => 'Поле :attribute має бути присутнім, коли присутні :values.',
    'prohibited' => 'Поле :attribute заборонене.',
    'prohibited_if' => 'Поле :attribute заборонене, коли :other дорівнює :value.',
    'prohibited_if_accepted' => 'Поле :attribute заборонене, коли :other прийняте.',
    'prohibited_if_declined' => 'Поле :attribute заборонене, коли :other відхилене.',
    'prohibited_unless' => 'Поле :attribute заборонене, якщо :other не входить до :values.',
    'prohibits' => 'Поле :attribute забороняє наявність :other.',
    'regex' => 'Формат поля :attribute недійсний.',
    'required' => 'Поле :attribute обов’язкове.',
    'required_array_keys' => 'Поле :attribute має містити записи для: :values.',
    'required_if' => 'Поле :attribute обов’язкове, коли :other дорівнює :value.',
    'required_if_accepted' => 'Поле :attribute обов’язкове, коли :other прийняте.',
    'required_if_declined' => 'Поле :attribute обов’язкове, коли :other відхилене.',
    'required_unless' => 'Поле :attribute обов’язкове, якщо :other не входить до :values.',
    'required_with' => 'Поле :attribute обов’язкове, коли присутнє :values.',
    'required_with_all' => 'Поле :attribute обов’язкове, коли присутні :values.',
    'required_without' => 'Поле :attribute обов’язкове, коли :values відсутнє.',
    'required_without_all' => 'Поле :attribute обов’язкове, коли жодне з :values не присутнє.',
    'same' => 'Поле :attribute має збігатися з :other.',
    'size' => [
        'array' => 'Поле :attribute має містити :size елементів.',
        'file' => 'Розмір файлу в полі :attribute має бути :size кілобайт.',
        'numeric' => 'Поле :attribute має дорівнювати :size.',
        'string' => 'Кількість символів у полі :attribute має дорівнювати :size.',
    ],
    'starts_with' => 'Поле :attribute має починатися з одного з таких значень: :values.',
    'string' => 'Поле :attribute має бути рядком.',
    'timezone' => 'Поле :attribute має містити коректний часовий пояс.',
    'unique' => 'Таке значення поля :attribute вже зайняте.',
    'uploaded' => 'Не вдалося завантажити :attribute.',
    'uppercase' => 'Поле :attribute має бути у верхньому регістрі.',
    'url' => 'Поле :attribute має містити коректну URL-адресу.',
    'ulid' => 'Поле :attribute має містити коректний ULID.',
    'uuid' => 'Поле :attribute має містити коректний UUID.',

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

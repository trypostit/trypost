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

    'accepted' => ':attribute을(를) 승인해야 합니다.',
    'accepted_if' => ':other이(가) :value일 때 :attribute을(를) 승인해야 합니다.',
    'active_url' => ':attribute은(는) 유효한 URL이어야 합니다.',
    'after' => ':attribute은(는) :date 이후의 날짜여야 합니다.',
    'after_or_equal' => ':attribute은(는) :date 이후이거나 같은 날짜여야 합니다.',
    'alpha' => ':attribute은(는) 문자만 포함해야 합니다.',
    'alpha_dash' => ':attribute은(는) 문자, 숫자, 대시(-), 밑줄(_)만 포함해야 합니다.',
    'alpha_num' => ':attribute은(는) 문자와 숫자만 포함해야 합니다.',
    'any_of' => ':attribute이(가) 유효하지 않습니다.',
    'array' => ':attribute은(는) 배열이어야 합니다.',
    'ascii' => ':attribute은(는) 단일 바이트 영숫자와 기호만 포함해야 합니다.',
    'before' => ':attribute은(는) :date 이전의 날짜여야 합니다.',
    'before_or_equal' => ':attribute은(는) :date 이전이거나 같은 날짜여야 합니다.',
    'between' => [
        'array' => ':attribute은(는) :min개에서 :max개 사이의 항목이 있어야 합니다.',
        'file' => ':attribute은(는) :min에서 :max킬로바이트 사이여야 합니다.',
        'numeric' => ':attribute은(는) :min에서 :max 사이여야 합니다.',
        'string' => ':attribute은(는) :min자에서 :max자 사이여야 합니다.',
    ],
    'boolean' => ':attribute은(는) true 또는 false여야 합니다.',
    'can' => ':attribute에 허가되지 않은 값이 포함되어 있습니다.',
    'confirmed' => ':attribute 확인이 일치하지 않습니다.',
    'contains' => ':attribute에 필수 값이 없습니다.',
    'current_password' => '비밀번호가 올바르지 않습니다.',
    'date' => ':attribute은(는) 유효한 날짜여야 합니다.',
    'date_equals' => ':attribute은(는) :date와 같은 날짜여야 합니다.',
    'date_format' => ':attribute은(는) :format 형식과 일치해야 합니다.',
    'decimal' => ':attribute은(는) 소수점 :decimal자리여야 합니다.',
    'declined' => ':attribute을(를) 거부해야 합니다.',
    'declined_if' => ':other이(가) :value일 때 :attribute을(를) 거부해야 합니다.',
    'different' => ':attribute와(과) :other은(는) 달라야 합니다.',
    'digits' => ':attribute은(는) :digits자리 숫자여야 합니다.',
    'digits_between' => ':attribute은(는) :min에서 :max자리 사이의 숫자여야 합니다.',
    'dimensions' => ':attribute의 이미지 크기가 유효하지 않습니다.',
    'distinct' => ':attribute에 중복된 값이 있습니다.',
    'doesnt_contain' => ':attribute에는 다음 값이 포함되어서는 안 됩니다: :values.',
    'doesnt_end_with' => ':attribute은(는) 다음 중 하나로 끝나서는 안 됩니다: :values.',
    'doesnt_start_with' => ':attribute은(는) 다음 중 하나로 시작해서는 안 됩니다: :values.',
    'email' => ':attribute은(는) 유효한 이메일 주소여야 합니다.',
    'encoding' => ':attribute은(는) :encoding으로 인코딩되어야 합니다.',
    'ends_with' => ':attribute은(는) 다음 중 하나로 끝나야 합니다: :values.',
    'enum' => '선택한 :attribute이(가) 유효하지 않습니다.',
    'exists' => '선택한 :attribute이(가) 유효하지 않습니다.',
    'extensions' => ':attribute은(는) 다음 확장자 중 하나여야 합니다: :values.',
    'file' => ':attribute은(는) 파일이어야 합니다.',
    'filled' => ':attribute에 값이 있어야 합니다.',
    'gt' => [
        'array' => ':attribute은(는) :value개보다 많은 항목이 있어야 합니다.',
        'file' => ':attribute은(는) :value킬로바이트보다 커야 합니다.',
        'numeric' => ':attribute은(는) :value보다 커야 합니다.',
        'string' => ':attribute은(는) :value자보다 길어야 합니다.',
    ],
    'gte' => [
        'array' => ':attribute은(는) :value개 이상의 항목이 있어야 합니다.',
        'file' => ':attribute은(는) :value킬로바이트 이상이어야 합니다.',
        'numeric' => ':attribute은(는) :value 이상이어야 합니다.',
        'string' => ':attribute은(는) :value자 이상이어야 합니다.',
    ],
    'hex_color' => ':attribute은(는) 유효한 16진수 색상이어야 합니다.',
    'image' => ':attribute은(는) 이미지여야 합니다.',
    'in' => '선택한 :attribute이(가) 유효하지 않습니다.',
    'in_array' => ':attribute은(는) :other에 존재해야 합니다.',
    'in_array_keys' => ':attribute에는 다음 키 중 하나 이상이 포함되어야 합니다: :values.',
    'integer' => ':attribute은(는) 정수여야 합니다.',
    'ip' => ':attribute은(는) 유효한 IP 주소여야 합니다.',
    'ipv4' => ':attribute은(는) 유효한 IPv4 주소여야 합니다.',
    'ipv6' => ':attribute은(는) 유효한 IPv6 주소여야 합니다.',
    'json' => ':attribute은(는) 유효한 JSON 문자열이어야 합니다.',
    'list' => ':attribute은(는) 목록이어야 합니다.',
    'lowercase' => ':attribute은(는) 소문자여야 합니다.',
    'lt' => [
        'array' => ':attribute은(는) :value개보다 적은 항목이 있어야 합니다.',
        'file' => ':attribute은(는) :value킬로바이트보다 작아야 합니다.',
        'numeric' => ':attribute은(는) :value보다 작아야 합니다.',
        'string' => ':attribute은(는) :value자보다 짧아야 합니다.',
    ],
    'lte' => [
        'array' => ':attribute은(는) :value개보다 많은 항목이 있어서는 안 됩니다.',
        'file' => ':attribute은(는) :value킬로바이트 이하여야 합니다.',
        'numeric' => ':attribute은(는) :value 이하여야 합니다.',
        'string' => ':attribute은(는) :value자 이하여야 합니다.',
    ],
    'mac_address' => ':attribute은(는) 유효한 MAC 주소여야 합니다.',
    'max' => [
        'array' => ':attribute은(는) :max개보다 많은 항목이 있어서는 안 됩니다.',
        'file' => ':attribute은(는) :max킬로바이트를 초과해서는 안 됩니다.',
        'numeric' => ':attribute은(는) :max을(를) 초과해서는 안 됩니다.',
        'string' => ':attribute은(는) :max자를 초과해서는 안 됩니다.',
    ],
    'max_digits' => ':attribute은(는) :max자리보다 많은 숫자를 가질 수 없습니다.',
    'mimes' => ':attribute은(는) 다음 유형의 파일이어야 합니다: :values.',
    'mimetypes' => ':attribute은(는) 다음 유형의 파일이어야 합니다: :values.',
    'min' => [
        'array' => ':attribute은(는) 최소 :min개의 항목이 있어야 합니다.',
        'file' => ':attribute은(는) 최소 :min킬로바이트여야 합니다.',
        'numeric' => ':attribute은(는) 최소 :min이어야 합니다.',
        'string' => ':attribute은(는) 최소 :min자여야 합니다.',
    ],
    'min_digits' => ':attribute은(는) 최소 :min자리 숫자여야 합니다.',
    'missing' => ':attribute은(는) 없어야 합니다.',
    'missing_if' => ':other이(가) :value일 때 :attribute은(는) 없어야 합니다.',
    'missing_unless' => ':other이(가) :value가 아니면 :attribute은(는) 없어야 합니다.',
    'missing_with' => ':values이(가) 있을 때 :attribute은(는) 없어야 합니다.',
    'missing_with_all' => ':values이(가) 모두 있을 때 :attribute은(는) 없어야 합니다.',
    'multiple_of' => ':attribute은(는) :value의 배수여야 합니다.',
    'not_in' => '선택한 :attribute이(가) 유효하지 않습니다.',
    'not_regex' => ':attribute 형식이 유효하지 않습니다.',
    'numeric' => ':attribute은(는) 숫자여야 합니다.',
    'password' => [
        'letters' => ':attribute에는 문자가 최소 하나 이상 포함되어야 합니다.',
        'mixed' => ':attribute에는 대문자와 소문자가 각각 최소 하나 이상 포함되어야 합니다.',
        'numbers' => ':attribute에는 숫자가 최소 하나 이상 포함되어야 합니다.',
        'symbols' => ':attribute에는 기호가 최소 하나 이상 포함되어야 합니다.',
        'uncompromised' => '입력한 :attribute이(가) 데이터 유출에 나타났습니다. 다른 :attribute을(를) 선택하세요.',
    ],
    'present' => ':attribute이(가) 있어야 합니다.',
    'present_if' => ':other이(가) :value일 때 :attribute이(가) 있어야 합니다.',
    'present_unless' => ':other이(가) :value가 아니면 :attribute이(가) 있어야 합니다.',
    'present_with' => ':values이(가) 있을 때 :attribute이(가) 있어야 합니다.',
    'present_with_all' => ':values이(가) 모두 있을 때 :attribute이(가) 있어야 합니다.',
    'prohibited' => ':attribute은(는) 금지되어 있습니다.',
    'prohibited_if' => ':other이(가) :value일 때 :attribute은(는) 금지됩니다.',
    'prohibited_if_accepted' => ':other이(가) 승인되면 :attribute은(는) 금지됩니다.',
    'prohibited_if_declined' => ':other이(가) 거부되면 :attribute은(는) 금지됩니다.',
    'prohibited_unless' => ':other이(가) :values에 없으면 :attribute은(는) 금지됩니다.',
    'prohibits' => ':attribute은(는) :other이(가) 있는 것을 금지합니다.',
    'regex' => ':attribute 형식이 유효하지 않습니다.',
    'required' => ':attribute은(는) 필수입니다.',
    'required_array_keys' => ':attribute에는 다음 항목이 포함되어야 합니다: :values.',
    'required_if' => ':other이(가) :value일 때 :attribute은(는) 필수입니다.',
    'required_if_accepted' => ':other이(가) 승인되면 :attribute은(는) 필수입니다.',
    'required_if_declined' => ':other이(가) 거부되면 :attribute은(는) 필수입니다.',
    'required_unless' => ':other이(가) :values에 없으면 :attribute은(는) 필수입니다.',
    'required_with' => ':values이(가) 있을 때 :attribute은(는) 필수입니다.',
    'required_with_all' => ':values이(가) 모두 있을 때 :attribute은(는) 필수입니다.',
    'required_without' => ':values이(가) 없을 때 :attribute은(는) 필수입니다.',
    'required_without_all' => ':values 중 어느 것도 없을 때 :attribute은(는) 필수입니다.',
    'same' => ':attribute은(는) :other와(과) 일치해야 합니다.',
    'size' => [
        'array' => ':attribute은(는) :size개의 항목을 포함해야 합니다.',
        'file' => ':attribute은(는) :size킬로바이트여야 합니다.',
        'numeric' => ':attribute은(는) :size여야 합니다.',
        'string' => ':attribute은(는) :size자여야 합니다.',
    ],
    'starts_with' => ':attribute은(는) 다음 중 하나로 시작해야 합니다: :values.',
    'string' => ':attribute은(는) 문자열이어야 합니다.',
    'timezone' => ':attribute은(는) 유효한 시간대여야 합니다.',
    'unique' => ':attribute은(는) 이미 사용 중입니다.',
    'uploaded' => ':attribute 업로드에 실패했습니다.',
    'uppercase' => ':attribute은(는) 대문자여야 합니다.',
    'url' => ':attribute은(는) 유효한 URL이어야 합니다.',
    'ulid' => ':attribute은(는) 유효한 ULID여야 합니다.',
    'uuid' => ':attribute은(는) 유효한 UUID여야 합니다.',

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

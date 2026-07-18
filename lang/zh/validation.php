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

    'accepted' => ':attribute 必须接受。',
    'accepted_if' => '当 :other 为 :value 时，:attribute 必须接受。',
    'active_url' => ':attribute 不是一个有效的网址。',
    'after' => ':attribute 必须是 :date 之后的日期。',
    'after_or_equal' => ':attribute 必须是 :date 或之后的日期。',
    'alpha' => ':attribute 只能包含字母。',
    'alpha_dash' => ':attribute 只能包含字母、数字、短划线和下划线。',
    'alpha_num' => ':attribute 只能包含字母和数字。',
    'any_of' => ':attribute 无效。',
    'array' => ':attribute 必须是一个数组。',
    'ascii' => ':attribute 只能包含单字节的字母数字字符和符号。',
    'before' => ':attribute 必须是 :date 之前的日期。',
    'before_or_equal' => ':attribute 必须是 :date 或之前的日期。',
    'between' => [
        'array' => ':attribute 必须包含 :min 到 :max 个项目。',
        'file' => ':attribute 必须介于 :min 到 :max KB 之间。',
        'numeric' => ':attribute 必须介于 :min 到 :max 之间。',
        'string' => ':attribute 必须介于 :min 到 :max 个字符之间。',
    ],
    'boolean' => ':attribute 必须为 true 或 false。',
    'can' => ':attribute 包含未经授权的值。',
    'confirmed' => ':attribute 两次输入不一致。',
    'contains' => ':attribute 缺少一个必需的值。',
    'current_password' => '密码不正确。',
    'date' => ':attribute 不是一个有效的日期。',
    'date_equals' => ':attribute 必须是等于 :date 的日期。',
    'date_format' => ':attribute 的格式必须为 :format。',
    'decimal' => ':attribute 必须有 :decimal 位小数。',
    'declined' => ':attribute 必须拒绝。',
    'declined_if' => '当 :other 为 :value 时，:attribute 必须拒绝。',
    'different' => ':attribute 和 :other 必须不同。',
    'digits' => ':attribute 必须是 :digits 位数字。',
    'digits_between' => ':attribute 必须介于 :min 到 :max 位数字之间。',
    'dimensions' => ':attribute 的图片尺寸无效。',
    'distinct' => ':attribute 有重复的值。',
    'doesnt_contain' => ':attribute 不得包含以下任一值：:values。',
    'doesnt_end_with' => ':attribute 不得以以下任一内容结尾：:values。',
    'doesnt_start_with' => ':attribute 不得以以下任一内容开头：:values。',
    'email' => ':attribute 必须是一个有效的邮箱地址。',
    'encoding' => ':attribute 必须使用 :encoding 编码。',
    'ends_with' => ':attribute 必须以以下之一结尾：:values。',
    'enum' => '所选的 :attribute 无效。',
    'exists' => '所选的 :attribute 无效。',
    'extensions' => ':attribute 必须具有以下扩展名之一：:values。',
    'file' => ':attribute 必须是一个文件。',
    'filled' => ':attribute 不能为空。',
    'gt' => [
        'array' => ':attribute 必须多于 :value 个项目。',
        'file' => ':attribute 必须大于 :value KB。',
        'numeric' => ':attribute 必须大于 :value。',
        'string' => ':attribute 必须多于 :value 个字符。',
    ],
    'gte' => [
        'array' => ':attribute 必须包含 :value 个或更多项目。',
        'file' => ':attribute 必须大于或等于 :value KB。',
        'numeric' => ':attribute 必须大于或等于 :value。',
        'string' => ':attribute 必须多于或等于 :value 个字符。',
    ],
    'hex_color' => ':attribute 必须是一个有效的十六进制颜色值。',
    'image' => ':attribute 必须是一张图片。',
    'in' => '所选的 :attribute 无效。',
    'in_array' => ':attribute 必须存在于 :other 中。',
    'in_array_keys' => ':attribute 必须包含以下键中的至少一个：:values。',
    'integer' => ':attribute 必须是一个整数。',
    'ip' => ':attribute 必须是一个有效的 IP 地址。',
    'ipv4' => ':attribute 必须是一个有效的 IPv4 地址。',
    'ipv6' => ':attribute 必须是一个有效的 IPv6 地址。',
    'json' => ':attribute 必须是一个有效的 JSON 字符串。',
    'list' => ':attribute 必须是一个列表。',
    'lowercase' => ':attribute 必须是小写。',
    'lt' => [
        'array' => ':attribute 必须少于 :value 个项目。',
        'file' => ':attribute 必须小于 :value KB。',
        'numeric' => ':attribute 必须小于 :value。',
        'string' => ':attribute 必须少于 :value 个字符。',
    ],
    'lte' => [
        'array' => ':attribute 不得多于 :value 个项目。',
        'file' => ':attribute 必须小于或等于 :value KB。',
        'numeric' => ':attribute 必须小于或等于 :value。',
        'string' => ':attribute 必须少于或等于 :value 个字符。',
    ],
    'mac_address' => ':attribute 必须是一个有效的 MAC 地址。',
    'max' => [
        'array' => ':attribute 不得多于 :max 个项目。',
        'file' => ':attribute 不得大于 :max KB。',
        'numeric' => ':attribute 不得大于 :max。',
        'string' => ':attribute 不得多于 :max 个字符。',
    ],
    'max_digits' => ':attribute 不得多于 :max 位数字。',
    'mimes' => ':attribute 必须是以下类型的文件：:values。',
    'mimetypes' => ':attribute 必须是以下类型的文件：:values。',
    'min' => [
        'array' => ':attribute 至少要有 :min 个项目。',
        'file' => ':attribute 至少要有 :min KB。',
        'numeric' => ':attribute 至少要为 :min。',
        'string' => ':attribute 至少要有 :min 个字符。',
    ],
    'min_digits' => ':attribute 至少要有 :min 位数字。',
    'missing' => ':attribute 必须不存在。',
    'missing_if' => '当 :other 为 :value 时，:attribute 必须不存在。',
    'missing_unless' => '除非 :other 为 :value，否则 :attribute 必须不存在。',
    'missing_with' => '当存在 :values 时，:attribute 必须不存在。',
    'missing_with_all' => '当 :values 都存在时，:attribute 必须不存在。',
    'multiple_of' => ':attribute 必须是 :value 的倍数。',
    'not_in' => '所选的 :attribute 无效。',
    'not_regex' => ':attribute 的格式无效。',
    'numeric' => ':attribute 必须是一个数字。',
    'password' => [
        'letters' => ':attribute 必须至少包含一个字母。',
        'mixed' => ':attribute 必须至少包含一个大写字母和一个小写字母。',
        'numbers' => ':attribute 必须至少包含一个数字。',
        'symbols' => ':attribute 必须至少包含一个符号。',
        'uncompromised' => '所填的 :attribute 曾出现在数据泄露中。请换一个不同的 :attribute。',
    ],
    'present' => ':attribute 必须存在。',
    'present_if' => '当 :other 为 :value 时，:attribute 必须存在。',
    'present_unless' => '除非 :other 为 :value，否则 :attribute 必须存在。',
    'present_with' => '当存在 :values 时，:attribute 必须存在。',
    'present_with_all' => '当 :values 都存在时，:attribute 必须存在。',
    'prohibited' => ':attribute 被禁止。',
    'prohibited_if' => '当 :other 为 :value 时，:attribute 被禁止。',
    'prohibited_if_accepted' => '当 :other 被接受时，:attribute 被禁止。',
    'prohibited_if_declined' => '当 :other 被拒绝时，:attribute 被禁止。',
    'prohibited_unless' => '除非 :other 在 :values 中，否则 :attribute 被禁止。',
    'prohibits' => ':attribute 会导致 :other 不能存在。',
    'regex' => ':attribute 的格式无效。',
    'required' => ':attribute 不能为空。',
    'required_array_keys' => ':attribute 必须包含以下项的条目：:values。',
    'required_if' => '当 :other 为 :value 时，:attribute 不能为空。',
    'required_if_accepted' => '当 :other 被接受时，:attribute 不能为空。',
    'required_if_declined' => '当 :other 被拒绝时，:attribute 不能为空。',
    'required_unless' => '除非 :other 在 :values 中，否则 :attribute 不能为空。',
    'required_with' => '当存在 :values 时，:attribute 不能为空。',
    'required_with_all' => '当 :values 都存在时，:attribute 不能为空。',
    'required_without' => '当不存在 :values 时，:attribute 不能为空。',
    'required_without_all' => '当 :values 都不存在时，:attribute 不能为空。',
    'same' => ':attribute 和 :other 必须相同。',
    'size' => [
        'array' => ':attribute 必须包含 :size 个项目。',
        'file' => ':attribute 必须为 :size KB。',
        'numeric' => ':attribute 必须为 :size。',
        'string' => ':attribute 必须为 :size 个字符。',
    ],
    'starts_with' => ':attribute 必须以以下之一开头：:values。',
    'string' => ':attribute 必须是一个字符串。',
    'timezone' => ':attribute 必须是一个有效的时区。',
    'unique' => ':attribute 已经被占用。',
    'uploaded' => ':attribute 上传失败。',
    'uppercase' => ':attribute 必须是大写。',
    'url' => ':attribute 必须是一个有效的网址。',
    'ulid' => ':attribute 必须是一个有效的 ULID。',
    'uuid' => ':attribute 必须是一个有效的 UUID。',

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

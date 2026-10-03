<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => '我们无法访问该网站（:reason）。',
        'http_status' => '该网站返回了异常状态（:status）。',
        'invalid_scheme' => '仅支持 http 和 https 网址。',
        'missing_host' => '该网址缺少主机名。',
        'unresolvable_host' => '我们无法解析该主机（:host）。',
        'private_network' => '不允许指向私有网络的网址。',
    ],
];

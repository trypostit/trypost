<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => '해당 웹사이트에 연결할 수 없습니다 (:reason).',
        'http_status' => '웹사이트가 예상치 못한 상태를 반환했습니다 (:status).',
        'invalid_scheme' => 'http 및 https URL만 지원됩니다.',
        'missing_host' => 'URL에 호스트가 없습니다.',
        'unresolvable_host' => '호스트를 확인할 수 없습니다 (:host).',
        'private_network' => '사설 네트워크를 가리키는 URL은 허용되지 않습니다.',
    ],
];

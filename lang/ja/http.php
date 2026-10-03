<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'そのウェブサイトに接続できませんでした（:reason）。',
        'http_status' => 'ウェブサイトが予期しないステータスを返しました（:status）。',
        'invalid_scheme' => 'http と https の URL のみサポートされています。',
        'missing_host' => 'URL にホストがありません。',
        'unresolvable_host' => 'ホストを解決できませんでした（:host）。',
        'private_network' => 'プライベートネットワークを指す URL は許可されていません。',
    ],
];

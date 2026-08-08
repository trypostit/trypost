<?php

return [
    'mentioned' => [
        'subject' => ':name님이 TryPost에서 회원님을 멘션했습니다',
        'title' => ':name님이 회원님을 멘션했습니다',
        'intro' => ':name님이 게시물 댓글에서 회원님을 멘션했습니다.',
        'cta' => '댓글 보기',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :workspace에서 :count개 계정을 재연결해야 합니다|[2,*] :workspace에서 :count개 계정을 재연결해야 합니다',
        'title' => '계정 재연결 필요',
        'intro' => '<strong>:workspace</strong> 워크스페이스의 다음 소셜 계정이 연결 해제되어 재연결이 필요합니다:',
        'reasons_title' => '다음과 같은 이유로 발생했을 수 있습니다:',
        'reason_expired' => '액세스 토큰 만료',
        'reason_revoked' => '플랫폼에서 TryPost에 대한 액세스를 취소함',
        'reason_changed' => '플랫폼이 인증 요구사항을 변경함',
        'reconnect_cta' => '게시물 예약 및 게시를 계속하려면 이 계정들을 재연결해 주세요.',
        'button' => '계정 재연결',
    ],
];

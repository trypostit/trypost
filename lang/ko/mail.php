<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => '오픈소스 소셜 미디어 예약 발행 도구',
        'manage_notifications' => '알림 관리',
        'signoff' => '감사합니다.',
        'team' => 'TryPost 팀',
    ],

    'account_disconnected' => [
        'subject' => ':workspace의 :platform 계정을 다시 연결해야 합니다',
        'title' => ':platform 계정을 다시 연결해야 합니다',
        'preview' => '게시물 예약을 계속하려면 :workspace의 :platform 계정을 다시 연결하세요.',
        'heading' => '계정 연결 해제됨',
        'intro' => '워크스페이스 <strong>:workspace</strong>에서 <strong>:platform</strong> 계정 <strong>:account</strong>의 연결이 해제되었습니다.',
        'reasons_title' => '다음과 같은 이유일 수 있습니다:',
        'reason_expired' => '액세스 토큰이 만료되었습니다',
        'reason_revoked' => 'TryPost의 접근 권한을 취소했습니다',
        'reason_error' => '인증 오류가 발생했습니다',
        'reconnect_cta' => '계정을 다시 연결하면 예약과 발행을 계속할 수 있습니다.',
        'button' => '계정 다시 연결',
    ],

    'email_verification' => [
        'subject' => '이메일 주소를 인증하세요',
        'preview' => '이메일 주소를 인증해 주세요.',
        'greeting' => ':name님, 안녕하세요.',
        'body' => '아래 버튼을 눌러 이메일 주소를 확인해 주세요.',
        'button' => '이메일 인증',
        'ignore' => '계정을 만든 적이 없다면 이 메일을 무시하셔도 됩니다.',
    ],

    'mentioned_in_comment' => [
        'subject' => ':name님이 TryPost에서 회원님을 멘션했습니다',
        'title' => ':name님이 회원님을 멘션했습니다',
        'intro' => ':name님이 게시물 댓글에서 회원님을 멘션했습니다.',
        'button' => '댓글 보기',
    ],

    'password_reset' => [
        'subject' => '비밀번호를 재설정하세요',
        'preview' => '비밀번호를 재설정하세요.',
        'greeting' => ':name님, 안녕하세요.',
        'body' => '비밀번호 재설정 요청을 받았습니다. 아래 버튼을 눌러 새 비밀번호를 만드세요.',
        'button' => '비밀번호 재설정',
        'expiry' => '이 링크는 60분 후 만료됩니다. 요청한 적이 없다면 이 메일을 무시하셔도 됩니다.',
    ],

    'post_at_risk' => [
        'subject' => '{1} :workspace에서 게시물 :count건이 발행되지 않을 수 있습니다|[0,*] :workspace에서 게시물 :count건이 발행되지 않을 수 있습니다',
        'title' => '게시물 발행이 실패할 수 있습니다',
        'heading' => '게시물 발행이 실패할 수 있습니다',
        'intro' => '예약한 게시물을 발행하려면 워크스페이스 :workspace의 다음 계정을 다시 연결해야 합니다:',
        'posts_label' => '{1} 예약 게시물 :count건: :times UTC|[0,*] 예약 게시물 :count건: :times UTC',
        'reconnect_cta' => '예약한 게시물을 놓치지 않도록 지금 계정을 다시 연결하세요.',
        'button' => '계정 다시 연결',
    ],

    'post_publish_failed' => [
        'subject' => ':workspace에서 게시물 발행에 실패했습니다',
        'title' => '게시물 발행에 실패했습니다',
        'preview' => '하나 이상의 플랫폼에서 발행하지 못했습니다.',
        'heading' => '게시물 발행에 실패했습니다',
        'body' => '워크스페이스 :workspace의 예약 게시물이 하나 이상의 플랫폼에서 발행되지 않았습니다.',
        'platforms_title' => '실패한 플랫폼:',
        'button' => '게시물 보기',
    ],

    'post_published' => [
        'subject' => ':workspace에서 게시물이 발행되었습니다',
        'title' => '게시물이 발행되었습니다',
        'preview' => '게시물이 정상적으로 발행되었습니다.',
        'heading' => '게시물이 발행되었습니다',
        'body' => '워크스페이스 :workspace의 게시물이 정상적으로 발행되었습니다.',
        'platforms_title' => '발행된 플랫폼:',
        'view_post' => '게시물 보기',
        'button' => '게시물 보기',
    ],

    'webhook_paused' => [
        'subject' => '웹훅이 일시정지됨: :endpoint',
        'title' => '반복된 실패로 웹훅이 일시정지되었습니다',
        'preview' => '연속 5회 전달에 실패한 뒤 웹훅을 일시정지했습니다.',
        'heading' => '반복된 실패로 웹훅이 일시정지되었습니다',
        'body' => ':endpoint의 웹훅을 연속 5회 전달 실패 후 일시정지했습니다. Endpoint를 확인한 뒤 웹훅 상세 페이지에서 다시 사용하세요.',
        'button' => '웹훅 보기',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :workspace에서 :count개 계정을 재연결해야 합니다|[0,*] :workspace에서 :count개 계정을 재연결해야 합니다',
        'title' => '계정 재연결 필요',
        'heading' => '계정 재연결 필요',
        'intro' => '<strong>:workspace</strong> 워크스페이스의 다음 소셜 계정이 연결 해제되어 재연결이 필요합니다:',
        'reasons_title' => '다음과 같은 이유로 발생했을 수 있습니다:',
        'reason_expired' => '액세스 토큰 만료',
        'reason_revoked' => '플랫폼에서 TryPost에 대한 액세스를 취소함',
        'reason_changed' => '플랫폼이 인증 요구사항을 변경함',
        'reconnect_cta' => '게시물 예약 및 게시를 계속하려면 이 계정들을 재연결해 주세요.',
        'button' => '계정 재연결',
    ],

    'workspace_invite' => [
        'subject' => ':account에 초대되었습니다',
        'title' => ':account에 초대되었습니다',
        'preview' => ':account에 초대되었습니다',
        'heading' => '초대를 받았습니다',
        'intro' => '워크스페이스 <strong>:account</strong>에서 함께 일하도록 초대되었습니다.',
        'role' => '<strong>:role</strong> 역할로 초대되었습니다.',
        'button' => '초대 수락',
        'expiry' => '이 초대는 7일 후 만료됩니다.',
    ],

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => '입력하신 인증 정보가 기록과 일치하지 않습니다.',
    'password' => '입력하신 비밀번호가 올바르지 않습니다.',
    'throttle' => '로그인 시도가 너무 많습니다. :seconds초 후에 다시 시도해 주세요.',

    'flash' => [
        'welcome' => 'TryPost에 오신 것을 환영합니다!',
        'welcome_trial' => 'TryPost에 오신 것을 환영합니다! 체험이 시작되었습니다.',
    ],

    'legal' => '계속 진행하면 <a href="https://trypost.it/terms" target="_blank">서비스 약관</a> 및 <a href="https://trypost.it/privacy" target="_blank">개인정보 처리방침</a>에 동의하는 것입니다.',

    'slides' => [
        'calendar' => [
            'title' => '비주얼 캘린더',
            'description' => '직관적인 드래그 앤 드롭 캘린더로 모든 소셜 계정의 콘텐츠를 계획하고 예약하세요.',
        ],
        'scheduling' => [
            'title' => '스마트 예약',
            'description' => 'LinkedIn, X, Instagram, TikTok, YouTube 등 여러 채널의 게시물을 한 곳에서 예약하세요.',
        ],
        'media' => [
            'title' => '리치 미디어',
            'description' => '이미지, 캐러셀, 스토리, 릴스를 게시하세요. 각 플랫폼에 맞는 형식이 자동으로 적용됩니다.',
        ],
        'video' => [
            'title' => '동영상 게시',
            'description' => '동영상을 한 번 업로드하여 TikTok, YouTube Shorts, Instagram Reels, Facebook Reels에 게시하세요.',
        ],
        'team' => [
            'title' => '팀 워크스페이스',
            'description' => '팀을 초대하고 역할을 지정하며 별도의 워크스페이스에서 여러 브랜드를 관리하세요.',
        ],
        'signatures' => [
            'title' => '서명',
            'description' => '재사용 가능한 서명(해시태그, 링크, 맺음말)을 저장하고 클릭 한 번으로 게시물에 추가하세요.',
        ],
    ],

    'or_continue_with' => '또는 다음으로 계속하기',
    'or_continue_with_email' => '또는 이메일로 계속하기',
    'google_login' => 'Google로 로그인',
    'google_signup' => 'Google로 가입하기',
    'github_login' => 'GitHub으로 로그인',
    'github_signup' => 'GitHub으로 가입하기',
    'github_email_unavailable' => 'GitHub에서 이메일을 가져올 수 없습니다. GitHub 이메일을 공개로 설정하거나 이메일 권한을 부여한 후 다시 시도하세요.',

    'signup_success' => [
        'page_title' => '환영합니다',
        'title' => '계정을 설정하는 중',
        'description' => '보통 몇 초면 완료됩니다...',
    ],

    'login' => [
        'title' => '계정에 로그인',
        'description' => '로그인하려면 아래에 이메일과 비밀번호를 입력하세요',
        'page_title' => '로그인',
        'email' => '이메일 주소',
        'password' => '비밀번호',
        'forgot_password' => '비밀번호를 잊으셨나요?',
        'remember_me' => '로그인 상태 유지',
        'submit' => '로그인',
        'no_account' => '계정이 없으신가요?',
        'sign_up' => '가입하기',
    ],

    'register' => [
        'title' => '모든 소셜 캘린더를 한 곳에서',
        'description' => '계정을 만들고 모든 네트워크에 게시물 예약을 시작하세요.',
        'page_title' => '회원가입',
        'signup_with_email' => '이메일로 가입하기',
        'name' => '이름',
        'name_placeholder' => '전체 이름',
        'email' => '이메일 주소',
        'password' => '비밀번호',
        'show_password' => '비밀번호 표시',
        'hide_password' => '비밀번호 숨기기',
        'submit' => '계정 만들기',
        'has_account' => '이미 계정이 있으신가요?',
        'log_in' => '로그인',
    ],

    'forgot_password' => [
        'title' => '비밀번호 찾기',
        'description' => '비밀번호 재설정 링크를 받으려면 이메일을 입력하세요',
        'page_title' => '비밀번호 찾기',
        'email' => '이메일 주소',
        'submit' => '비밀번호 재설정 링크 이메일로 받기',
        'return_to' => '또는 다음으로 돌아가기',
        'log_in' => '로그인',
    ],

    'reset_password' => [
        'title' => '비밀번호 재설정',
        'description' => '아래에 새 비밀번호를 입력하세요',
        'page_title' => '비밀번호 재설정',
        'email' => '이메일',
        'password' => '비밀번호',
        'confirm_password' => '비밀번호 확인',
        'confirm_placeholder' => '비밀번호 확인',
        'submit' => '비밀번호 재설정',
    ],

    'verify_email' => [
        'title' => '이메일 인증',
        'description' => '방금 보내드린 이메일의 링크를 클릭하여 이메일 주소를 인증해 주세요.',
        'page_title' => '이메일 인증',
        'link_sent' => '가입 시 입력하신 이메일 주소로 새 인증 링크를 보냈습니다.',
        'resend' => '인증 이메일 재전송',
        'log_out' => '로그아웃',
    ],

    'accept_invite' => [
        'page_title' => '초대 수락',
        'title' => '초대를 받으셨습니다!',
        'description' => ':workspace 워크스페이스에 참여하도록 초대받으셨습니다.',
        'workspace' => '워크스페이스',
        'your_role' => '내 역할',
        'email' => '이메일',
        'accept' => '초대 수락',
        'decline' => '초대 거절',
        'login_prompt' => '이 초대를 수락하려면 로그인하거나 계정을 만드세요.',
        'log_in' => '로그인',
        'create_account' => '계정 만들기',
    ],

];

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

    'failed' => '这些凭据与我们的记录不匹配。',
    'password' => '提供的密码不正确。',
    'throttle' => '登录尝试次数过多，请在 :seconds 秒后重试。',

    'flash' => [
        'welcome' => '欢迎使用 TryPost！',
        'welcome_trial' => '欢迎使用 TryPost！你的试用已开始。',
    ],

    'legal' => '继续即表示你同意我们的<a href="https://trypost.it/terms" target="_blank">服务条款</a>和<a href="https://trypost.it/privacy" target="_blank">隐私政策</a>。',

    'slides' => [
        'calendar' => [
            'title' => '可视化日历',
            'description' => '通过直观的拖放日历，跨所有社交账号规划和安排你的内容。',
        ],
        'scheduling' => [
            'title' => '智能排期',
            'description' => '在一个地方向 LinkedIn、X、Instagram、TikTok、YouTube 等平台安排发帖。',
        ],
        'media' => [
            'title' => '丰富媒体',
            'description' => '发布图片、轮播、快拍和 Reels。每个平台都会自动获得合适的格式。',
        ],
        'video' => [
            'title' => '视频发布',
            'description' => '一次上传视频，即可发布到 TikTok、YouTube Shorts、Instagram Reels 和 Facebook Reels。',
        ],
        'team' => [
            'title' => '团队工作区',
            'description' => '邀请团队成员、分配角色，并在独立的工作区中管理多个品牌。',
        ],
        'signatures' => [
            'title' => '签名',
            'description' => '保存可复用的签名（话题标签、链接、落款），一键附加到帖子中。',
        ],
    ],

    'or_continue_with' => '或使用以下方式继续',
    'or_continue_with_email' => '或使用邮箱继续',
    'google_login' => '使用 Google 登录',
    'google_signup' => '使用 Google 注册',
    'github_login' => '使用 GitHub 登录',
    'github_signup' => '使用 GitHub 注册',
    'github_email_unavailable' => '无法从 GitHub 获取你的邮箱。请将你的 GitHub 邮箱设为公开，或授予邮箱权限后重试。',

    'signup_success' => [
        'page_title' => '欢迎',
        'title' => '正在设置你的账户',
        'description' => '这通常只需几秒钟…',
    ],

    'login' => [
        'title' => '登录你的账户',
        'description' => '请在下方输入你的邮箱和密码以登录',
        'page_title' => '登录',
        'email' => '邮箱地址',
        'password' => '密码',
        'forgot_password' => '忘记密码？',
        'remember_me' => '记住我',
        'submit' => '登录',
        'no_account' => '还没有账户？',
        'sign_up' => '注册',
    ],

    'register' => [
        'title' => '你的整个社交日历，尽在一处',
        'description' => '创建账户，开始在每个平台上安排发帖。',
        'page_title' => '注册',
        'signup_with_email' => '使用邮箱注册',
        'name' => '姓名',
        'name_placeholder' => '全名',
        'email' => '邮箱地址',
        'password' => '密码',
        'show_password' => '显示密码',
        'hide_password' => '隐藏密码',
        'submit' => '创建账户',
        'has_account' => '已经有账户了？',
        'log_in' => '登录',
    ],

    'forgot_password' => [
        'title' => '忘记密码',
        'description' => '输入你的邮箱以接收密码重置链接',
        'page_title' => '忘记密码',
        'email' => '邮箱地址',
        'submit' => '发送密码重置链接',
        'return_to' => '或者，返回',
        'log_in' => '登录',
    ],

    'reset_password' => [
        'title' => '重置密码',
        'description' => '请在下方输入你的新密码',
        'page_title' => '重置密码',
        'email' => '邮箱',
        'password' => '密码',
        'confirm_password' => '确认密码',
        'confirm_placeholder' => '确认密码',
        'submit' => '重置密码',
    ],

    'verify_email' => [
        'title' => '验证邮箱',
        'description' => '请点击我们刚刚发送到你邮箱的链接以验证你的邮箱地址。',
        'page_title' => '邮箱验证',
        'link_sent' => '新的验证链接已发送至你注册时填写的邮箱地址。',
        'resend' => '重新发送验证邮件',
        'log_out' => '退出登录',
    ],

    'accept_invite' => [
        'page_title' => '接受邀请',
        'title' => '你收到了一份邀请！',
        'description' => '你被邀请加入 :workspace 工作区。',
        'workspace' => '工作区',
        'your_role' => '你的角色',
        'email' => '邮箱',
        'accept' => '接受邀请',
        'decline' => '拒绝邀请',
        'login_prompt' => '登录或创建账户以接受此邀请。',
        'log_in' => '登录',
        'create_account' => '创建账户',
    ],

];

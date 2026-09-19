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

    'legal' => '继续即表示你同意我们的<a href=":terms_url" target="_blank">服务条款</a>和<a href=":privacy_url" target="_blank">隐私政策</a>。',

    'reviews' => [
        'eyebrow' => 'G2 上 5/5',
        'heading' => '每天发布内容的人都爱用',
        'paulo_dantas' => [
            'role' => '创始人，chatadv.com.br',
            'quote' => '在所有社交网络上创建、整理和分发内容都很简单。有了 MCP，我们可以用自己喜欢的 AI，比如 Claude 或 ChatGPT，直接创建内容并安排发布。',
        ],
        'diego' => [
            'role' => 'CEO，Globalfy.com',
            'quote' => '我喜欢 TryPost 的简单。我可以直接在 Claude 里写好帖子，再用 MCP 发布或安排到以后发送。五分钟就配置好了。',
        ],
        'luiz' => [
            'role' => '内容创作者',
            'quote' => '我很喜欢连接 AI 工具和智能体的过程，几分钟就能把内容安排到 9 个社交平台。',
        ],
        'pedro' => [
            'role' => '创始人，templated.io',
            'quote' => '使用和集成都非常简单。有了 MCP，我只需要在界面里连接社交账号。',
        ],
        'paulo_castellano' => [
            'role' => '创始人，changelogfy.com',
            'quote' => '我非常喜欢 MCP 集成，它让我可以在 Claude 或 ChatGPT 里管理所有社交媒体账号。',
        ],
    ],

    'or_continue_with' => '或使用以下方式继续',
    'or_continue_with_email' => '或使用邮箱继续',
    'google_login' => '使用 Google 登录',
    'google_signup' => '使用 Google 注册',
    'github_login' => '使用 GitHub 登录',
    'github_signup' => '使用 GitHub 注册',
    'github_email_unavailable' => '无法从 GitHub 获取你的邮箱。请将你的 GitHub 邮箱设为公开，或授予邮箱权限后重试。',

    'login' => [
        'title' => '登录你的账户',
        'description' => '请在下方输入你的邮箱和密码以登录',
        'page_title' => '登录',
        'email' => '邮箱地址',
        'password' => '密码',
        'show_password' => '显示密码',
        'hide_password' => '隐藏密码',
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
        'expired_title' => '此邀请已失效',
        'expired_description' => '该邀请对应的工作区已被删除。如仍需访问，请向账户所有者索取新邀请。',
        'expired_action' => '返回首页',
    ],

];

<?php

return [
    'title' => '账单',

    'past_due_notice' => [
        'title' => '付款已逾期',
        'description' => '请更新你的付款方式以保持订阅有效。',
        'cta' => '更新付款方式',
    ],

    'annual_banner' => [
        'title' => '免费获得 2 个月',
        'description' => '切换到年付，每月支付更少——套餐不变，其他一切照旧。',
        'cta' => '升级到年付',
    ],

    'subscribe' => [
        'billed_monthly' => '按月计费',
        'billed_yearly' => '按年计费',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => '套餐',
        'description' => '随时可以升级或降级。',
        'monthly' => '按月',
        'yearly' => '按年',
        'save_two_months' => '免两个月',
        'per_month' => '/月',
        'workspaces_one' => '1 个工作区',
        'workspaces_unlimited' => '无限工作区',
        'current' => '当前套餐',
        'select' => '选择 :plan',
        'start_first_month' => '以 :price 开始第一个月',

        'billed_yearly_total' => '按年计费 · :price（免两个月）',
        'socials_tagline' => '适合创作者和小品牌。',
        'workspaces_tagline' => '适合代理商和大型业务。',
        'everything_included' => '全部包含',
        'features' => [
            'networks_all' => '包含所有社交网络',
            'networks_all_tooltip' => '你可以在所有这些平台上发布。',
            'accounts_unlimited' => '社交账号不限',
            'accounts_unlimited_tooltip' => '想连接多少账号都可以，同一平台的多个账号也行。比如三个 Instagram。',
            'calendar' => '日历：月、周、日视图',
            'calendar_tooltip' => '一眼看清整个月：哪些在计划中、已排期、已发布。需要细节时切换到周或日视图。',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => '帮你撰写和检查帖子的 AI 助手。',
            'mcp' => 'MCP：用 Claude、ChatGPT 或 Grok 发布',
            'mcp_tooltip' => '把 Claude、ChatGPT 或 Grok 连接到你的工作区。让它创建和排期帖子、拉取数据、找出表现最好的内容，并根据你的数据规划下一步。',
            'repurpose' => 'Repurpose：一条内容变成多条',
            'repurpose_tooltip' => '选择一个来源账号。你在那里发布的每条新帖子都会自动转发到你的其他平台。不需要打开 TryPost。',
            'analytics' => '数据分析',
            'analytics_tooltip' => '获取每条帖子、每个账号的曝光、触达、点赞、评论等数据，全部集中在一处。',
            'team' => '成员人数不限',
            'team_tooltip' => '想邀请多少人加入团队都可以，不收额外费用。设定每个人能做什么，发布前先审批。',
        ],
    ],

    'plan' => [
        'title' => '套餐',
        'description' => '管理你的订阅套餐。',
        'label' => '套餐',
        'price' => '价格',
        'month' => '月',
        'trial' => '试用',
        'active' => '有效',
        'past_due' => '已逾期',
        'cancelling' => '取消中',
        'trial_ends' => '试用结束',
    ],

    'subscription' => [
        'title' => '订阅',
        'description' => '管理你的付款方式、账单信息和订阅。',
        'payment_method' => '付款方式',
        'no_payment_method' => '尚未添加付款方式。',
        'expires_on' => '有效期至 :month/:year',
        'manage_label' => '订阅',
        'manage_stripe' => '在 Stripe 上管理',
    ],

    'invoices' => [
        'title' => '发票',
        'description' => '下载你过往的发票。',
        'empty' => '未找到发票',
        'paid' => '已支付',
    ],

    'flash' => [
        'plan_changed' => '你现在使用的是 :plan 套餐。',
        'switched_to_yearly' => '你现在已切换为按年计费。',
        'cannot_manage' => '只有账户所有者才能管理账单。',
        'too_many_workspaces' => '你有 :count 个工作区。此套餐包含 :limit 个 — 切换前请先删除多余的。',
        'subscription_required' => '使用 AI 功能需要有效的订阅。',
    ],

    'processing' => [
        'page_title' => '处理中…',
        'title' => '正在处理你的订阅',
        'description' => '正在为你设置账户，请稍候。这只需片刻。',
        'success_title' => '一切就绪！',
        'success_description' => '你的订阅已生效。正在为你跳转到工作区…',
        'cancelled_title' => '结账已取消',
        'cancelled_description' => '你的结账已取消，未产生任何费用。',
        'retry' => '重试',
    ],
];

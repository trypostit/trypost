<?php

return [
    'title' => '账单',

    'past_due_notice' => [
        'title' => '付款已逾期',
        'description' => '请更新你的付款方式以保持订阅有效。',
        'cta' => '更新付款方式',
    ],

    'subscribe' => [
        'billed_monthly' => '按月计费',
        'prices' => [
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'description' => '随时可以升级或降级。',
        'monthly' => '按月',
        'yearly' => '按年',
        'save_two_months' => '免两个月',
        'per_month' => '/月',
        'per_year' => '/年',
        'workspaces_one' => '一个工作区',
        'workspaces_unlimited' => '无限工作区',
        'workspaces_tooltip' => '一个工作区对应一个品牌或客户，与其他工作区相互隔离：拥有自己的社交账号、签名、标签、数据分析、成员权限和 MCP 连接。',
        'current' => '当前套餐',
        'switch_to_yearly' => '切换到年付',
        'switch_to_monthly' => '切换到月付',
        'select' => '选择 :plan',
        'upgrade' => '升级到 :plan',
        'downgrade' => '降级到 :plan',
        'billed_yearly_total' => '按年计费 · :price（免两个月）',
        'socials_tagline' => '适合创作者和小品牌。',
        'workspaces_tagline' => '适合代理商和大型业务。',
        'everything_included' => '全部包含',
        'features' => [
            'networks_all' => '包含所有社交平台',
            'networks_all_tooltip' => '在一个地方发布到所有平台。',
            'accounts_unlimited' => '不限社交账号数量',
            'accounts_unlimited_tooltip' => '想连接多少账号都可以，同一平台的多个账号也行。比如三个 Instagram。',
            'calendar' => '内容日历',
            'ideas' => '内容规划',
            'ideas_tooltip' => '随手记下点子，集中整理，好点子随时变成内容。',
            'calendar_tooltip' => '在一个地方按月或按周查看所有已排期和已发布的帖子。',
            'ai' => 'AI 写作助手',
            'ai_tooltip' => '用 AI 撰写、改写帖子并调整语气，支持你的语言。',
            'mcp' => '在 Claude 或 ChatGPT 中使用 TryPost',
            'mcp_tooltip' => '连接 Claude、ChatGPT 或其他 AI。让它创建和排期帖子、填充队列、整理点子，并展示你的增长。',
            'repurpose' => '视频自动转发',
            'repurpose_tooltip' => '在 Instagram 发了视频？TryPost 会自动把它转发到 TikTok、YouTube、Facebook 和你的其他平台。',
            'analytics' => '高级数据分析',
            'analytics_tooltip' => '把所有平台合起来看，比如粉丝总数，也可以按平台和帖子筛选。观看、覆盖、点赞、评论等数据，帮你知道什么最有效。',
            'team' => '不限团队成员',
            'team_tooltip' => '想邀请多少人加入团队都可以，不收额外费用。设定每个人能做什么，发布前先审批。',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => '生效中',
            'trial' => '免费试用',
            'cancelling' => '已取消',
            'past_due' => '已逾期',
        ],
        'change' => '更改套餐',
        'subscribe' => '立即订阅',
        'price_monthly' => ':price/月',
        'price_yearly' => ':price/年',
        'trial_until' => '试用至 :date',
        'cancelled' => '已取消，将于 :date 结束',
        'discount_until' => '优惠 :discount，截至 :date',
        'discount_forever' => '每次付款优惠 :discount',
        'discount_once' => '下次付款优惠 :discount',
    ],

    'subscription' => [
        'title' => '付款方式',
        'default' => '默认',
        'description' => '在 Stripe 更新你的卡片或账单信息。',
        'no_payment_method' => '尚未添加付款方式。',
        'expires_on' => '有效期至 :month/:year',
        'manage_stripe' => '在 Stripe 上管理',
    ],

    'tabs' => [
        'plan' => '套餐',
        'invoices' => '发票',
    ],

    'invoices' => [
        'columns' => [
            'date' => '日期',
            'amount' => '金额',
            'status' => '状态',
        ],
        'empty_title' => '暂无发票',
        'empty_description' => '首次付款后，你的发票会显示在这里。',
        'paid' => '已支付',
        'download' => '下载发票',
    ],

    'flash' => [
        'plan_changed' => '你现在使用的是 :plan 套餐。',
        'cannot_manage' => '只有账户所有者才能管理账单。',
        'too_many_workspaces' => '你有 :count 个工作区。此套餐包含 :limit 个 — 切换前请先删除多余的。',
        'subscription_required' => '使用 AI 功能需要有效的订阅。',
    ],

    'processing' => [
        'page_title' => '处理中…',
        'title' => '正在处理你的订阅',
        'description' => '正在为你设置账户，请稍候。这只需片刻。',
    ],
];

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
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
        ],
    ],

    'plan' => [
        'title' => '套餐',
        'description' => '管理你的订阅套餐。',
        'label' => '套餐',
        'workspaces' => '{1}:count 个工作区|[2,*]:count 个工作区',
        'per_workspace' => '每个工作区',
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
        'credits_exhausted' => 'AI 额度已用完——你每月 :limit 的额度已用尽。请升级套餐或等到下个月。',
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

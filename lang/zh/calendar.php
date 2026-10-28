<?php

declare(strict_types=1);

return [
    'title' => '日历',
    'today' => '今天',
    'previous' => '上一个',
    'next' => '下一个',
    'days' => '3天',
    'week' => '周',
    'month' => '月',
    'new_post' => '新建帖子',
    'no_content' => '无内容',
    'more' => '还有 :count 项',
    'less' => '收起',
    'empty_day' => '这一天没有安排。',
    'status' => [
        'label' => '状态',
        'all' => '所有帖子',
        'drafts' => '草稿',
        'scheduled' => '已排期',
        'sent' => '已发送',
    ],
    'no_date' => '无日期',
    'undated' => [
        'title' => '无日期草稿',
        'description' => '没有排期日期的草稿。',
        'close' => '关闭',
        'empty' => '没有无日期草稿。',
        'draft' => '草稿',
        'no_channel' => '无频道',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => '将帖子移到自定义时间',
            'description' => '你正在将此帖子从队列时段 :from 移到自定义时间 :to,该时间不在你的发布时间内。',
            'unqueued' => '帖子将在你选择的新时间发布,但不会占用队列中的位置。',
            'dont_show_again' => '不再显示',
            'cancel' => '取消',
            'confirm' => '移到自定义时间',
        ],
        'error' => '无法移动此帖子。请重试。',
    ],
];

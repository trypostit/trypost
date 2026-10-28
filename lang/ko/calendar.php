<?php

declare(strict_types=1);

return [
    'title' => '캘린더',
    'today' => '오늘',
    'previous' => '이전',
    'next' => '다음',
    'days' => '3일',
    'week' => '주',
    'month' => '월',
    'new_post' => '새 게시물',
    'no_content' => '내용 없음',
    'more' => '+:count개 더보기',
    'less' => '접기',
    'empty_day' => '이 날 예정된 게시물이 없습니다.',
    'status' => [
        'label' => '상태',
        'all' => '모든 게시물',
        'drafts' => '임시 저장',
        'scheduled' => '예약됨',
        'sent' => '전송됨',
    ],
    'no_date' => '날짜 없음',
    'undated' => [
        'title' => '날짜 없는 임시 저장',
        'description' => '예약 날짜가 없는 임시 저장 게시물입니다.',
        'close' => '닫기',
        'empty' => '날짜 없는 임시 저장 게시물이 없습니다.',
        'draft' => '임시 저장',
        'no_channel' => '채널 없음',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => '게시물을 지정한 시간으로 이동',
            'description' => '이 게시물을 대기열 시간(:from)에서 게시 시간에 없는 지정 시간(:to)으로 이동합니다.',
            'unqueued' => '게시물은 선택한 새 시간에 게시되지만 대기열 자리는 차지하지 않습니다.',
            'dont_show_again' => '다시 표시하지 않기',
            'cancel' => '취소',
            'confirm' => '지정 시간으로 이동',
        ],
        'error' => '게시물을 이동하지 못했습니다. 다시 시도해 주세요.',
        'errors' => [
            'past' => '이미 지난 시간입니다. 이후 시간을 선택하세요.',
            'required' => '이 게시물의 시간을 선택하세요.',
            'pending' => '이 게시물은 승인 대기 중입니다. 승인되거나 거절된 후에 이동하세요.',
            'publishing_soon' => '이 게시물은 곧 게시되므로 더 이상 이동할 수 없습니다.',
            'too_far' => '2038년 1월 19일 이전 날짜를 선택하세요.',
        ],
        'drag_hint' => '끌어서 일정 변경',
        'drag_hint_undated' => ':channel의 날짜 없는 임시 저장 글을 끌어서 예약',
        'announce' => [
            'over' => ':from에 예약된 항목이 :target 위에 있습니다.',
            'over_undated' => ':channel의 날짜 없는 임시 저장 글이 :target 위에 있습니다.',
            'moved' => ':from에 예약된 항목을 :to(으)로 변경했습니다.',
            'returned' => '항목을 원래 위치로 되돌렸습니다.',
            'undated' => ':from에 예약된 항목을 날짜 없음으로 옮겼습니다.',
            'from_undated' => ':channel의 날짜 없는 임시 저장 글을 :to에 예약했습니다.',
            'date_time' => ':date :time',
        ],
        'toast' => [
            'moved' => '게시물을 :time(으)로 변경했습니다.',
            'moved_draft' => '임시 저장 글을 :time(으)로 변경했습니다.',
            'undated' => '임시 저장 글을 날짜 없음으로 옮겼습니다.',
            'unqueued_suffix' => '대기열 자리가 다시 비었습니다.',
        ],
    ],
];

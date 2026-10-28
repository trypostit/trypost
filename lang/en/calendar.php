<?php

declare(strict_types=1);

return [
    'title' => 'Calendar',
    'today' => 'Today',
    'previous' => 'Previous',
    'next' => 'Next',
    'days' => '3 days',
    'week' => 'Week',
    'month' => 'Month',
    'new_post' => 'New Post',
    'no_content' => 'No content',
    'more' => '+:count more',
    'less' => 'Show less',
    'empty_day' => 'Nothing planned for this day.',
    'status' => [
        'label' => 'Status',
        'all' => 'All posts',
        'drafts' => 'Drafts',
        'scheduled' => 'Scheduled',
        'sent' => 'Sent',
    ],
    'no_date' => 'No Date',
    'undated' => [
        'title' => 'Undated drafts',
        'description' => 'Drafts without a scheduled date.',
        'close' => 'Close',
        'empty' => 'No undated drafts.',
        'draft' => 'Draft',
        'no_channel' => 'No channel',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => 'Moving post to a custom time',
            'description' => 'You\'re moving this post from a queue slot at :from to a custom time, :to, which isn\'t one of your posting times.',
            'unqueued' => 'The post will be published at the new time you picked, but it won\'t use a spot in your queue.',
            'dont_show_again' => 'Don\'t show this again',
            'cancel' => 'Cancel',
            'confirm' => 'Move to custom time',
        ],
        'error' => 'Couldn\'t move this post. Try again.',
        'errors' => [
            'past' => 'This time has already passed. Pick a later time.',
            'required' => 'Pick a time for this post.',
            'pending' => 'This post is waiting for approval. Move it once it\'s approved or rejected.',
            'publishing_soon' => 'This post is about to be published, so it can\'t be moved anymore.',
            'too_far' => 'Pick a date before January 19, 2038.',
        ],
        'drag_hint' => 'Drag to reschedule',
        'drag_hint_undated' => 'Drag No Date draft for :channel to schedule',
        'announce' => [
            'over' => 'item scheduled for :from is over :target.',
            'over_undated' => 'No Date draft for :channel is over :target.',
            'moved' => 'item scheduled for :from was rescheduled to :to.',
            'returned' => 'Item was dropped back in its original position.',
            'undated' => 'item scheduled for :from was dropped on No Date.',
            'from_undated' => 'No Date draft for :channel was scheduled for :to.',
            'date_time' => ':date at :time',
        ],
        'toast' => [
            'moved' => 'Rescheduled your post for :time.',
            'moved_draft' => 'Rescheduled your draft for :time.',
            'undated' => 'Moved your draft to No Date.',
            'unqueued_suffix' => 'The queue slot is free again.',
        ],
    ],
];

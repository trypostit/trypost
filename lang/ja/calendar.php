<?php

declare(strict_types=1);

return [
    'title' => 'カレンダー',
    'today' => '今日',
    'previous' => '前へ',
    'next' => '次へ',
    'days' => '3日',
    'week' => '週',
    'month' => '月',
    'new_post' => '新規投稿',
    'no_content' => 'コンテンツなし',
    'more' => '他 :count 件',
    'less' => '閉じる',
    'empty_day' => 'この日の予定はありません。',
    'status' => [
        'label' => 'ステータス',
        'all' => 'すべての投稿',
        'drafts' => '下書き',
        'scheduled' => '予約済み',
        'sent' => '送信済み',
    ],
    'no_date' => '日付なし',
    'undated' => [
        'title' => '日付なしの下書き',
        'description' => '予約日が設定されていない下書きです。',
        'close' => '閉じる',
        'empty' => '日付なしの下書きはありません。',
        'draft' => '下書き',
        'no_channel' => 'チャンネルなし',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => '投稿を指定の時刻に移動',
            'description' => 'この投稿をキューの枠(:from)から、投稿時刻に含まれていない指定の時刻(:to)に移動します。',
            'unqueued' => '投稿は選択した新しい時刻に公開されますが、キューの枠は使用しません。',
            'dont_show_again' => '今後は表示しない',
            'cancel' => 'キャンセル',
            'confirm' => '指定の時刻に移動',
        ],
        'error' => '投稿を移動できませんでした。もう一度お試しください。',
        'errors' => [
            'past' => 'この時刻はすでに過ぎています。後の時刻を選んでください。',
            'required' => 'この投稿の時刻を選んでください。',
        ],
        'drag_hint' => 'ドラッグして予定を変更',
        'drag_hint_undated' => ':channel の日付なしの下書きをドラッグして予約',
        'announce' => [
            'over' => ':from に予定されている項目が :target の上にあります。',
            'moved' => ':from に予定されていた項目を :to に変更しました。',
            'returned' => '項目を元の位置に戻しました。',
            'undated' => ':from に予定されていた項目を「日付なし」に移しました。',
            'from_undated' => ':channel の日付なしの下書きを :to に予約しました。',
            'date_time' => ':date :time',
        ],
        'toast' => [
            'moved' => '投稿を :time に変更しました。',
            'moved_draft' => '下書きを :time に変更しました。',
            'undated' => '下書きを「日付なし」に移しました。',
            'unqueued_suffix' => 'キューの枠が再び空きました。',
        ],
    ],
];

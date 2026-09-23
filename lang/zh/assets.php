<?php

return [
    'title' => '素材库',

    'tabs' => [
        'my_uploads' => '我的上传',
        'stock_photos' => '图库照片',
        'gifs' => 'GIF',
    ],

    'upload' => [
        'drag_drop' => '将文件拖放到此处，或点击选择',
        'formats' => 'JPEG、PNG、GIF、WebP、MP4、PDF',
        'uploading' => '上传中…',
        'failed' => '无法上传 :file，请重试。',
        'file_too_large' => '文件大小超过允许的最大值(:max MB)。',
        'cancelled' => '上传已取消。',
    ],

    'empty' => [
        'title' => '暂无素材',
        'description' => '上传图片和视频，构建你的媒体库。',
    ],

    'save_to_assets' => '保存到素材库',
    'saved' => '已保存到你的素材库！',
    'create_post' => '创建帖子',
    'download' => '下载',
    'add_to_post' => '添加到帖子',
    'search_placeholder' => '搜索媒体…',

    'delete' => [
        'title' => '删除素材',
        'description' => '确定要删除此素材吗？此操作无法撤销。',
        'confirm' => '删除',
        'cancel' => '取消',
    ],

    'unsplash' => [
        'search_placeholder' => '搜索免费照片…',
        'no_results' => '未找到照片',
        'no_results_description' => '换一个搜索词试试。',
        'trending' => 'Unsplash 热门',
        'start_searching' => '在 Unsplash 上搜索免费图库照片',
    ],

    'giphy' => [
        'trending' => 'Giphy 热门',
        'search_placeholder' => '搜索 GIF…',
        'no_results' => '未找到 GIF',
        'no_results_description' => '换一个搜索词试试。',
        'powered_by' => '由 GIPHY 提供支持',
    ],

    'webdav' => [
        'up' => '上一级文件夹',
        'import' => '导入所选 :count 项',
        'loading' => '正在加载文件夹…',
        'empty' => '此文件夹为空。',
        'unreachable' => '无法访问该共享。',
        'import_failed' => '这些文件无法导入。',
        'import_partial' => '{1} :names 无法导入。|[2,*] :count 个文件无法导入：:names',
    ],
];

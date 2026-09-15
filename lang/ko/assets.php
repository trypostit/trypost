<?php

return [
    'title' => '에셋',

    'tabs' => [
        'my_uploads' => '내 업로드',
        'stock_photos' => '스톡 사진',
        'gifs' => 'GIF',
    ],

    'upload' => [
        'drag_drop' => '여기에 파일을 끌어다 놓거나 클릭하여 선택하세요',
        'formats' => 'JPEG, PNG, GIF, WebP, MP4, PDF',
        'uploading' => '업로드 중...',
        'failed' => ':file을(를) 업로드할 수 없습니다. 다시 시도해 주세요.',
        'file_too_large' => '파일 크기가 허용된 최대값(:max MB)을 초과했습니다.',
        'cancelled' => '업로드가 취소되었습니다.',
    ],

    'empty' => [
        'title' => '아직 에셋이 없습니다',
        'description' => '이미지와 동영상을 업로드하여 미디어 라이브러리를 만드세요.',
    ],

    'save_to_assets' => '에셋에 저장',
    'saved' => '에셋에 저장되었습니다!',
    'create_post' => '게시물 만들기',
    'download' => '다운로드',
    'add_to_post' => '게시물에 추가',
    'search_placeholder' => '미디어 검색...',

    'delete' => [
        'title' => '에셋 삭제',
        'description' => '이 에셋을 삭제하시겠습니까? 이 작업은 되돌릴 수 없습니다.',
        'confirm' => '삭제',
        'cancel' => '취소',
    ],

    'unsplash' => [
        'search_placeholder' => '무료 사진 검색...',
        'no_results' => '사진을 찾을 수 없습니다',
        'no_results_description' => '다른 검색어로 시도해 보세요.',
        'trending' => 'Unsplash 인기 사진',
        'start_searching' => 'Unsplash의 무료 스톡 사진을 검색하세요',
    ],

    'giphy' => [
        'trending' => 'Giphy 인기 GIF',
        'search_placeholder' => 'GIF 검색...',
        'no_results' => 'GIF를 찾을 수 없습니다',
        'no_results_description' => '다른 검색어로 시도해 보세요.',
        'powered_by' => 'Powered by GIPHY',
    ],

    'webdav' => [
        'up' => '상위 폴더',
        'import' => '선택한 :count개 가져오기',
        'loading' => '폴더를 불러오는 중...',
        'empty' => '이 폴더는 비어 있습니다.',
        'unreachable' => '공유에 연결할 수 없습니다.',
        'import_failed' => '해당 파일을 가져오지 못했습니다.',
        'import_partial' => '{1} :names을(를) 가져오지 못했습니다.|[2,*] :count개 파일을 가져오지 못했습니다: :names',
    ],
];

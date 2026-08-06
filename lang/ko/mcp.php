<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'TryPost 계정으로 게시물을 만들고 관리할 수 있도록 AI 어시스턴트를 연결하세요.',
    'copy_step' => 'TryPost 서버 URL 복사',
    'open_step' => 'AI 어시스턴트 열기',
    'copy' => 'URL 복사',
    'connect' => ':client로 연결',
    'step_add' => '아래 이름, URL 또는 설정을 앱에 붙여넣으세요. 처음 연결할 때 브라우저에서 로그인이 열립니다.',
    'name_label' => '이름',
    'url_label' => '서버 URL',
    'config_label' => '설정',
    'connected_title' => '연결된 앱',
    'connected_description' => '로그인한 어시스턴트입니다. 더 이상 쓰지 않는 연결은 해제할 수 있습니다.',
    'connected_empty' => '아직 연결된 앱이 없습니다. 위의 Claude, ChatGPT 또는 다른 클라이언트를 사용하세요.',
    'disconnect' => '연결 해제',
    'disconnect_title' => '앱 연결 해제',
    'disconnect_confirm' => 'TryPost에서 앱 로그인을 해제합니다. MCP를 다시 쓰려면 다시 연결해야 합니다.',
    'disconnected' => '앱 연결이 해제되었습니다.',
    'copied' => '복사됨',
    'last_used' => '최근 사용',
    'never' => '없음',
    'documentation_title' => '문서',
    'documentation_description' => '클라이언트별 설정 가이드, 사용 가능한 도구, 문제 해결.',
    'view_docs' => '문서 보기',
    'connector_name' => 'TryPost',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => '다른 앱',
    'other_clients_description' => 'Cursor, VS Code, Claude Code 및 MCP를 지원하는 모든 앱.',

    'clients' => [
        'claude' => 'Settings → Connectors를 열고 커스텀 커넥터를 추가한 뒤 위 URL을 붙여넣으세요.',
        'chatgpt' => 'Settings → Apps & Connectors를 열고 커스텀 커넥터를 만든 뒤 위 URL을 붙여넣으세요.',
        'cursor' => 'Cursor에서 TryPost를 원격 MCP 서버로 추가하세요.',
        'cursor_name' => 'Cursor',
        'vscode' => '아래 설정을 VS Code MCP 설정에 붙여넣으세요.',
        'vscode_name' => 'VS Code',
        'claude_code' => '아래 설정을 Claude Code MCP 설정에 붙여넣으세요.',
        'claude_code_name' => 'Claude Code',
        'other' => 'mcpServers 설정을 읽는 모든 클라이언트에서 동작합니다.',
        'other_name' => '기타',
    ],
];

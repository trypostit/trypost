<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    */

    'redirect_domains' => [
        '*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients use private-use URI schemes (RFC 8252)
    | for redirect callbacks — e.g. Cursor registers
    | cursor://anysphere.cursor-mcp/oauth/callback. HTTP(S) and loopback
    | redirects (Claude.ai, ChatGPT, Hermes, OpenCode, Codex CLI, …) are
    | already accepted via redirect_domains. This list is the scheme
    | allowlist for the native-app case.
    |
    */

    'custom_schemes' => [
        // Desktop IDEs / agent hosts
        'antigravity',
        'cursor',
        'jetbrains',
        'kiro',
        'trae',
        'vscode',
        'vscode-insiders',
        'windsurf',
        'zed',

        // Anthropic
        'claude',
        'claude-cli',
        'claude-code',
        'claude-desktop',
        'claudeai',

        // OpenAI / xAI
        'chatgpt',
        'codex',
        'grok',
        'xai',

        // Agent CLIs and hosts
        'codium',
        'continue',
        'devin',
        'goose',
        'hermes',
        'lmstudio',
        'opencode',
        'raycast',
        'warp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

];

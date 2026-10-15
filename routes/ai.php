<?php

declare(strict_types=1);

use App\Http\Middleware\Mcp\AuthenticateMcp;
use App\Http\Middleware\Mcp\ChallengeChatGptToolCalls;
use App\Http\Middleware\Mcp\LogMcpClient;
use App\Mcp\Servers\TryPostServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware('throttle:mcp-oauth-registration')->group(function () {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp/trypost', TryPostServer::class)
    ->middleware([LogMcpClient::class, ChallengeChatGptToolCalls::class, AuthenticateMcp::class.':api', 'workspace.token:mcp'])
    ->name('mcp.trypost');

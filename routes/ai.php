<?php

declare(strict_types=1);

use App\Http\Middleware\Mcp\AuthenticateMcp;
use App\Http\Middleware\Mcp\ChallengeChatGptToolCalls;
use App\Http\Middleware\Mcp\LoadMcpWorkspace;
use App\Mcp\Servers\TryPostServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

Mcp::oauthRoutes();

Route::getRoutes()
    ->getByAction(OAuthRegisterController::class)
    ?->middleware('throttle:mcp-oauth-registration');

Mcp::web('/mcp/trypost', TryPostServer::class)
    ->middleware([ChallengeChatGptToolCalls::class, AuthenticateMcp::class.':api', LoadMcpWorkspace::class])
    ->name('mcp.trypost');

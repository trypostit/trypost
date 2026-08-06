<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware('throttle:mcp-oauth-registration')->group(function (): void {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp/trypost', TryPostServer::class)
    ->middleware(['auth:api', 'workspace.token:mcp'])
    ->name('mcp.trypost');

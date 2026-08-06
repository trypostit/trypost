<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

// Dynamic client registration is the abuse surface — throttle it alone so
// well-known discovery stays unthrottled. Mcp::oauthRoutes() already registered
// POST oauth/register; append the limiter onto that existing route.
foreach (Route::getRoutes() as $route) {
    if ($route->uri() === 'oauth/register' && in_array('POST', $route->methods(), true)) {
        $route->middleware('throttle:mcp-oauth-registration');
        break;
    }
}

Mcp::web('/mcp/trypost', TryPostServer::class)
    ->middleware(['auth:api', 'workspace.token:mcp'])
    ->name('mcp.trypost');

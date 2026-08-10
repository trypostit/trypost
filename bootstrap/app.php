<?php

declare(strict_types=1);

use App\Http\Middleware\Api\LoadWorkspaceFromToken;
use App\Http\Middleware\App\EnsureRegistrationEnabled;
use App\Http\Middleware\App\HandleInertiaRequests;
use App\Http\Middleware\App\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->encryptCookies(except: ['sidebar_state', 'locale']);

        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'workspace.token' => LoadWorkspaceFromToken::class,
            'registration.enabled' => EnsureRegistrationEnabled::class,
        ]);

        $middleware->preventRequestForgery(except: [
            'stripe/*',
            'telegram/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportWhen(function (Throwable $e) {
            return $e instanceof OAuthServerException && $e->getHttpStatusCode() < 500;
        });

        $exceptions->renderable(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->expectsJson()) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? null;
                $message = $retryAfter
                    ? "Rate limit exceeded. Please retry after {$retryAfter} seconds."
                    : 'Rate limit exceeded. Please try again later.';

                return response()->json([
                    'name' => 'rate_limit_exceeded',
                    'message' => $message,
                ], 429)->withHeaders($e->getHeaders());
            }
        });

        $exceptions->render(function (DomainException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    })->create();

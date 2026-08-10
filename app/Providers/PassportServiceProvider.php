<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AccessToken;
use App\Passport\AccessTokenRepository;
use App\Passport\AuthCode;
use App\Passport\AuthCodeRepository;
use App\Passport\AuthorizationController;
use App\Passport\AuthorizationView;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\AccessTokenRepository as PassportAccessTokenRepository;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Http\Controllers\DeviceAuthorizationController;
use Laravel\Passport\Passport;

class PassportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PassportAuthCodeRepository::class, AuthCodeRepository::class);
        $this->app->bind(PassportAccessTokenRepository::class, AccessTokenRepository::class);
        $this->app->bind(PassportAuthorizationController::class, AuthorizationController::class);

        // Passport's contextual guard binding targets its own controller class;
        // repeat it for our override so authorize() still receives the web guard.
        $this->app->when([
            AuthorizationController::class,
            DeviceAuthorizationController::class,
        ])->needs(StatefulGuard::class)->give(
            fn () => Auth::guard(config('passport.guard', null)),
        );
    }

    public function boot(): void
    {
        Passport::useTokenModel(AccessToken::class);
        Passport::useAuthCodeModel(AuthCode::class);

        // API keys may omit an application expiry ("never"). Passport still
        // embeds a JWT `exp`, so keep that far ahead and enforce optional
        // `oauth_access_tokens.expires_at` in LoadWorkspaceFromToken.
        Passport::personalAccessTokensExpireIn(now()->addYears(100));

        Passport::tokensCan([
            'mcp:use' => 'Use MCP server',
        ]);

        Passport::authorizationView(
            fn (array $parameters) => $this->app->make(AuthorizationView::class)($parameters),
        );
    }
}

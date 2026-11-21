<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class XController extends SocialController
{
    protected string $driver = 'x';

    protected SocialPlatform $platform = SocialPlatform::X;

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        return $this->redirectToProvider($request, $this->driver, $this->requestedScopes());
    }

    public function callback(Request $request): RedirectResponse
    {
        return $this->handleCallback($request, $this->driver, $this->requestedScopes());
    }

    protected function afterConnected(SocialAccount $account): void
    {
        rescue(fn (): bool => app(ConnectionVerifier::class)->verifyAccessToken($account), report: false);
    }
}

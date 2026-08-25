<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use Illuminate\Http\Request;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class DiscordController extends SocialController
{
    protected string $driver = 'discord';

    protected SocialPlatform $platform = SocialPlatform::Discord;

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        return $this->redirectToProvider($request, $this->driver, config('trypost.platforms.discord.scopes'));
    }

    public function callback(Request $request): InertiaResponse
    {
        return $this->handleCallback($request, $this->driver);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FinishSocialConnectionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The confirmation page every redirect-based connection lands on, handed to
 * the controller of the network being connected.
 */
class SocialConnectionController extends Controller
{
    public function show(Request $request, Platform $platform): Response
    {
        return $this->network($platform)->confirm($request);
    }

    public function store(FinishSocialConnectionRequest $request, Platform $platform): RedirectResponse
    {
        return $this->network($platform)->finish($request);
    }

    private function network(Platform $platform): SocialController
    {
        return app(match ($platform) {
            Platform::LinkedIn => LinkedInController::class,
            Platform::X => XController::class,
            Platform::TikTok => TikTokController::class,
            Platform::YouTube => YouTubeController::class,
            Platform::Facebook => FacebookController::class,
            Platform::Instagram => InstagramController::class,
            Platform::InstagramFacebook => InstagramFacebookController::class,
            Platform::Threads => ThreadsController::class,
            Platform::Pinterest => PinterestController::class,
            Platform::Bluesky => BlueskyController::class,
            Platform::Mastodon => MastodonController::class,
            Platform::Discord => DiscordController::class,
            Platform::GoogleBusiness => GoogleBusinessController::class,
            Platform::Vk => VkController::class,
            Platform::LinkedInPage, Platform::Telegram => abort(SymfonyResponse::HTTP_NOT_FOUND),
        });
    }
}

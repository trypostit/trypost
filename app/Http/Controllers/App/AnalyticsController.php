<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\GoogleBusinessProfilePublishException;
use App\Exceptions\TokenExpiredException;
use App\Http\Controllers\Controller;
use App\Models\GoogleBusinessProfileLocation;
use App\Models\SocialAccount;
use App\Services\Social\FacebookAnalytics;
use App\Services\Social\GoogleBusinessProfile\GoogleBusinessProfileAnalytics;
use App\Services\Social\InstagramAnalytics;
use App\Services\Social\LinkedInPageAnalytics;
use App\Services\Social\PinterestAnalytics;
use App\Services\Social\Telegram\TelegramAnalytics;
use App\Services\Social\ThreadsAnalytics;
use App\Services\Social\TikTokAnalytics;
use App\Services\Social\XAnalytics;
use App\Services\Social\YouTubeAnalytics;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AnalyticsController extends Controller
{
    private const SUPPORTED_PLATFORMS = [
        Platform::TikTok,
        Platform::Instagram,
        Platform::InstagramFacebook,
        Platform::Threads,
        Platform::Facebook,
        Platform::X,
        Platform::LinkedInPage,
        Platform::Pinterest,
        Platform::YouTube,
        Platform::GoogleBusinessProfile,
        Platform::Telegram,
    ];

    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('view', $workspace);

        $accounts = $workspace->socialAccounts()
            ->where('is_active', true)
            ->whereIn('platform', self::SUPPORTED_PLATFORMS)
            ->get()
            ->flatMap(function (SocialAccount $account): array {
                if ($account->platform === Platform::GoogleBusinessProfile) {
                    return $account->googleBusinessProfileLocations()
                        ->where('is_selected', true)
                        ->orderBy('title')
                        ->get()
                        ->map(fn (GoogleBusinessProfileLocation $location): array => [
                            'id' => 'gbp-location-'.$location->id,
                            'account_id' => $account->id,
                            'location_id' => $location->id,
                            'platform' => $account->platform->value,
                            'username' => null,
                            'display_label' => $location->title,
                            'avatar_url' => $account->avatar_url,
                        ])->all();
                }

                return [[
                    'id' => $account->id,
                    'account_id' => $account->id,
                    'location_id' => null,
                    'platform' => $account->platform->value,
                    'username' => $account->username,
                    'display_label' => $account->display_label,
                    'avatar_url' => $account->avatar_url,
                ]];
            })->values();

        return Inertia::render('analytics/Index', [
            'accounts' => $accounts,
        ]);
    }

    public function show(Request $request, SocialAccount $account): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if ($account->workspace_id !== $workspace->id) {
            abort(HttpResponse::HTTP_FORBIDDEN);
        }

        $since = $request->has('since') ? Carbon::parse($request->input('since')) : null;
        $until = $request->has('until') ? Carbon::parse($request->input('until')) : null;

        $location = null;
        if ($account->platform === Platform::GoogleBusinessProfile && $request->filled('location_id')) {
            $location = $account->googleBusinessProfileLocations()
                ->where('is_selected', true)
                ->findOrFail($request->string('location_id')->toString());
        }

        $metrics = $this->metricsFor($account, $since, $until, $location);

        return response()->json(['metrics' => $metrics]);
    }

    /**
     * An unreachable platform is not a server error — empty numbers beat a 500
     * on a page the user just opened. Narrow on purpose: catching Throwable
     * would render a defect as "this account has no activity".
     *
     * @return array<int, array{label: string, value: int|string}>
     */
    private function metricsFor(
        SocialAccount $account,
        ?Carbon $since,
        ?Carbon $until,
        ?GoogleBusinessProfileLocation $googleBusinessProfileLocation = null,
    ): array {
        try {
            return match ($account->platform) {
                Platform::TikTok => app(TikTokAnalytics::class)->getMetrics($account),
                Platform::Instagram, Platform::InstagramFacebook => app(InstagramAnalytics::class)->getMetrics($account, $since, $until),
                Platform::Threads => app(ThreadsAnalytics::class)->getMetrics($account, $since, $until),
                Platform::Facebook => app(FacebookAnalytics::class)->getMetrics($account, $since, $until),
                Platform::X => app(XAnalytics::class)->getMetrics($account, $since, $until),
                Platform::LinkedInPage => app(LinkedInPageAnalytics::class)->getMetrics($account, $since, $until),
                Platform::Pinterest => app(PinterestAnalytics::class)->getMetrics($account, $since, $until),
                Platform::YouTube => app(YouTubeAnalytics::class)->getMetrics($account, $since, $until),
                Platform::GoogleBusinessProfile => app(GoogleBusinessProfileAnalytics::class)->getMetrics($account, $since, $until, $googleBusinessProfileLocation),
                Platform::Telegram => app(TelegramAnalytics::class)->getMetrics($account),
                default => [],
            };
        } catch (GoogleBusinessProfilePublishException|PlatformUnavailableException|TokenExpiredException|ConnectionException $e) {
            report($e);

            return [];
        }
    }
}

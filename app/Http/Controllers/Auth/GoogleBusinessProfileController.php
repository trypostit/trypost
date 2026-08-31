<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\GoogleBusinessProfile\GoogleBusinessProfileApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Symfony\Component\HttpFoundation\Response;

class GoogleBusinessProfileController extends SocialController
{
    protected SocialPlatform $platform = SocialPlatform::GoogleBusinessProfile;

    protected array $scopes = ['https://www.googleapis.com/auth/business.manage'];

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('manageAccounts', $workspace);

        session([
            'social_connect_workspace' => $workspace->id,
            'social_reconnect_id' => null,
            'google_business_profile_account_id' => null,
        ]);

        return Inertia::location($this->provider()
            ->scopes($this->scopes)
            ->with([
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
            ])
            ->redirect()
            ->getTargetUrl());
    }

    public function callback(Request $request, GoogleBusinessProfileApi $api): InertiaResponse|RedirectResponse
    {
        if ($request->filled('error')) {
            return $this->popupCallback(false, __('accounts.popup_callback.failed_to_authenticate'), $this->platform->value);
        }

        $workspace = $this->workspaceFromSession($request);
        if (! $workspace) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        try {
            $googleUser = $this->provider()->user();
            $accounts = $api->accounts($googleUser->token);
            $locations = collect($accounts)
                ->flatMap(fn (array $account): array => collect($api->locations($googleUser->token, (string) data_get($account, 'name')))
                    ->map(fn (array $location): array => [...$location, 'googleAccountName' => data_get($account, 'name')])
                    ->all())
                ->values();

            if ($locations->isEmpty()) {
                return $this->popupCallback(false, __('accounts.popup_callback.no_google_business_profile_locations'), $this->platform->value);
            }

            $socialAccount = DB::transaction(function () use ($workspace, $googleUser, $locations): SocialAccount {
                $socialAccount = $workspace->socialAccounts()->firstOrNew([
                    'platform' => $this->platform->value,
                    'platform_user_id' => $googleUser->getId(),
                ]);
                $socialAccount->fill([
                    'username' => $googleUser->getEmail(),
                    'display_name' => $googleUser->getName() ?: $googleUser->getEmail(),
                    'access_token' => $googleUser->token,
                    'token_expires_at' => $googleUser->expiresIn ? now()->addSeconds($googleUser->expiresIn) : null,
                    'scopes' => $this->scopes,
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                    'meta' => ['google_user_id' => $googleUser->getId()],
                ]);

                // Google may omit the refresh token on a repeat authorization.
                // Keep the previously issued token instead of making the account
                // unable to refresh as soon as the new access token expires.
                if (filled($googleUser->refreshToken)) {
                    $socialAccount->refresh_token = $googleUser->refreshToken;
                }
                $socialAccount->save();

                $seen = [];
                foreach ($locations as $location) {
                    $name = (string) data_get($location, 'name');
                    $seen[] = $name;
                    $socialAccount->googleBusinessProfileLocations()->updateOrCreate(
                        ['google_location_name' => $name],
                        [
                            'google_account_name' => data_get($location, 'googleAccountName'),
                            'title' => data_get($location, 'title'),
                            'store_code' => data_get($location, 'storeCode'),
                            'timezone' => data_get($location, 'metadata.timezone'),
                            'maps_uri' => data_get($location, 'metadata.mapsUri'),
                            'website_uri' => data_get($location, 'websiteUri'),
                            'phone_number' => data_get($location, 'phoneNumbers.primaryPhone'),
                            'storefront_address' => data_get($location, 'storefrontAddress'),
                            'metadata' => data_get($location, 'metadata'),
                            'is_verified' => (bool) data_get($location, 'metadata.hasVoiceOfMerchant', false),
                            'last_synced_at' => now(),
                        ],
                    );
                }

                $socialAccount->googleBusinessProfileLocations()
                    ->whereNotIn('google_location_name', $seen)
                    ->update(['is_selected' => false]);

                return $socialAccount;
            });

            session(['google_business_profile_account_id' => $socialAccount->id]);

            return redirect()->route('app.social.google-business-profile.select-locations');
        } catch (NetworkAlreadyConnectedException) {
            return $this->popupCallback(false, __('accounts.popup_callback.network_taken'), $this->platform->value);
        } catch (\Throwable $e) {
            Log::error('Google Business Profile OAuth error', ['error' => $e->getMessage()]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting_google_business_profile'), $this->platform->value);
        }
    }

    public function selectLocations(Request $request): InertiaResponse
    {
        $account = $this->accountFromSession($request);
        if (! $account) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        return Inertia::render('accounts/GoogleBusinessProfileLocationSelect', [
            'locations' => $account->googleBusinessProfileLocations()
                ->orderBy('title')
                ->get(['id', 'title', 'store_code', 'storefront_address', 'maps_uri', 'is_selected']),
        ]);
    }

    public function select(Request $request): InertiaResponse
    {
        $account = $this->accountFromSession($request);
        if (! $account) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $validated = $request->validate([
            'location_ids' => ['required', 'array', 'min:1'],
            'location_ids.*' => [
                'uuid',
                Rule::exists('google_business_profile_locations', 'id')->where('social_account_id', $account->id),
            ],
        ]);

        DB::transaction(function () use ($account, $validated): void {
            $deselectedLocationIds = $account->googleBusinessProfileLocations()
                ->whereNotIn('id', $validated['location_ids'])
                ->pluck('id');

            $targetsToDisable = PostPlatform::query()
                ->whereIn('google_business_profile_location_id', $deselectedLocationIds)
                ->where('status', PostPlatformStatus::Pending)
                ->whereHas('post', fn ($query) => $query->whereIn('status', [PostStatus::Draft, PostStatus::Scheduled]));
            $candidatePostIds = (clone $targetsToDisable)->pluck('post_id');
            Post::query()->whereIn('id', $candidatePostIds)->lockForUpdate()->get(['id']);

            $affectedPostIds = (clone $targetsToDisable)->pluck('post_id');
            $targetsToDisable->update(['enabled' => false]);

            Post::query()
                ->whereIn('id', $affectedPostIds)
                ->where('status', PostStatus::Scheduled)
                ->whereDoesntHave('postPlatforms', fn ($query) => $query->enabled())
                ->update(['status' => PostStatus::Draft, 'scheduled_at' => null]);

            $account->googleBusinessProfileLocations()->update(['is_selected' => false]);
            $account->googleBusinessProfileLocations()
                ->whereIn('id', $validated['location_ids'])
                ->update(['is_selected' => true]);
        });

        session()->forget(['google_business_profile_account_id', 'social_connect_workspace', 'social_reconnect_id']);

        return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
    }

    private function provider(): GoogleProvider
    {
        /** @var GoogleProvider $provider */
        $provider = Socialite::buildProvider(GoogleProvider::class, config('services.google-business-profile'));

        return $provider;
    }

    private function workspaceFromSession(Request $request): ?Workspace
    {
        $workspace = Workspace::find(session('social_connect_workspace'));

        return $workspace && $request->user()->can('manageAccounts', $workspace) ? $workspace : null;
    }

    private function accountFromSession(Request $request): ?SocialAccount
    {
        $workspace = $this->workspaceFromSession($request);
        if (! $workspace) {
            return null;
        }

        return $workspace->socialAccounts()
            ->where('platform', $this->platform)
            ->find(session('google_business_profile_account_id'));
    }
}

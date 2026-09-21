<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\ConnectPopupException;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Http\Requests\Auth\SelectGoogleBusinessLocationRequest;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\GoogleBusinessPublisher;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class GoogleBusinessController extends SocialController
{
    protected string $driver = 'google-business';

    protected SocialPlatform $platform = SocialPlatform::GoogleBusiness;

    protected array $scopes = [
        'https://www.googleapis.com/auth/userinfo.profile',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/business.manage',
    ];

    private const string OAUTH_SESSION = 'google_business_oauth';

    public function __construct(private readonly GoogleBusinessPublisher $publisher) {}

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        return $this->redirectToProvider($request, $this->driver, $this->scopes, [
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ]);
    }

    public function callback(Request $request): InertiaResponse|RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);
        $reconnect = $this->reconnectAccount($workspace);

        try {
            $socialUser = Socialite::driver($this->driver)->user();
            $oauth = [
                'access_token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'expires_in' => $socialUser->expiresIn,
                'user_id' => $socialUser->getId(),
                'reconnect_id' => $reconnect?->id,
            ];

            $locations = $this->publisher->fetchLocations($socialUser->token);

            if (empty($locations)) {
                return $this->popupCallback(false, __('accounts.popup_callback.no_google_business_locations'), $this->platform->value);
            }

            $locations = $this->filterConnectableIdentities($workspace, $locations, 'id', $reconnect);

            if (empty($locations)) {
                return $this->noConnectableIdentities($reconnect, 'location_not_found');
            }

            if (count($locations) === 1) {
                $this->connectLocation($workspace, $locations[0], $oauth, $reconnect);

                return $this->connectedCallback($reconnect);
            }

            session([self::OAUTH_SESSION => [...$oauth, 'locations' => $locations]]);

            return redirect()->route('app.social.google-business.select-location');
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (Exception $e) {
            Log::error('Google Business Profile OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    public function selectLocation(Request $request): InertiaResponse
    {
        $oauth = $this->requireOauthSession();
        $workspace = $this->connectWorkspace($request);
        $locations = data_get($oauth, 'locations', []);

        if (empty($locations)) {
            $this->forgetOauthSession();

            return $this->popupCallback(false, __('accounts.popup_callback.no_google_business_locations'), $this->platform->value);
        }

        return Inertia::render('accounts/GoogleBusinessLocationSelect', [
            'workspace' => $workspace,
            'locations' => $locations,
        ]);
    }

    public function select(SelectGoogleBusinessLocationRequest $request): InertiaResponse
    {
        $oauth = $this->requireOauthSession();
        $workspace = $this->connectWorkspace($request);

        try {
            $location = collect(data_get($oauth, 'locations'))
                ->firstWhere('id', $request->validated('location_id'));

            if (! $location) {
                return $this->popupCallback(false, __('accounts.popup_callback.location_not_found'), $this->platform->value);
            }

            $reconnect = $this->reconnectAccount($workspace, data_get($oauth, 'reconnect_id'));

            $this->connectLocation($workspace, $location, $oauth, $reconnect);

            return $this->connectedCallback($reconnect);
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (Exception $e) {
            Log::error('Google Business Profile location selection error', [
                'error' => $e->getMessage(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting_location'), $this->platform->value);
        } finally {
            $this->forgetOauthSession();
        }
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  array<string, mixed>  $oauth
     */
    private function connectLocation(Workspace $workspace, array $location, array $oauth, ?SocialAccount $reconnect = null): void
    {
        $location['photo'] = $this->publisher->fetchLocationPhoto(
            (string) data_get($oauth, 'access_token'),
            (string) data_get($location, 'id'),
        );

        $attributes = $this->locationAttributes($location, $oauth);

        if ($reconnect !== null && blank(data_get($attributes, 'refresh_token'))) {
            $attributes['refresh_token'] = $reconnect->refresh_token;
        }

        SocialAccount::connectIdentity(
            $workspace,
            $this->platform,
            (string) data_get($location, 'id'),
            [
                ...$attributes,
                'status' => Status::Connected,
                'error_message' => null,
                'disconnected_at' => null,
            ],
            $reconnect,
        );
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  array<string, mixed>  $oauth
     * @return array<string, mixed>
     */
    private function locationAttributes(array $location, array $oauth): array
    {
        $title = data_get($location, 'title');

        return [
            'username' => $title,
            'display_name' => $title,
            'avatar_url' => uploadFromUrl(data_get($location, 'photo')),
            'access_token' => data_get($oauth, 'access_token'),
            'refresh_token' => data_get($oauth, 'refresh_token'),
            'token_expires_at' => data_get($oauth, 'expires_in')
                ? now()->addSeconds((int) data_get($oauth, 'expires_in'))
                : null,
            'scopes' => $this->scopes,
            'meta' => [
                'location_id' => data_get($location, 'id'),
                'account_name' => data_get($location, 'account_name'),
                'location_name' => data_get($location, 'location_name'),
                'maps_uri' => data_get($location, 'maps_uri'),
                'google_user_id' => data_get($oauth, 'user_id'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOauthSession(): array
    {
        $oauth = session(self::OAUTH_SESSION);

        if (! is_array($oauth)) {
            throw new ConnectPopupException('session_expired', $this->platform);
        }

        return $oauth;
    }

    private function forgetOauthSession(): void
    {
        session()->forget(self::OAUTH_SESSION);
    }
}

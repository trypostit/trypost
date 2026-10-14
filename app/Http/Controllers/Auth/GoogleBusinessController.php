<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Models\SocialAccount;
use App\Services\Social\GoogleBusinessPublisher;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

    public function __construct(private readonly GoogleBusinessPublisher $publisher) {}

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        return $this->redirectToProvider($request, $this->driver, $this->scopes, [
            'access_type' => 'offline',
            'prompt' => $this->switchingAccount($request) ? 'select_account consent' : 'consent',
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($this->driver)->user();

            $scopes = $this->reportedScopes($socialUser->approvedScopes, $this->scopes);
            $refusal = $this->refusalForMissingPublishScopes($scopes);

            if ($refusal !== null) {
                return $refusal;
            }

            $locations = $this->publisher->fetchLocations($socialUser->token);

            if (empty($locations)) {
                return $this->failConnection('no_google_business_locations');
            }

            $oauth = [
                'access_token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'expires_in' => $socialUser->expiresIn,
                'user_id' => $socialUser->getId(),
                'scopes' => $scopes,
            ];

            return $this->offerIdentities(
                $workspace,
                array_map(fn (array $location): array => PendingConnection::identity(
                    $this->platform,
                    (string) data_get($location, 'id'),
                    data_get($location, 'title'),
                    null,
                    null,
                    $this->platform->identityType()->value,
                    $this->locationAttributes($location, $oauth),
                ), $locations),
                $this->reconnectAccount($workspace),
                'location_not_found',
            );
        } catch (Exception $e) {
            Log::error('Google Business Profile OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->failConnection('error_connecting');
        }
    }

    /**
     * The location photo is read only for the locations the user picked, and a
     * reconnect keeps its refresh token when Google sends no new one.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    protected function accountValues(array $identity, ?SocialAccount $reconnect): array
    {
        $photo = $this->publisher->fetchLocationPhoto(
            (string) data_get($identity, 'attributes.access_token'),
            (string) data_get($identity, 'platform_user_id'),
        );

        $values = parent::accountValues([...$identity, 'avatar' => $photo], $reconnect);

        if ($reconnect !== null && blank(data_get($values, 'refresh_token'))) {
            $values['refresh_token'] = $reconnect->refresh_token;
        }

        return $values;
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
            'access_token' => data_get($oauth, 'access_token'),
            'refresh_token' => data_get($oauth, 'refresh_token'),
            'token_expires_at' => data_get($oauth, 'expires_in')
                ? now()->addSeconds((int) data_get($oauth, 'expires_in'))
                : null,
            'scopes' => data_get($oauth, 'scopes', $this->scopes),
            'meta' => [
                'location_id' => data_get($location, 'id'),
                'account_name' => data_get($location, 'account_name'),
                'location_name' => data_get($location, 'location_name'),
                'maps_uri' => data_get($location, 'maps_uri'),
                'google_user_id' => data_get($oauth, 'user_id'),
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Models\SocialAccount;
use App\Services\Social\LinkedInOrganizations;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class LinkedInController extends SocialController
{
    protected string $driver = 'linkedin-openid';

    protected SocialPlatform $platform = SocialPlatform::LinkedIn;

    /**
     * The LinkedIn card stands for both the personal profile and company-page
     * capabilities, each independently toggleable so self-hosters can run with
     * just one. The connect flow is available while either is enabled.
     */
    protected function ensurePlatformEnabled(): void
    {
        if (! $this->personEnabled() && ! $this->organizationEnabled()) {
            abort(Response::HTTP_FORBIDDEN, 'This platform is currently unavailable.');
        }
    }

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $this->rememberConnectSession($request, $workspace);

        return Inertia::location(
            Socialite::driver($this->driver)
                ->scopes($this->connectScopes())
                ->redirect()
                ->getTargetUrl()
        );
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($this->driver)->user();

            $credentials = [
                'access_token' => $socialUser->token,
                'refresh_token' => $socialUser->refreshToken,
                'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                'scopes' => $this->normalizeScopes($socialUser->approvedScopes ?? []),
            ];

            $person = [
                'id' => (string) $socialUser->getId(),
                'name' => $socialUser->getName(),
                'avatar' => $socialUser->getAvatar(),
                'vanity_name' => $this->personEnabled() ? $this->fetchVanityName($socialUser->token) : null,
            ];

            $identities = [
                ...($this->personEnabled() ? [$this->personIdentity($person, $credentials)] : []),
                ...array_map(
                    fn (array $organization): array => $this->organizationIdentity($organization, $person, $credentials),
                    $this->organizationEnabled() ? (LinkedInOrganizations::administeredBy($socialUser->token) ?? []) : [],
                ),
            ];

            if ($identities === []) {
                return $this->failConnection('not_linkedin_admin');
            }

            $reconnect = $this->reconnectAccount($workspace);

            return $this->offerIdentities(
                $workspace,
                $identities,
                $reconnect,
                $reconnect?->platform === SocialPlatform::LinkedInPage ? 'page_not_found' : 'wrong_account',
            );
        } catch (Exception $e) {
            Log::error('LinkedIn OAuth Error', [
                'error' => $e->getMessage(),
            ]);

            return $this->failConnection('error_connecting');
        }
    }

    /**
     * A capability switched off between the consent screen and "Finish
     * connection" stops the connection.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    protected function accountValues(array $identity, ?SocialAccount $reconnect): array
    {
        if (! SocialPlatform::from((string) data_get($identity, 'platform'))->isEnabled()) {
            throw new RuntimeException('This LinkedIn capability is no longer available.');
        }

        return parent::accountValues($identity, $reconnect);
    }

    /**
     * The member's personal profile becomes a `linkedin` account.
     *
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function personIdentity(array $person, array $credentials): array
    {
        return PendingConnection::identity(
            SocialPlatform::LinkedIn,
            (string) data_get($person, 'id'),
            data_get($person, 'name'),
            data_get($person, 'vanity_name'),
            data_get($person, 'avatar'),
            SocialPlatform::LinkedIn->identityType()->value,
            [
                ...$credentials,
                'username' => data_get($person, 'vanity_name'),
                'display_name' => data_get($person, 'name'),
            ],
        );
    }

    /**
     * A company the member administers becomes a `linkedin-page` account, with the
     * acting member recorded in meta so the page publisher can post on its behalf.
     * Only the admin-verified list read here is ever offered.
     *
     * @param  array<string, mixed>  $organization
     * @param  array<string, mixed>  $person
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function organizationIdentity(array $organization, array $person, array $credentials): array
    {
        $organizationId = data_get($organization, 'id');

        return PendingConnection::identity(
            SocialPlatform::LinkedInPage,
            (string) $organizationId,
            data_get($organization, 'name'),
            data_get($organization, 'vanity_name'),
            data_get($organization, 'logo'),
            SocialPlatform::LinkedInPage->identityType()->value,
            [
                ...$credentials,
                'username' => data_get($organization, 'vanity_name'),
                'display_name' => data_get($organization, 'name'),
                'meta' => [
                    'organization_id' => $organizationId,
                    'admin_user_id' => data_get($person, 'id'),
                    'admin_name' => data_get($person, 'name'),
                ],
            ],
        );
    }

    /**
     * Union of the scopes for the enabled capabilities, so one consent screen
     * grants exactly what the workspace can use — member posting, company-page
     * administration, or both — and the user picks the identity afterwards.
     *
     * @return array<int, string>
     */
    private function connectScopes(): array
    {
        $scopes = [];

        if ($this->personEnabled()) {
            $scopes = array_merge($scopes, config('trypost.platforms.linkedin.scopes'));
        }

        if ($this->organizationEnabled()) {
            $scopes = array_merge($scopes, config('trypost.platforms.linkedin-page.scopes'));
        }

        return array_values(array_unique($scopes));
    }

    private function personEnabled(): bool
    {
        return SocialPlatform::LinkedIn->isEnabled();
    }

    private function organizationEnabled(): bool
    {
        return SocialPlatform::LinkedInPage->isEnabled();
    }

    /**
     * LinkedIn returns approved scopes comma-joined, but Socialite splits OAuth
     * scopes on space — so the whole CSV lands as a single array element. Re-split
     * on commas to store individual scope tokens.
     *
     * @param  array<int, string>  $approvedScopes
     * @return array<int, string>
     */
    private function normalizeScopes(array $approvedScopes): array
    {
        return array_values(array_filter(explode(',', implode(',', $approvedScopes))));
    }

    private function fetchVanityName(string $accessToken): ?string
    {
        try {
            $response = Http::withToken($accessToken)
                ->withHeaders(['X-RestLi-Protocol-Version' => '2.0.0'])
                ->get(config('trypost.platforms.linkedin.api').'/v2/me', [
                    'projection' => '(id,vanityName,localizedFirstName,localizedLastName)',
                ]);

            if ($response->successful()) {
                return $response->json('vanityName');
            }
        } catch (Exception $e) {
            Log::warning('Failed to fetch LinkedIn vanityName', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}

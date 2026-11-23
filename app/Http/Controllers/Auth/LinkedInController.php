<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Models\SocialAccount;
use App\Support\ChannelPermissions;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
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
        if ($this->enabledPlatforms()->isEmpty()) {
            abort(Response::HTTP_FORBIDDEN, 'This platform is currently unavailable.');
        }
    }

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $this->authorize('manageAccounts', $request->user()->currentWorkspace);

        return $this->redirectToProvider($request, $this->driver);
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($this->driver)->user();
            $reconnect = $this->reconnectAccount($workspace);
            $enabledPlatforms = $this->enabledPlatforms($reconnect?->platform);
            $missingIdentity = match ($reconnect?->platform) {
                null => 'not_linkedin_admin',
                SocialPlatform::LinkedIn => 'wrong_account',
                SocialPlatform::LinkedInPage => 'page_not_found',
            };

            if ($enabledPlatforms->isEmpty()) {
                return $this->failConnection($missingIdentity);
            }

            $scopes = $this->reportedScopes($socialUser->approvedScopes);
            $publishablePlatforms = $enabledPlatforms->filter(
                fn (SocialPlatform $platform): bool => blank(ChannelPermissions::missingFrom($scopes, $platform, GatedFeature::Publish)),
            );

            if ($publishablePlatforms->isEmpty()) {
                return $this->redirectForMissingPublishPermission($scopes);
            }

            $identities = $this->publishingIdentities($socialUser, $publishablePlatforms, $scopes);

            if (blank($identities)) {
                return $this->failConnection($missingIdentity);
            }

            return $this->offerIdentities($workspace, $identities, $reconnect, $missingIdentity);
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
     * @param  Collection<int, SocialPlatform>  $platforms
     * @param  list<string>  $scopes
     * @return list<array<string, mixed>>
     */
    private function publishingIdentities(SocialiteUser $member, Collection $platforms, array $scopes): array
    {
        $credentials = [
            'access_token' => $member->token,
            'refresh_token' => $member->refreshToken,
            'token_expires_at' => $member->expiresIn ? now()->addSeconds($member->expiresIn) : null,
            'scopes' => $scopes,
        ];

        $identities = [];

        if ($platforms->contains(SocialPlatform::LinkedIn)) {
            $identities[] = $this->profileIdentity($member, $credentials);
        }

        if ($platforms->contains(SocialPlatform::LinkedInPage)) {
            foreach ($this->fetchOrganizations($member->token) as $organization) {
                $identities[] = $this->organizationIdentity($organization, $member, $credentials);
            }
        }

        return $identities;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function profileIdentity(SocialiteUser $member, array $credentials): array
    {
        $username = $this->fetchVanityName($member->token);
        $name = $member->getName();

        return PendingConnection::identity(
            SocialPlatform::LinkedIn,
            (string) $member->getId(),
            $name,
            $username,
            $member->getAvatar(),
            SocialPlatform::LinkedIn->identityType()->value,
            [...$credentials, 'username' => $username, 'display_name' => $name],
        );
    }

    /**
     * @param  array<string, mixed>  $organization
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function organizationIdentity(array $organization, SocialiteUser $admin, array $credentials): array
    {
        $organizationId = data_get($organization, 'id');
        $name = data_get($organization, 'localizedName', 'Unknown');
        $username = data_get($organization, 'vanityName');

        return PendingConnection::identity(
            SocialPlatform::LinkedInPage,
            (string) $organizationId,
            $name,
            $username,
            data_get($organization, 'logoV2.original~.elements.0.identifiers.0.identifier'),
            SocialPlatform::LinkedInPage->identityType()->value,
            [
                ...$credentials,
                'username' => $username,
                'display_name' => $name,
                'meta' => [
                    'organization_id' => $organizationId,
                    'admin_user_id' => (string) $admin->getId(),
                    'admin_name' => $admin->getName(),
                ],
            ],
        );
    }

    /**
     * @return Collection<int, SocialPlatform>
     */
    private function enabledPlatforms(?SocialPlatform $reconnectPlatform = null): Collection
    {
        $platforms = [SocialPlatform::LinkedIn, SocialPlatform::LinkedInPage];

        if ($reconnectPlatform !== null) {
            $platforms = [$reconnectPlatform];
        }

        return collect($platforms)
            ->filter(fn (SocialPlatform $platform): bool => $platform->isEnabled());
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

    /**
     * Organizations the authenticated member administers, used to offer company
     * pages as a posting identity alongside their personal profile.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchOrganizations(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get(config('trypost.platforms.linkedin.api').'/v2/organizationAcls', [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'projection' => '(elements*(organization~(id,localizedName,vanityName,logoV2(original~:playableStreams))))',
            ]);

        if ($response->failed()) {
            Log::error('LinkedIn Organizations fetch error', [
                'error' => $response->body(),
            ]);

            return [];
        }

        return $response->collect('elements')
            ->filter(fn (array $entry): bool => filled(data_get($entry, 'organization~.id')))
            ->pluck('organization~')
            ->values()
            ->all();
    }
}

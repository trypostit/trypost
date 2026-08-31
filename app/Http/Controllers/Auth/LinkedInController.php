<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\LinkedInIdentityType;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
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

    public function callback(Request $request): InertiaResponse|RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($this->driver)->user();

            session([
                'linkedin_pending' => [
                    'workspace_id' => $workspace->id,
                    'token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'expires_in' => $socialUser->expiresIn,
                    'approved_scopes' => $socialUser->approvedScopes ?? [],
                    'person' => [
                        'id' => $socialUser->getId(),
                        'name' => $socialUser->getName(),
                        'avatar' => $socialUser->getAvatar(),
                        'vanity_name' => $this->personEnabled() ? $this->fetchVanityName($socialUser->token) : null,
                    ],
                    'organizations' => $this->organizationEnabled() ? $this->fetchOrganizations($socialUser->token) : [],
                ],
            ]);

            return redirect()->route('app.social.linkedin.select-identity');
        } catch (\Exception $e) {
            Log::error('LinkedIn OAuth Error', [
                'error' => $e->getMessage(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    /**
     * Render the identity picker.
     *
     * The pending payload carries its own workspace, so the picker survives a
     * cleared connect session where connectWorkspace() would not. The profile
     * and the pages are one pool of LinkedIn identities: they go through the
     * shared filter together and are split again for the view, and only the
     * filter emptying the pool counts as the network being taken.
     */
    public function selectIdentity(Request $request): InertiaResponse
    {
        $pending = session('linkedin_pending');

        if (! $pending) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = Workspace::find($pending['workspace_id']);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        $identities = array_values(array_filter([
            $this->personEnabled() ? $pending['person'] : null,
            ...$pending['organizations'],
        ]));

        $reconnect = $this->reconnectAccount($workspace);
        $connectable = collect($this->filterConnectableIdentities($workspace, $identities, 'id', $reconnect));

        if ($identities !== [] && $connectable->isEmpty()) {
            session()->forget('linkedin_pending');

            // A profile reconnect has no page to be missing: the pool emptying
            // means this login is a different member than the card being
            // reconnected.
            return $this->noConnectableIdentities(
                $reconnect,
                $reconnect?->platform === SocialPlatform::LinkedIn ? 'wrong_account' : 'page_not_found',
            );
        }

        if ($connectable->isEmpty()) {
            session()->forget('linkedin_pending');
        }

        $personId = (string) data_get($pending, 'person.id');
        $isPerson = fn (array $identity): bool => (string) data_get($identity, 'id') === $personId;

        return Inertia::render('accounts/LinkedInSelect', [
            'person' => $connectable->first($isPerson),
            'organizations' => $connectable->reject($isPerson)->values()->all(),
            'onboardingProgress' => false,
        ]);
    }

    public function select(Request $request): InertiaResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', new Enum(LinkedInIdentityType::class)],
            'organization_id' => 'required_if:type,organization',
        ]);

        if ($validator->fails()) {
            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }

        $validated = $validator->validated();

        $pending = session('linkedin_pending');

        if (! $pending) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = Workspace::find($pending['workspace_id']);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        $type = LinkedInIdentityType::from(data_get($validated, 'type'));

        if (($type === LinkedInIdentityType::Organization && ! $this->organizationEnabled())
            || ($type === LinkedInIdentityType::Person && ! $this->personEnabled())) {
            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }

        $reconnect = $this->reconnectAccount($workspace);

        try {
            if ($type === LinkedInIdentityType::Organization) {
                $organization = $this->resolveAdministeredOrganization($pending, data_get($validated, 'organization_id'));

                if (! $organization) {
                    return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
                }

                if ($reconnect !== null && (string) data_get($organization, 'id') !== (string) $reconnect->platform_user_id) {
                    return $this->popupCallback(false, __('accounts.popup_callback.wrong_account'), $this->platform->value);
                }

                $this->connectOrganization($workspace, $pending, $organization, $reconnect);
            } else {
                if ($reconnect !== null && (string) data_get($pending, 'person.id') !== (string) $reconnect->platform_user_id) {
                    return $this->popupCallback(false, __('accounts.popup_callback.wrong_account'), $this->platform->value);
                }

                $this->connectPerson($workspace, $pending, $reconnect);
            }

            session()->forget('linkedin_pending');

            return $this->connectedCallback($reconnect);
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('LinkedIn selection error', [
                'error' => $e->getMessage(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    /**
     * The user's personal LinkedIn profile becomes a `linkedin` account.
     */
    private function connectPerson(Workspace $workspace, array $pending, ?SocialAccount $reconnect): void
    {
        $person = $pending['person'];

        SocialAccount::connectIdentity(
            $workspace,
            SocialPlatform::LinkedIn,
            (string) data_get($person, 'id'),
            [
                'username' => data_get($person, 'vanity_name'),
                'display_name' => data_get($person, 'name'),
                'avatar_url' => uploadFromUrl(data_get($person, 'avatar')),
                'access_token' => $pending['token'],
                'refresh_token' => $pending['refresh_token'],
                'token_expires_at' => $pending['expires_in'] ? now()->addSeconds($pending['expires_in']) : null,
                'scopes' => $this->normalizeScopes($pending['approved_scopes'] ?? []),
                'status' => Status::Connected,
                'error_message' => null,
                'disconnected_at' => null,
            ],
            $reconnect,
        );
    }

    /**
     * Match the chosen organization id against the admin-verified list captured at
     * callback, so a tampered POST cannot connect a company the member does not
     * administer.
     *
     * @param  array<string, mixed>  $pending
     * @return array<string, mixed>|null
     */
    private function resolveAdministeredOrganization(array $pending, mixed $organizationId): ?array
    {
        return collect(data_get($pending, 'organizations', []))
            ->first(fn ($organization) => (string) data_get($organization, 'id') === (string) $organizationId);
    }

    /**
     * A company the user administers becomes a `linkedin-page` account, with the
     * acting member recorded in meta so the page publisher can post on its behalf.
     * The organization data comes from the admin-verified session list, never the
     * request body.
     *
     * @param  array<string, mixed>  $pending
     * @param  array<string, mixed>  $organization
     */
    private function connectOrganization(Workspace $workspace, array $pending, array $organization, ?SocialAccount $reconnect): void
    {
        $organizationId = data_get($organization, 'id');

        SocialAccount::connectIdentity(
            $workspace,
            SocialPlatform::LinkedInPage,
            (string) $organizationId,
            [
                'username' => data_get($organization, 'vanity_name'),
                'display_name' => data_get($organization, 'name'),
                'avatar_url' => uploadFromUrl(data_get($organization, 'logo')),
                'access_token' => $pending['token'],
                'refresh_token' => $pending['refresh_token'],
                'token_expires_at' => $pending['expires_in'] ? now()->addSeconds($pending['expires_in']) : null,
                'scopes' => $this->normalizeScopes($pending['approved_scopes'] ?? []),
                'status' => Status::Connected,
                'error_message' => null,
                'disconnected_at' => null,
                'meta' => [
                    'organization_id' => $organizationId,
                    'admin_user_id' => data_get($pending, 'person.id'),
                    'admin_name' => data_get($pending, 'person.name'),
                ],
            ],
            $reconnect,
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
        } catch (\Exception $e) {
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
     * @return array<int, array{id: mixed, name: string, vanity_name: ?string, logo: ?string}>
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

        $organizations = [];

        foreach (data_get($response->json(), 'elements', []) as $element) {
            $org = data_get($element, 'organization~');

            if ($org) {
                $organizations[] = [
                    'id' => data_get($org, 'id'),
                    'name' => data_get($org, 'localizedName', 'Unknown'),
                    'vanity_name' => data_get($org, 'vanityName'),
                    'logo' => data_get($org, 'logoV2.original~.elements.0.identifiers.0.identifier'),
                ];
            }
        }

        return $organizations;
    }
}

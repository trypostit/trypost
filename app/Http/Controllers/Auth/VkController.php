<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\IdentityType;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Models\Workspace;
use App\Services\Social\Vk\VkApi;
use App\Support\Social\PendingConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * VK connects with a user access token (scope: wall, photos, groups, video,
 * offline) instead of OAuth — VK stopped granting the `wall` scope to new
 * OAuth apps, so users bring a token from a standalone app or an approved
 * application of their own. The token form keeps its step on the same page
 * layout (like Bluesky and Mastodon) and ends on the shared confirmation
 * page: a user token offers the profile wall plus administered communities,
 * a community access token offers the one community it belongs to.
 */
class VkController extends SocialController
{
    /**
     * VK error 27: "Group authorization failed" — the method is unavailable
     * with a community access token. Used to tell community tokens apart from
     * user tokens on the shared token field.
     */
    private const int VK_ERROR_GROUP_AUTH = 27;

    protected SocialPlatform $platform = SocialPlatform::Vk;

    public function connect(Request $request): InertiaResponse
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $this->rememberConnectSession($request, $workspace);

        return Inertia::render('accounts/VkConnect', [
            'errors' => session('errors')?->getBag('default')?->toArray() ?? [],
            'backUrl' => PendingConnection::current()?->returnUrl() ?? PendingConnection::defaultReturnUrl(),
        ]);
    }

    public function store(Request $request): RedirectResponse|InertiaResponse
    {
        $this->ensurePlatformEnabled();

        $request->validate([
            'access_token' => 'required|string|min:10',
            'community' => 'nullable|string|max:255',
        ]);

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        try {
            $user = $this->fetchTokenUser($request->access_token);

            if ($user === null) {
                // Community access token: wall.post with it is allowed
                // regardless of the app type that issued it, but VK has no API
                // to tell which community a token belongs to — the form asks
                // for the community address and the token is checked against it.
                if (! $request->filled('community')) {
                    return Inertia::render('accounts/VkConnect', [
                        'errors' => [],
                        'communityToken' => true,
                        'backUrl' => PendingConnection::current()?->returnUrl() ?? PendingConnection::defaultReturnUrl(),
                    ]);
                }

                return $this->offerCommunityIdentity($request, $workspace);
            }

            return $this->offerIdentities(
                $workspace,
                $this->tokenIdentities($request->access_token, $user),
                $this->reconnectAccount($workspace),
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('VK connection error', [
                'error' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages(['access_token' => __('accounts.vk.connection_error')]);
        }
    }

    /**
     * A community as the user typed it — a full URL, a `club123` / `public123`
     * address, a bare numeric id, or a screen name — normalized to what
     * groups.getById accepts in `group_ids`.
     */
    private function normalizeCommunity(string $input): string
    {
        $value = trim($input);
        $value = (string) preg_replace('#^https?://[^/]+/#i', '', $value);
        $value = trim($value, '/');

        if (preg_match('/^(?:club|public|event)(\d+)$/i', $value, $matches)) {
            return $matches[1];
        }

        return ltrim($value, '-');
    }

    /**
     * The user behind a user access token, or null when the token is a
     * community access token (users.get answers with error 27 for those).
     * Any other VK error surfaces as a validation error on the token field.
     *
     * @return array<string, mixed>|null
     */
    private function fetchTokenUser(string $accessToken): ?array
    {
        $response = Http::asForm()->post(VkApi::endpoint('users.get'), [
            'fields' => 'screen_name,photo_200',
        ] + VkApi::baseParams($accessToken));

        if ((int) $response->json('error.error_code') === self::VK_ERROR_GROUP_AUTH) {
            return null;
        }

        $error = $response->json('error');

        if ($response->failed() || $error !== null) {
            Log::error('VK connect API call failed', [
                'method' => 'users.get',
                'status' => $response->status(),
                'error_code' => data_get($error, 'error_code'),
            ]);

            throw ValidationException::withMessages([
                'access_token' => data_get($error, 'error_msg') ?: __('accounts.vk.connection_error'),
            ]);
        }

        $user = $response->json('response.0');

        if (! is_array($user)) {
            // users.get is callable with a community access token too — it
            // just returns an empty list without user_ids. A successful but
            // empty response therefore means a community token, not a broken
            // one (a dead token errors out above with VK's own message).
            return null;
        }

        return $user;
    }

    /**
     * Offer the community a community access token belongs to. VK has no API
     * to resolve a community from its token, so the community comes from the
     * form; groups.getCallbackConfirmationCode (callable only with the
     * community's own token, unlike groups.getOnlineStatus it does not need
     * community messages to be enabled) then proves the token belongs to it.
     */
    private function offerCommunityIdentity(Request $request, Workspace $workspace): RedirectResponse
    {
        $groups = $this->callVk($request->access_token, 'groups.getById', [
            'group_ids' => $this->normalizeCommunity((string) $request->community),
            'fields' => 'screen_name,photo_200',
        ]);

        // v5.199 returns response.groups[], older versions response[].
        $group = data_get($groups, 'groups.0') ?? data_get($groups, '0');

        if (! is_array($group)) {
            throw ValidationException::withMessages(['community' => __('accounts.vk.invalid_community')]);
        }

        $mismatch = Http::asForm()->post(VkApi::endpoint('groups.getCallbackConfirmationCode'), [
            'group_id' => (int) data_get($group, 'id'),
        ] + VkApi::baseParams($request->access_token))->json('error') !== null;

        if ($mismatch) {
            throw ValidationException::withMessages(['community' => __('accounts.vk.community_token_mismatch')]);
        }

        $ownerId = -(int) data_get($group, 'id');

        return $this->offerIdentities($workspace, [
            PendingConnection::identity(
                $this->platform,
                (string) $ownerId,
                (string) data_get($group, 'name'),
                data_get($group, 'screen_name'),
                data_get($group, 'photo_200'),
                IdentityType::Page->value,
                $this->accountAttributes($request->access_token, [
                    'owner_id' => $ownerId,
                    'is_group' => true,
                    'community_token' => true,
                ], data_get($group, 'screen_name'), (string) data_get($group, 'name')),
            ),
        ], $this->reconnectAccount($workspace));
    }

    /**
     * Walls a user token may publish to — the user's own profile plus
     * communities where the user is an administrator or editor — as
     * confirmation-page identities.
     *
     * @param  array<string, mixed>  $user
     * @return list<array<string, mixed>>
     */
    private function tokenIdentities(string $accessToken, array $user): array
    {
        $userId = (int) data_get($user, 'id');
        $userName = trim(data_get($user, 'first_name', '').' '.data_get($user, 'last_name', ''));

        $identities = [
            PendingConnection::identity(
                $this->platform,
                (string) $userId,
                $userName,
                data_get($user, 'screen_name'),
                data_get($user, 'photo_200'),
                IdentityType::Profile->value,
                $this->accountAttributes($accessToken, [
                    'owner_id' => $userId,
                    'is_group' => false,
                    'vk_user_id' => $userId,
                ], data_get($user, 'screen_name'), $userName),
            ),
        ];

        $groups = $this->callVk($accessToken, 'groups.get', [
            'filter' => 'admin,editor',
            'extended' => 1,
            'fields' => 'screen_name,photo_200',
            'count' => 200,
        ]);

        foreach (data_get($groups, 'items', []) as $group) {
            $groupId = (int) data_get($group, 'id');

            $identities[] = PendingConnection::identity(
                $this->platform,
                (string) -$groupId,
                (string) data_get($group, 'name'),
                data_get($group, 'screen_name'),
                data_get($group, 'photo_200'),
                IdentityType::Page->value,
                $this->accountAttributes($accessToken, [
                    'owner_id' => -$groupId,
                    'is_group' => true,
                    'vk_user_id' => $userId,
                ], data_get($group, 'screen_name'), (string) data_get($group, 'name')),
            );
        }

        return $identities;
    }

    /**
     * Column values the shared confirmation flow stores for a picked wall.
     * vkhost/standalone and community tokens are issued with the `offline`
     * scope and never expire; there is no refresh flow.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function accountAttributes(string $accessToken, array $meta, ?string $username, string $displayName): array
    {
        return [
            'username' => $username,
            'display_name' => $displayName,
            'access_token' => $accessToken,
            'refresh_token' => null,
            'token_expires_at' => null,
            'meta' => $meta,
        ];
    }

    /**
     * Call a VK method and return its `response` payload. VK reports failures
     * as HTTP 200 with an `error` object — surfaced here as a validation
     * error on the token field so the form shows what VK said.
     *
     * @return array<mixed>
     */
    private function callVk(string $accessToken, string $method, array $params): array
    {
        $response = Http::asForm()->post(
            VkApi::endpoint($method),
            $params + VkApi::baseParams($accessToken),
        );

        $error = $response->json('error');

        if ($response->failed() || $error !== null) {
            Log::error('VK connect API call failed', [
                'method' => $method,
                'status' => $response->status(),
                'error_code' => data_get($error, 'error_code'),
            ]);

            throw ValidationException::withMessages([
                'access_token' => data_get($error, 'error_msg') ?: __('accounts.vk.connection_error'),
            ]);
        }

        return (array) $response->json('response');
    }
}

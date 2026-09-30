<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Models\Workspace;
use App\Services\Social\Vk\VkApi;
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
 * application of their own. Two-step form: the token is validated and the
 * manageable walls (own profile + administered communities) are listed, then
 * the chosen wall is stored as the account.
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

        return Inertia::render('accounts/VkConnect', [
            'errors' => session('errors')?->getBag('default')?->toArray() ?? [],
        ]);
    }

    public function store(Request $request): InertiaResponse
    {
        $this->ensurePlatformEnabled();

        $request->validate([
            'access_token' => 'required|string|min:10',
            'owner_id' => 'nullable|integer',
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
                    ]);
                }

                return $this->storeCommunityAccount($request, $workspace);
            }

            $targets = $this->buildTargets($request->access_token, $user);

            if (! $request->filled('owner_id')) {
                return Inertia::render('accounts/VkConnect', [
                    'errors' => [],
                    'targets' => array_values($targets),
                ]);
            }

            $target = $targets[(int) $request->owner_id] ?? null;

            if ($target === null) {
                throw ValidationException::withMessages(['owner_id' => __('accounts.vk.invalid_target')]);
            }

            $avatarPath = $target['photo'] ? uploadFromUrl($target['photo']) : null;

            $workspace->socialAccounts()->updateOrCreate(
                [
                    'platform' => $this->platform->value,
                    'platform_user_id' => (string) $target['owner_id'],
                ],
                [
                    'username' => $target['screen_name'],
                    'display_name' => $target['name'],
                    'avatar_url' => $avatarPath,
                    'access_token' => $request->access_token,
                    'refresh_token' => null,
                    // vkhost/standalone tokens are issued with the `offline`
                    // scope and never expire; there is no refresh flow.
                    'token_expires_at' => null,
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                    'meta' => [
                        'owner_id' => $target['owner_id'],
                        'is_group' => $target['owner_id'] < 0,
                        'vk_user_id' => (int) data_get($user, 'id'),
                    ],
                ],
            );

            return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
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
     * Connect the community a community access token belongs to. VK has no
     * API to resolve a community from its token, so the community comes from
     * the form; groups.getCallbackConfirmationCode (callable only with the
     * community's own token, unlike groups.getOnlineStatus it does not need
     * community messages to be enabled) then proves the token belongs to it.
     */
    private function storeCommunityAccount(Request $request, Workspace $workspace): InertiaResponse
    {
        $groups = $this->callVk($request->access_token, 'groups.getById', [
            'group_ids' => $this->normalizeCommunity((string) $request->community),
            'fields' => 'screen_name,photo_200',
        ]);

        // v5.199 отдаёт response.groups[], более старые версии — response[].
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
        $photo = data_get($group, 'photo_200');

        $workspace->socialAccounts()->updateOrCreate(
            [
                'platform' => $this->platform->value,
                'platform_user_id' => (string) $ownerId,
            ],
            [
                'username' => data_get($group, 'screen_name'),
                'display_name' => (string) data_get($group, 'name'),
                'avatar_url' => $photo ? uploadFromUrl($photo) : null,
                'access_token' => $request->access_token,
                'refresh_token' => null,
                // Community access tokens never expire; there is no refresh flow.
                'token_expires_at' => null,
                'status' => Status::Connected,
                'error_message' => null,
                'disconnected_at' => null,
                'meta' => [
                    'owner_id' => $ownerId,
                    'is_group' => true,
                    'community_token' => true,
                ],
            ],
        );

        return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
    }

    /**
     * Walls the token may publish to: the user's own profile plus communities
     * where the user is an administrator or editor. Keyed by owner_id so the
     * second form step can only pick something this token really manages.
     *
     * @param  array<string, mixed>  $user
     * @return array<int, array{owner_id: int, name: string, screen_name: ?string, photo: ?string, is_group: bool}>
     */
    private function buildTargets(string $accessToken, array $user): array
    {
        $targets = [];

        $userId = (int) data_get($user, 'id');
        $targets[$userId] = [
            'owner_id' => $userId,
            'name' => trim(data_get($user, 'first_name', '').' '.data_get($user, 'last_name', '')),
            'screen_name' => data_get($user, 'screen_name'),
            'photo' => data_get($user, 'photo_200'),
            'is_group' => false,
        ];

        $groups = $this->callVk($accessToken, 'groups.get', [
            'filter' => 'admin,editor',
            'extended' => 1,
            'fields' => 'screen_name,photo_200',
            'count' => 200,
        ]);

        foreach (data_get($groups, 'items', []) as $group) {
            $groupId = (int) data_get($group, 'id');
            $targets[-$groupId] = [
                'owner_id' => -$groupId,
                'name' => (string) data_get($group, 'name'),
                'screen_name' => data_get($group, 'screen_name'),
                'photo' => data_get($group, 'photo_200'),
                'is_group' => true,
            ];
        }

        return $targets;
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

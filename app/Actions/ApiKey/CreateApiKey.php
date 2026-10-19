<?php

declare(strict_types=1);

namespace App\Actions\ApiKey;

use App\Enums\PostHog\ApiKeyEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;

class CreateApiKey
{
    /**
     * @param  array{name: string, expires_at?: string|null}  $data
     * @return array{token: AccessToken, plain_token: string}
     */
    public static function execute(User $user, Workspace $workspace, array $data): array
    {
        $created = self::issue($user, $workspace, $data);

        rescue(fn () => self::track($user, $workspace));

        return $created;
    }

    /**
     * @param  array{name: string, expires_at?: string|null}  $data
     * @return array{token: AccessToken, plain_token: string}
     */
    public static function issue(User $user, Workspace $workspace, array $data): array
    {
        $result = $user->createToken(data_get($data, 'name'));
        $token = AccessToken::query()->findOrFail($result->token->id);
        $expiresAt = data_get($data, 'expires_at');

        $token->forceFill([
            'workspace_id' => $workspace->id,
            // Empty = never expire (overrides Passport's JWT default lifetime in the DB).
            'expires_at' => filled($expiresAt)
                ? now()->parse($expiresAt)->endOfDay()
                : null,
        ])->saveQuietly();

        return [
            'token' => $token->refresh(),
            'plain_token' => $result->accessToken,
        ];
    }

    private static function track(User $user, Workspace $workspace): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        CaptureAccountEvent::dispatch(
            (string) $user->id,
            (string) $workspace->account_id,
            ApiKeyEvent::Created->value,
            ['workspace_id' => (string) $workspace->id],
        );
    }
}

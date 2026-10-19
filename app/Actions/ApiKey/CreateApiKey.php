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
        $created = IssueApiKey::execute($user, $workspace, $data);

        rescue(fn () => self::trackCreated($user, $workspace));

        return $created;
    }

    private static function trackCreated(User $user, Workspace $workspace): void
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

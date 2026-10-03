<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Dto\CanvaTokens;
use App\Enums\Media\Source;
use App\Models\MediaSourceConnection;
use App\Models\User;

class ConnectCanva
{
    /**
     * Remember the user's Canva sign-in and the Canva user and team it
     * belongs to, replacing an earlier one. There is no disconnect: revoking
     * TryPost inside Canva is the way out.
     *
     * @param  array{user_id: string, team_id: string}  $canvaUser
     */
    public static function execute(User $user, CanvaTokens $tokens, array $canvaUser): MediaSourceConnection
    {
        return MediaSourceConnection::query()->updateOrCreate(
            ['user_id' => $user->id, 'source' => Source::Canva],
            [
                'external_user_id' => data_get($canvaUser, 'user_id'),
                'external_team_id' => data_get($canvaUser, 'team_id'),
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'expires_at' => now()->addSeconds($tokens->expiresIn),
            ],
        );
    }
}

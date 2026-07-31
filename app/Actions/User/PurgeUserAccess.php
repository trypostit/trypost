<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Passport\Token;

class PurgeUserAccess
{
    /**
     * Revoke Passport tokens and delete user media rows.
     *
     * @return list<string> media paths for DeleteOrphanedMediaFiles after commit
     */
    public static function execute(User $user): array
    {
        $userMediaQuery = Media::query()
            ->where('mediable_type', Relation::getMorphAlias(User::class))
            ->where('mediable_id', $user->id);

        /** @var list<string> $mediaPaths */
        $mediaPaths = $userMediaQuery->pluck('path')->all();
        $userMediaQuery->delete();

        $user->tokens()->each(function (Token $token): void {
            $token->revoke();
            $token->refreshToken?->revoke();
        });

        return $mediaPaths;
    }
}

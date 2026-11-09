<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Models\SocialAccount;
use App\Support\Social\InstagramAudio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * One Instagram audio asset by its id, with fresh cover and preview links: the
 * links Meta hands out expire, the id does not. Kept for an hour.
 */
class GetInstagramAudio
{
    /**
     * @return array{id: string, type: string|null, title: string, artist: string|null, cover_url: string|null, duration_ms: int|null, preview_url: string|null}
     *
     * @throws ConnectionException
     * @throws RequestException
     * @throws ThrottleRequestsException
     */
    public static function execute(SocialAccount $account, string $audioId): array
    {
        return Cache::remember(
            "instagram:audio:{$account->id}:{$audioId}",
            now()->addHour(),
            function () use ($account, $audioId): array {
                InstagramAudio::spend($account);

                $response = Http::connectTimeout(3)
                    ->timeout(8)
                    ->get("{$account->platform->graphApiUrl()}/{$audioId}", [
                        'user_id' => $account->platform_user_id,
                        'access_token' => $account->access_token,
                    ])
                    ->throw();

                return InstagramAudio::present($response->json());
            },
        );
    }
}

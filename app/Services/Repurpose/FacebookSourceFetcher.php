<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Models\SocialAccount;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FacebookSourceFetcher implements SourceFetcher
{
    private const FIELDS = 'id,source,description,permalink_url,created_time';

    /**
     * @return array<int, SourceMedia>
     */
    public function fetch(SocialAccount $account, ?CarbonInterface $since): array
    {
        $graphApi = config('trypost.platforms.facebook.graph_api');

        $response = Http::withToken($account->access_token)
            ->get("{$graphApi}/{$account->platform_user_id}/videos", array_filter([
                'fields' => self::FIELDS,
                'limit' => 50,
                'since' => $since?->getTimestamp(),
            ]));

        if ($response->failed()) {
            throw new RuntimeException((string) data_get($response->json(), 'error.message', $response->body()));
        }

        return array_map(
            fn (array $row): SourceMedia => new SourceMedia(
                id: (string) data_get($row, 'id'),
                isVideo: true,
                downloadUrl: data_get($row, 'source'),
                caption: (string) data_get($row, 'description', ''),
                permalink: data_get($row, 'permalink_url'),
                createdAt: ($createdTime = data_get($row, 'created_time')) ? Carbon::parse($createdTime) : null,
            ),
            (array) $response->json('data', []),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class InstagramSourceFetcher implements SourceFetcher
{
    private const FIELDS = 'id,media_type,media_product_type,media_url,caption,permalink,timestamp';

    /**
     * @return array<int, SourceMedia>
     */
    public function fetch(SocialAccount $account, ?CarbonInterface $since): array
    {
        $response = Http::withToken($account->access_token)
            ->get("{$this->graphApi($account)}/{$account->platform_user_id}/media", array_filter([
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
                isVideo: data_get($row, 'media_type') === 'VIDEO',
                downloadUrl: data_get($row, 'media_url'),
                caption: (string) data_get($row, 'caption', ''),
                permalink: data_get($row, 'permalink'),
                createdAt: ($timestamp = data_get($row, 'timestamp')) ? Carbon::parse($timestamp) : null,
            ),
            (array) $response->json('data', []),
        );
    }

    /**
     * A direct Instagram login talks to the Instagram graph host; an account
     * connected through a Facebook Page talks to the Facebook one.
     */
    private function graphApi(SocialAccount $account): string
    {
        return $account->platform === Platform::InstagramFacebook
            ? config('trypost.platforms.instagram-facebook.graph_api')
            : config('trypost.platforms.instagram.graph_api');
    }
}

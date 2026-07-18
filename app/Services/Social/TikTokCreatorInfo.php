<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TikTokCreatorInfo
{
    use HasSocialHttpClient;

    private string $baseUrl;

    private string $accessToken;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.tiktok.api');
    }

    /**
     * @return array{
     *     creator_nickname: ?string,
     *     creator_username: ?string,
     *     creator_avatar_url: ?string,
     *     privacy_level_options: array<int, string>,
     *     comment_disabled: bool,
     *     duet_disabled: bool,
     *     stitch_disabled: bool,
     *     max_video_post_duration_sec: ?int,
     * }
     */
    public function fetch(SocialAccount $account): array
    {
        return Cache::remember(
            "tiktok:creator_info:{$account->id}",
            now()->addMinutes(5),
            fn () => $this->fetchFresh($account),
        );
    }

    /**
     * @return array{
     *     creator_nickname: ?string,
     *     creator_username: ?string,
     *     creator_avatar_url: ?string,
     *     privacy_level_options: array<int, string>,
     *     comment_disabled: bool,
     *     duet_disabled: bool,
     *     stitch_disabled: bool,
     *     max_video_post_duration_sec: ?int,
     * }
     */
    private function fetchFresh(SocialAccount $account): array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $this->accessToken = $account->access_token;

        $response = $this->getHttpClient()
            ->post("{$this->baseUrl}/post/publish/creator_info/query/", []);

        if ($response->failed()) {
            Log::warning('TikTok creator_info query failed', [
                'social_account_id' => $account->id,
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return $this->emptyPayload();
        }

        $data = data_get($response->json(), 'data', []);

        return [
            'creator_nickname' => data_get($data, 'creator_nickname'),
            'creator_username' => data_get($data, 'creator_username'),
            'creator_avatar_url' => data_get($data, 'creator_avatar_url'),
            'privacy_level_options' => data_get($data, 'privacy_level_options', []),
            'comment_disabled' => (bool) data_get($data, 'comment_disabled', false),
            'duet_disabled' => (bool) data_get($data, 'duet_disabled', false),
            'stitch_disabled' => (bool) data_get($data, 'stitch_disabled', false),
            'max_video_post_duration_sec' => data_get($data, 'max_video_post_duration_sec'),
        ];
    }

    /**
     * @return array{
     *     creator_nickname: null,
     *     creator_username: null,
     *     creator_avatar_url: null,
     *     privacy_level_options: array<int, string>,
     *     comment_disabled: bool,
     *     duet_disabled: bool,
     *     stitch_disabled: bool,
     *     max_video_post_duration_sec: null,
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'creator_nickname' => null,
            'creator_username' => null,
            'creator_avatar_url' => null,
            'privacy_level_options' => [],
            'comment_disabled' => false,
            'duet_disabled' => false,
            'stitch_disabled' => false,
            'max_video_post_duration_sec' => null,
        ];
    }

    private function getHttpClient(): PendingRequest
    {
        return $this->socialHttp()->asJson()->withToken($this->accessToken);
    }
}

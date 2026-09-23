<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Support\Facades\Log;

/**
 * Posts the per-platform `meta.first_comment` under a just-published post.
 * The classic "link in the first comment" move: YouTube descriptions bury
 * links below the fold and Instagram captions don't render them at all.
 *
 * Runs after the post is already live, so it must never fail the publish —
 * every failure is logged and swallowed.
 */
class FirstCommentPoster
{
    use HasSocialHttpClient;

    public function post(PostPlatform $postPlatform, string $externalId): void
    {
        $comment = trim((string) data_get($postPlatform->meta, 'first_comment'));

        if ($comment === '') {
            return;
        }

        try {
            match ($postPlatform->platform) {
                Platform::YouTube => $this->postYouTubeComment($postPlatform, $externalId, $comment),
                Platform::Instagram, Platform::InstagramFacebook => $this->postInstagramComment($postPlatform, $externalId, $comment),
                default => Log::warning('First comment is not supported for this platform', [
                    'platform' => $postPlatform->platform->value,
                    'post_platform_id' => $postPlatform->id,
                ]),
            };
        } catch (\Throwable $e) {
            Log::warning('First comment failed (post itself is published)', [
                'platform' => $postPlatform->platform->value,
                'post_platform_id' => $postPlatform->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * commentThreads.insert needs the youtube.force-ssl scope — requested at
     * connect time since the platform was added, so every account has it.
     */
    private function postYouTubeComment(PostPlatform $postPlatform, string $videoId, string $comment): void
    {
        $account = $postPlatform->socialAccount;
        $api = rtrim((string) config('trypost.platforms.youtube.data_api'), '/');

        $response = $this->socialHttp()
            ->withToken($account->access_token)
            ->post("{$api}/commentThreads?part=snippet", [
                'snippet' => [
                    'videoId' => $videoId,
                    'topLevelComment' => [
                        'snippet' => ['textOriginal' => $comment],
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::warning('YouTube first comment failed', [
                'post_platform_id' => $postPlatform->id,
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
        }
    }

    /**
     * Needs instagram_business_manage_comments (direct) / instagram_manage_comments
     * (via Facebook Page) — accounts connected before the scope was requested
     * get a 4xx here, which is logged and ignored; reconnecting fixes it.
     */
    private function postInstagramComment(PostPlatform $postPlatform, string $mediaId, string $comment): void
    {
        $account = $postPlatform->socialAccount;
        $baseUrl = $account->platform->instagramGraphBaseUrl();

        $response = $this->socialHttp()->post("{$baseUrl}/{$mediaId}/comments", [
            'message' => $comment,
            'access_token' => $account->access_token,
        ]);

        if ($response->failed()) {
            Log::warning('Instagram first comment failed', [
                'post_platform_id' => $postPlatform->id,
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
        }
    }
}

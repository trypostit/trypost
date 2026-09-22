<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\VkPublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Services\Social\Vk\VkApi;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VkPublisher
{
    use HasSocialHttpClient;

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $content = $postPlatform->post->content
            ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform)
            : null;

        $account = $postPlatform->socialAccount;
        $ownerId = $this->ownerId($account);

        $attachments = [];

        foreach ($postPlatform->post->mediaItems->take($postPlatform->platform->maxImages()) as $media) {
            $attachment = match (true) {
                $media->isImage() => $this->uploadPhoto($account, $ownerId, $media->url),
                $media->isVideo() => $this->uploadVideo($account, $ownerId, $media->url, $content),
                default => null,
            };

            if ($attachment !== null) {
                $attachments[] = $attachment;
            }
        }

        $params = [
            'owner_id' => $ownerId,
            'message' => $content ?? '',
        ];

        if ($ownerId < 0) {
            // Publish as the community itself, not as the connecting user.
            $params['from_group'] = 1;
        }

        if ($attachments !== []) {
            $params['attachments'] = implode(',', $attachments);
        }

        $response = $this->socialHttp()->asForm()->post(
            VkApi::endpoint('wall.post'),
            $params + VkApi::baseParams($account->access_token),
        );

        $postId = $response->json('response.post_id');

        if ($response->failed() || $postId === null) {
            Log::error('VK post creation failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        return [
            'id' => (string) $postId,
            'url' => "https://vk.com/wall{$ownerId}_{$postId}",
        ];
    }

    /**
     * The wall to publish to: negative id for a community, positive for the
     * user's own profile wall. Stored at connect time; platform_user_id keeps
     * the same value as a fallback for rows created before meta existed.
     */
    private function ownerId(SocialAccount $account): int
    {
        return (int) (data_get($account->meta, 'owner_id') ?? $account->platform_user_id);
    }

    /**
     * VK photo upload is a three-step flow: getWallUploadServer → POST the
     * file to the returned upload_url → saveWallPhoto. Returns an attachment
     * reference like `photo123_456`, or null to skip the item (a failed
     * single photo should not sink the whole post; wall.post itself decides
     * whether an empty post is acceptable).
     */
    private function uploadPhoto(SocialAccount $account, int $ownerId, string $url): ?string
    {
        $groupParams = $ownerId < 0 ? ['group_id' => abs($ownerId)] : [];
        $tempFile = tempnam(sys_get_temp_dir(), 'vk_media_');

        try {
            $download = Http::withOptions(['sink' => $tempFile])->timeout(600)->get($url);

            if ($download->failed() || filesize($tempFile) === 0) {
                Log::error('VK failed to download media', ['url' => $url]);

                return null;
            }

            $detectedMime = mime_content_type($tempFile) ?: '';
            if (MediaType::classify($detectedMime) === MediaType::Image && ! MediaType::isGif($detectedMime)) {
                $optimizer = app(MediaOptimizer::class);
                $optimizedPath = $optimizer->optimizeImage($tempFile, Platform::Vk);
                @unlink($tempFile);
                $tempFile = $optimizedPath;
            }

            $server = $this->call($account, 'photos.getWallUploadServer', $groupParams);
            $uploadUrl = data_get($server, 'response.upload_server') ?? data_get($server, 'response.upload_url');

            if (! is_string($uploadUrl) || $uploadUrl === '') {
                Log::error('VK getWallUploadServer returned no upload_url', ['body' => $this->redactResponseBody(json_encode($server) ?: '')]);

                return null;
            }

            $stream = fopen($tempFile, 'r');
            $upload = $this->socialHttp()
                ->attach('photo', $stream, 'photo.jpg')
                ->post($uploadUrl);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($upload->failed() || data_get($upload->json(), 'photo') === null) {
                Log::error('VK photo upload failed', [
                    'status' => $upload->status(),
                    'body' => $this->redactResponseBody($upload->body()),
                ]);

                return null;
            }

            $saved = $this->call($account, 'photos.saveWallPhoto', $groupParams + [
                'photo' => (string) data_get($upload->json(), 'photo'),
                'server' => (string) data_get($upload->json(), 'server'),
                'hash' => (string) data_get($upload->json(), 'hash'),
            ]);

            $photo = data_get($saved, 'response.0');

            if (! is_array($photo)) {
                Log::error('VK saveWallPhoto failed', ['body' => $this->redactResponseBody(json_encode($saved) ?: '')]);

                return null;
            }

            return 'photo'.data_get($photo, 'owner_id').'_'.data_get($photo, 'id');
        } catch (\Exception $e) {
            Log::error('VK photo upload error', ['error' => $e->getMessage(), 'url' => $url]);

            return null;
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * VK video upload: video.save returns an upload_url the raw file is
     * POSTed to; the attachment id comes from video.save itself. `wallpost=0`
     * keeps VK from auto-publishing — the video is attached to our wall.post.
     * Requires the token to carry the `video` scope; a missing scope surfaces
     * as an API error and the item is skipped.
     */
    private function uploadVideo(SocialAccount $account, int $ownerId, string $url, ?string $content): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'vk_video_');

        try {
            $download = Http::withOptions(['sink' => $tempFile])->timeout(600)->get($url);

            if ($download->failed() || filesize($tempFile) === 0) {
                Log::error('VK failed to download video', ['url' => $url]);

                return null;
            }

            $name = $content !== null && $content !== ''
                ? mb_substr($content, 0, 100)
                : 'Video';

            $save = $this->call($account, 'video.save', array_filter([
                'group_id' => $ownerId < 0 ? abs($ownerId) : null,
                'name' => $name,
                'wallpost' => 0,
            ], fn ($value) => $value !== null));

            $uploadUrl = data_get($save, 'response.upload_url');

            if (! is_string($uploadUrl) || $uploadUrl === '') {
                Log::error('VK video.save returned no upload_url', ['body' => $this->redactResponseBody(json_encode($save) ?: '')]);

                return null;
            }

            $stream = fopen($tempFile, 'r');
            $upload = $this->socialHttp()
                ->timeout(600)
                ->attach('video_file', $stream, 'video.mp4')
                ->post($uploadUrl);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($upload->failed()) {
                Log::error('VK video upload failed', [
                    'status' => $upload->status(),
                    'body' => $this->redactResponseBody($upload->body()),
                ]);

                return null;
            }

            $videoOwner = data_get($save, 'response.owner_id');
            $videoId = data_get($upload->json(), 'video_id') ?? data_get($save, 'response.video_id');

            if ($videoOwner === null || $videoId === null) {
                return null;
            }

            return "video{$videoOwner}_{$videoId}";
        } catch (\Exception $e) {
            Log::error('VK video upload error', ['error' => $e->getMessage(), 'url' => $url]);

            return null;
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Call a VK API method and return the decoded body. VK signals errors as
     * HTTP 200 + an `error` object, so both transport failures and API errors
     * funnel through the same exception path.
     *
     * @return array<string, mixed>
     */
    private function call(SocialAccount $account, string $method, array $params): array
    {
        $response = $this->socialHttp()->asForm()->post(
            VkApi::endpoint($method),
            $params + VkApi::baseParams($account->access_token),
        );

        if ($response->failed() || $response->json('error') !== null) {
            Log::error("VK {$method} failed", [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        return $response->json() ?? [];
    }

    private function handleApiError(Response $response): never
    {
        throw VkPublishException::fromApiResponse($response);
    }
}

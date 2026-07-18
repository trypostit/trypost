<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\MastodonPublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MastodonPublisher
{
    use HasSocialHttpClient;

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $content = $postPlatform->post->content ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform) : null;

        $account = $postPlatform->socialAccount;
        $instance = $account->meta['instance'] ?? config('trypost.platforms.mastodon.default_instance');

        $medias = $postPlatform->post->mediaItems;
        $mediaIds = [];

        // Upload media first (max 4)
        foreach ($medias->take(4) as $media) {
            $mediaId = $this->uploadMedia($account, $instance, $media->url, $media->original_filename, $media->isImage() ? $media->altTextFor(Platform::Mastodon) : null);
            if ($mediaId) {
                $mediaIds[] = $mediaId;
            }
        }

        // Create status
        $payload = [
            'status' => $content ?? '',
            'visibility' => 'public',
        ];

        if (! empty($mediaIds)) {
            $payload['media_ids'] = $mediaIds;
        }

        $response = $this->socialHttp()->withToken($account->access_token)
            ->post("{$instance}/api/v1/statuses", $payload);

        if ($response->failed()) {
            Log::error('Mastodon post failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);
            $this->handleApiError($response);
        }

        $data = $response->json();

        return [
            'id' => data_get($data, 'id'),
            'url' => data_get($data, 'url'),
        ];
    }

    private function uploadMedia(SocialAccount $account, string $instance, string $url, ?string $filename, ?string $altText): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'masto_media_');

        try {
            $downloadResponse = Http::withOptions(['sink' => $tempFile])->timeout(600)->get($url);

            if ($downloadResponse->failed()) {
                throw new \Exception('Failed to download media: HTTP '.$downloadResponse->status());
            }

            if (filesize($tempFile) === 0) {
                Log::error('Mastodon failed to download media', ['url' => $url]);

                return null;
            }

            // Optimize images (skip GIFs)
            $detectedMime = mime_content_type($tempFile) ?: '';
            if (MediaType::classify($detectedMime) === MediaType::Image && ! MediaType::isGif($detectedMime)) {
                $optimizer = app(MediaOptimizer::class);
                $optimizedPath = $optimizer->optimizeImage($tempFile, Platform::Mastodon);
                @unlink($tempFile);
                $tempFile = $optimizedPath;
            }

            $name = $filename ?? basename(parse_url($url, PHP_URL_PATH));
            if (empty($name)) {
                $name = 'media';
            }

            $stream = fopen($tempFile, 'r');

            $request = $this->socialHttp()->withToken($account->access_token)
                ->attach('file', $stream, $name);

            if ($altText !== null) {
                $request = $request->attach('description', $altText);
            }

            $response = $request->post("{$instance}/api/v1/media");

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($response->failed()) {
                Log::error('Mastodon media upload failed', [
                    'status' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);

                return null;
            }

            $data = $response->json();

            return data_get($data, 'id');
        } catch (\Exception $e) {
            Log::error('Mastodon media upload error', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return null;
        } finally {
            @unlink($tempFile);
        }
    }

    private function handleApiError(Response $response): never
    {
        throw MastodonPublishException::fromApiResponse($response);
    }
}

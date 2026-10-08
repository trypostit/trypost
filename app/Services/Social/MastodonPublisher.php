<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Dto\MediaItem;
use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\MastodonPublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Services\Social\Concerns\PublishesThreads;
use App\Support\Social\ThreadProgress;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MastodonPublisher
{
    use HasSocialHttpClient;
    use PublishesThreads;

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $content = $postPlatform->post->content ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform) : null;

        $account = $postPlatform->socialAccount;
        $instance = $account->meta['instance'] ?? config('trypost.platforms.mastodon.default_instance');

        $rootHash = ThreadProgress::hash((string) $content, $postPlatform->post->mediaItems->map(fn (MediaItem $item): string => $item->id)->all());

        return $this->publishThread(
            $postPlatform,
            $rootHash,
            fn (): array => $this->publishRoot($postPlatform, $account, $instance, $content, "{$postPlatform->id}:{$rootHash}"),
            fn (string $text, Collection $media, array $parent): array => $this->createStatus(
                $account,
                $instance,
                [
                    ...$this->replyPayload($postPlatform, $text, (string) $parent['id']),
                    ...$this->mediaPayload($account, $instance, $media),
                ],
                "{$postPlatform->id}:{$parent['id']}:".ThreadProgress::hash($text, $media->map(fn (MediaItem $item): string => $item->id)->all()),
            ),
        );
    }

    /**
     * @return array{id: string, url: ?string}
     */
    private function publishRoot(PostPlatform $postPlatform, SocialAccount $account, string $instance, ?string $content, string $idempotencyKey): array
    {
        $payload = [
            'status' => $content ?? '',
            'visibility' => 'public',
            ...$this->mediaPayload($account, $instance, $postPlatform->post->mediaItems),
        ];

        return $this->createStatus($account, $instance, [...$payload, ...$this->contentWarning($postPlatform)], $idempotencyKey);
    }

    /**
     * Uploads up to four media and returns the status's `media_ids`, empty
     * when the post has none.
     *
     * @param  Collection<int, MediaItem>  $media
     * @return array{media_ids?: list<string>}
     */
    private function mediaPayload(SocialAccount $account, string $instance, Collection $media): array
    {
        $mediaIds = [];

        foreach ($media->take(4) as $item) {
            $mediaIds[] = $this->uploadMedia($account, $instance, $item->url, $item->original_filename, $item->isImage() ? $item->altTextFor(Platform::Mastodon) : null);
        }

        return $mediaIds === [] ? [] : ['media_ids' => $mediaIds];
    }

    /**
     * A reply repeats the root's content warning, so the thread stays behind it.
     *
     * @return array<string, mixed>
     */
    private function replyPayload(PostPlatform $postPlatform, string $text, string $inReplyToId): array
    {
        return [
            'status' => $text,
            'visibility' => 'public',
            'in_reply_to_id' => $inReplyToId,
            ...$this->contentWarning($postPlatform),
        ];
    }

    /**
     * @return array{spoiler_text?: string}
     */
    private function contentWarning(PostPlatform $postPlatform): array
    {
        $spoilerText = Str::trim((string) data_get($postPlatform->meta, 'spoiler_text'));

        return $spoilerText === '' ? [] : ['spoiler_text' => $spoilerText];
    }

    /**
     * The idempotency key makes Mastodon answer a resent status with the one it
     * already created (kept for an hour), covering a crash before the checkpoint.
     *
     * @param  array<string, mixed>  $payload
     * @return array{id: string, url: ?string}
     */
    private function createStatus(SocialAccount $account, string $instance, array $payload, string $idempotencyKey): array
    {
        $response = $this->socialHttp()->withToken($account->access_token)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
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
            'id' => (string) data_get($data, 'id'),
            'url' => data_get($data, 'url'),
        ];
    }

    /**
     * Uploads one media item and returns its id. A failure stops the post
     * instead of publishing it without the media: a 5xx or a dropped
     * connection is retried (the status does not exist yet), anything else
     * Mastodon refused fails with its mapped error.
     */
    private function uploadMedia(SocialAccount $account, string $instance, string $url, ?string $filename, ?string $altText): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'masto_media_');

        try {
            $downloadResponse = Http::withOptions(['sink' => $tempFile])->timeout(600)->get($url);

            if ($downloadResponse->failed() || filesize($tempFile) === 0) {
                Log::error('Mastodon failed to download media', ['url' => $url, 'status' => $downloadResponse->status()]);

                throw new MastodonPublishException(
                    userMessage: __('posts.errors.media_unavailable', ['platform' => Platform::Mastodon->label()]),
                    category: ErrorCategory::ServerError,
                );
            }

            // Optimize images (skip GIFs)
            $detectedMime = File::mimeType($tempFile) ?: '';
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

            try {
                $response = $request->post("{$instance}/api/v1/media");
            } catch (ConnectionException $e) {
                throw new PlatformUnavailableException("Mastodon media upload failed to connect: {$e->getMessage()}");
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($response->failed()) {
                Log::error('Mastodon media upload failed', [
                    'status' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);

                if ($response->serverError()) {
                    throw new PlatformUnavailableException('Mastodon media upload returned a server error', $response->status());
                }

                $this->handleApiError($response);
            }

            $mediaId = data_get($response->json(), 'id');

            if (! is_scalar($mediaId) || (string) $mediaId === '') {
                throw new MastodonPublishException(
                    userMessage: __('posts.errors.unrecognized_error', ['platform' => Platform::Mastodon->label()]),
                    category: ErrorCategory::Unknown,
                    rawResponse: $this->redactResponseBody($response->body()),
                );
            }

            return (string) $mediaId;
        } finally {
            @unlink($tempFile);
        }
    }

    private function handleApiError(Response $response): never
    {
        throw MastodonPublishException::fromApiResponse($response);
    }
}

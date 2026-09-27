<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Dto\MediaItem;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\YouTubePublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Support\YouTubeDescription;
use Google\Client as GoogleClient;
use Google\Service\Exception;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use Google_Http_MediaFileUpload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubePublisher
{
    use HasSocialHttpClient;

    private const CHUNK_SIZE = 10 * 1024 * 1024; // 10MB chunks

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $content = $postPlatform->post->content
            ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform)
            : null;
        $description = $this->resolveDescription($postPlatform, $content);

        $account = $postPlatform->socialAccount;

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $media = $postPlatform->post->mediaItems;

        if ($media->isEmpty()) {
            throw new YouTubePublishException(
                userMessage: 'YouTube Shorts requires a video to publish.',
                category: ErrorCategory::MediaFormat,
            );
        }

        $firstMedia = $media->first();

        if (! $firstMedia->isVideo()) {
            throw new YouTubePublishException(
                userMessage: 'YouTube Shorts only supports video content.',
                category: ErrorCategory::MediaFormat,
            );
        }

        return $this->publishShort($firstMedia, $account, $content, $description);
    }

    private function createGoogleClient(SocialAccount $account): GoogleClient
    {
        $client = app(GoogleClient::class);
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));

        $remainingSeconds = $account->token_expires_at
            ? max(0, (int) now()->diffInSeconds($account->token_expires_at, false))
            : 3600;

        $tokenData = [
            'access_token' => $account->access_token,
            'created' => time(),
            'expires_in' => $remainingSeconds,
        ];

        if ($account->refresh_token) {
            $tokenData['refresh_token'] = $account->refresh_token;
        }

        $client->setAccessToken($tokenData);

        return $client;
    }

    private function publishShort(MediaItem $media, SocialAccount $account, ?string $content, string $description): array
    {
        if (empty($content)) {
            throw new YouTubePublishException(
                userMessage: 'YouTube Shorts require a title. Please add text to your post.',
                category: ErrorCategory::ContentPolicy,
            );
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'yt_upload_');

        if ($tempFile === false) {
            throw new YouTubePublishException(
                userMessage: 'Failed to create temp file for YouTube upload',
                category: ErrorCategory::ServerError,
            );
        }

        try {
            $this->downloadVideo($media, $tempFile);
            $video = $this->uploadVideo($media, $account, $tempFile, $this->buildVideo($content, $description));
            $videoId = $video->getId();

            return [
                'id' => $videoId,
                'url' => "https://www.youtube.com/shorts/{$videoId}",
            ];
        } catch (Exception $e) {
            throw YouTubePublishException::fromGoogleException($e);
        } catch (\Throwable $e) {
            Log::error('YouTube upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        } finally {
            @unlink($tempFile);
        }
    }

    private function downloadVideo(MediaItem $media, string $path): void
    {
        $response = Http::withOptions(['sink' => $path])
            ->timeout(600)
            ->get($media->url);

        if ($response->failed()) {
            throw new YouTubePublishException(
                userMessage: 'Failed to download video for YouTube upload: HTTP '.$response->status(),
                category: ErrorCategory::ServerError,
            );
        }
    }

    private function uploadVideo(MediaItem $media, SocialAccount $account, string $path, Video $video): Video
    {
        $fileSize = filesize($path);

        if ($fileSize === false || $fileSize < 1024) {
            throw new YouTubePublishException(
                userMessage: 'Downloaded video is too small or empty ('.$fileSize.' bytes), aborting upload',
                category: ErrorCategory::MediaFormat,
            );
        }

        $client = $this->createGoogleClient($account);
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new YouTubePublishException(
                userMessage: 'Failed to open temp file for YouTube upload',
                category: ErrorCategory::ServerError,
            );
        }

        try {
            $client->setDefer(true);
            $youtube = new YouTube($client);
            $mediaUpload = new Google_Http_MediaFileUpload(
                $client,
                $youtube->videos->insert('snippet,status', $video),
                $media->mime_type ?: 'video/mp4',
                null,
                true,
                self::CHUNK_SIZE
            );
            $mediaUpload->setFileSize($fileSize);

            $uploadStatus = false;

            while (! $uploadStatus && ! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                $uploadStatus = $mediaUpload->nextChunk($chunk);
            }

            if (! $uploadStatus instanceof Video) {
                throw new YouTubePublishException(
                    userMessage: 'YouTube upload failed: no video object returned',
                    category: ErrorCategory::ServerError,
                );
            }

            return $uploadStatus;
        } finally {
            fclose($handle);
            $client->setDefer(false);
        }
    }

    private function resolveDescription(PostPlatform $postPlatform, ?string $content): string
    {
        $description = YouTubeDescription::resolve($postPlatform->meta, $content);
        $violation = YouTubeDescription::violation(data_get($postPlatform->meta, 'description'))
            ?? YouTubeDescription::violation($description);

        if ($violation !== null) {
            throw new YouTubePublishException(
                userMessage: __($violation),
                category: ErrorCategory::ContentPolicy,
            );
        }

        return $description;
    }

    private function buildVideo(string $content, string $description): Video
    {
        $snippet = new VideoSnippet;
        $snippet->setTitle($this->buildTitle($content));
        $snippet->setDescription($description);
        $snippet->setCategoryId('22');

        $status = new VideoStatus;
        $status->setPrivacyStatus('public');
        $status->setSelfDeclaredMadeForKids(false);

        $video = new Video;
        $video->setSnippet($snippet);
        $video->setStatus($status);

        return $video;
    }

    private function buildTitle(string $content): string
    {
        $maxLength = 100;
        $shortsTag = ' #Shorts';
        $availableLength = $maxLength - mb_strlen($shortsTag);

        $firstLine = explode("\n", $content)[0];
        $title = explode('.', $firstLine)[0];

        if (mb_strlen($title) > $availableLength) {
            $title = mb_substr($title, 0, $availableLength - 3).'...';
        }

        return $title.$shortsTag;
    }
}
